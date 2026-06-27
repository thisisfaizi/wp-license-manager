<?php
/**
 * REST controller for webhook endpoint management.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\Webhook;
use WPLM\Repositories\WebhookRepository;
use WPLM\Support\ResponseFactory;

/**
 * Handles /webhooks CRUD.  Webhooks are fired server-side by WebhookService;
 * this controller lets API consumers register and manage their endpoints.
 */
class WebhooksController extends BaseController {

	const NAMESPACE = 'wplm/v1';

	/** Allowed event names clients may subscribe to. */
	const ALLOWED_EVENTS = array(
		'license.created',
		'license.activated',
		'license.deactivated',
		'license.revoked',
		'license.suspended',
		'license.reinstated',
		'license.terminated',
		'license.expired',
		'license.renewed',
		'machine.revoked',
		'subscription.created',
		'subscription.renewed',
		'subscription.payment_failed',
		'subscription.cancelled',
		'subscription.paused',
		'subscription.resumed',
		'subscription.expired',
	);

	/**
	 * Register all webhook routes.
	 *
	 * @return void
	 */
	public function register(): void {
		// Collection.
		register_rest_route(
			self::NAMESPACE,
			'/webhooks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'require_read' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'require_write' ),
					'args'                => array(
						'name'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'target_url' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => fn( $v ) => filter_var( $v, FILTER_VALIDATE_URL ) !== false,
						),
						'events'     => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		// Single item.
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'require_read' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'require_write' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'require_write' ),
				),
			)
		);

		// Ping test.
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<id>\d+)/ping',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ping_item' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);
	}

	/**
	 * GET /webhooks
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $req ) {
		/** @var WebhookRepository $repo */
		$repo     = $this->container->make( WebhookRepository::class );
		$webhooks = $repo->get_all();

		return ResponseFactory::success(
			array( 'webhooks' => array_map( fn( $w ) => $this->prepare( $w ), $webhooks ) )
		);
	}

	/**
	 * GET /webhooks/{id}
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $req ) {
		$webhook = $this->resolve( absint( $req->get_param( 'id' ) ) );
		if ( is_wp_error( $webhook ) ) {
			return $webhook;
		}
		return ResponseFactory::success( $this->prepare( $webhook ) );
	}

	/**
	 * POST /webhooks
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $req ) {
		$name       = sanitize_text_field( wp_unslash( $req->get_param( 'name' ) ) );
		$target_url = esc_url_raw( wp_unslash( $req->get_param( 'target_url' ) ) );
		$events     = array_intersect(
			array_map( 'sanitize_text_field', (array) $req->get_param( 'events' ) ),
			self::ALLOWED_EVENTS
		);

		if ( empty( $events ) ) {
			return ResponseFactory::error(
				'wplm_invalid_events',
				__( 'No valid events provided. See /api-keys endpoint for allowed event names.', 'wp-license-manager' ),
				400
			);
		}

		$data = array(
			'name'       => $name,
			'target_url' => $target_url,
			'events'     => wp_json_encode( array_values( $events ) ),
			'secret'     => bin2hex( random_bytes( 32 ) ),
			'status'     => 1,
			'created_at' => current_time( 'mysql', true ),
		);

		/** @var WebhookRepository $repo */
		$repo = $this->container->make( WebhookRepository::class );
		$id   = $repo->insert( $data );

		if ( ! $id ) {
			return ResponseFactory::error( 'wplm_create_failed', __( 'Failed to create webhook.', 'wp-license-manager' ), 500 );
		}

		$webhook = $repo->find( $id );
		return ResponseFactory::success( $this->prepare( $webhook, true ), array(), 201 );
	}

	/**
	 * PUT /webhooks/{id}
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $req ) {
		$webhook = $this->resolve( absint( $req->get_param( 'id' ) ) );
		if ( is_wp_error( $webhook ) ) {
			return $webhook;
		}

		$body = $req->get_json_params() ?: array();
		$data = array();

		if ( isset( $body['name'] ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( $body['name'] ) );
		}
		if ( isset( $body['target_url'] ) ) {
			$data['target_url'] = esc_url_raw( wp_unslash( $body['target_url'] ) );
		}
		if ( isset( $body['events'] ) ) {
			$events         = array_intersect(
				array_map( 'sanitize_text_field', (array) $body['events'] ),
				self::ALLOWED_EVENTS
			);
			$data['events'] = wp_json_encode( array_values( $events ) );
		}
		if ( isset( $body['status'] ) ) {
			$data['status'] = absint( $body['status'] ) ? 1 : 0;
		}

		if ( empty( $data ) ) {
			return ResponseFactory::error( 'wplm_no_fields', __( 'No updatable fields provided.', 'wp-license-manager' ), 400 );
		}

		/** @var WebhookRepository $repo */
		$repo = $this->container->make( WebhookRepository::class );
		$repo->update( $webhook->id, $data );

		return ResponseFactory::success( $this->prepare( $repo->find( $webhook->id ) ) );
	}

	/**
	 * DELETE /webhooks/{id}
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $req ) {
		$id = absint( $req->get_param( 'id' ) );

		/** @var WebhookRepository $repo */
		$repo    = $this->container->make( WebhookRepository::class );
		$deleted = $repo->delete( $id );

		if ( ! $deleted ) {
			return ResponseFactory::error( 'wplm_not_found', __( 'Webhook not found.', 'wp-license-manager' ), 404 );
		}

		return ResponseFactory::success(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * POST /webhooks/{id}/ping — sends a test payload to verify the endpoint.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ping_item( \WP_REST_Request $req ) {
		$webhook = $this->resolve( absint( $req->get_param( 'id' ) ) );
		if ( is_wp_error( $webhook ) ) {
			return $webhook;
		}

		$payload = wp_json_encode(
			array(
				'event'      => 'ping',
				'webhook_id' => $webhook->id,
				'timestamp'  => time(),
			)
		);

		$sig = 'sha256=' . hash_hmac( 'sha256', $payload, $webhook->secret );

		$response = wp_remote_post(
			$webhook->target_url,
			array(
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-WPLM-Signature' => $sig,
					'X-WPLM-Event'     => 'ping',
				),
				'body'    => $payload,
				'timeout' => 10,
			)
		);

		$code    = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$success = $code >= 200 && $code < 300;

		return ResponseFactory::success(
			array(
				'success'       => $success,
				'response_code' => $code,
			)
		);
	}

	/**
	 * Resolve a webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 * @return Webhook|\WP_Error
	 */
	private function resolve( int $id ) {
		/** @var WebhookRepository $repo */
		$repo    = $this->container->make( WebhookRepository::class );
		$webhook = $repo->find( $id );

		if ( null === $webhook ) {
			return ResponseFactory::error( 'wplm_not_found', __( 'Webhook not found.', 'wp-license-manager' ), 404 );
		}

		return $webhook;
	}

	/**
	 * Prepare a webhook for the API response.
	 *
	 * @param Webhook $webhook      The model.
	 * @param bool    $include_secret Whether to include the signing secret (only on create).
	 * @return array
	 */
	private function prepare( Webhook $webhook, bool $include_secret = false ): array {
		$data = array(
			'id'         => $webhook->id,
			'name'       => $webhook->name,
			'target_url' => $webhook->target_url,
			'events'     => is_string( $webhook->events ) ? json_decode( $webhook->events, true ) : $webhook->events,
			'status'     => $webhook->status,
			'created_at' => $webhook->created_at,
		);

		if ( $include_secret ) {
			$data['secret'] = $webhook->secret;
		}

		return $data;
	}
}
