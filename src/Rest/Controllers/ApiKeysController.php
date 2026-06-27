<?php
/**
 * REST controller for API key management.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\ApiKey;
use WPLM\Repositories\ApiKeyRepository;
use WPLM\Support\ResponseFactory;

/**
 * Handles CRUD for /api-keys. Only read_write–scoped keys can create or delete
 * other API keys; read-scoped keys can only list their own.
 */
class ApiKeysController extends BaseController {

	const NAMESPACE = 'wplm/v1';

	/**
	 * Register all API-key routes.
	 *
	 * @return void
	 */
	public function register(): void {
		// Collection.
		register_rest_route(
			self::NAMESPACE,
			'/api-keys',
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
						'description' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'permissions' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'read', 'write', 'read_write' ),
						),
						'user_id'     => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
			)
		);

		// Single item.
		register_rest_route(
			self::NAMESPACE,
			'/api-keys/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'require_read' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'require_write' ),
				),
			)
		);
	}

	/**
	 * GET /api-keys
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $req ) {
		/** @var ApiKeyRepository $repo */
		$repo = $this->container->make( ApiKeyRepository::class );
		$keys = $repo->get_all();

		return ResponseFactory::success(
			array( 'api_keys' => array_map( fn( $k ) => $this->prepare_key( $k ), $keys ) )
		);
	}

	/**
	 * GET /api-keys/{id}
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $req ) {
		$id = absint( $req->get_param( 'id' ) );

		/** @var ApiKeyRepository $repo */
		$repo = $this->container->make( ApiKeyRepository::class );
		$key  = $repo->find( $id );

		if ( null === $key ) {
			return ResponseFactory::error( 'wplm_not_found', __( 'API key not found.', 'wp-license-manager' ), 404 );
		}

		return ResponseFactory::success( $this->prepare_key( $key ) );
	}

	/**
	 * POST /api-keys — generates a new consumer key pair.
	 *
	 * The plain-text consumer_key and consumer_secret are returned ONCE here
	 * and never again (only the hash is stored). The caller must record them.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $req ) {
		$description = sanitize_text_field( wp_unslash( $req->get_param( 'description' ) ) );
		$permissions = sanitize_text_field( wp_unslash( $req->get_param( 'permissions' ) ) );
		$user_id     = absint( $req->get_param( 'user_id' ) ) ?: get_current_user_id();

		// Generate plaintext credentials.
		$raw_key    = 'ck_' . bin2hex( random_bytes( 20 ) );
		$raw_secret = 'cs_' . bin2hex( random_bytes( 20 ) );

		$data = array(
			'user_id'         => $user_id,
			'description'     => $description,
			'permissions'     => $permissions,
			'consumer_key'    => hash( 'sha256', $raw_key ),
			'consumer_secret' => hash( 'sha256', $raw_secret ),
			'truncated_key'   => '…' . substr( $raw_key, -7 ),
			'last_access_at'  => null,
			'created_at'      => current_time( 'mysql', true ),
		);

		/** @var ApiKeyRepository $repo */
		$repo = $this->container->make( ApiKeyRepository::class );
		$id   = $repo->insert( $data );

		if ( ! $id ) {
			return ResponseFactory::error( 'wplm_create_failed', __( 'Failed to create API key.', 'wp-license-manager' ), 500 );
		}

		return ResponseFactory::success(
			array(
				'id'              => $id,
				'consumer_key'    => $raw_key,
				'consumer_secret' => $raw_secret,
				'description'     => $description,
				'permissions'     => $permissions,
				'user_id'         => $user_id,
			),
			array(),
			201
		);
	}

	/**
	 * DELETE /api-keys/{id}
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $req ) {
		$id = absint( $req->get_param( 'id' ) );

		/** @var ApiKeyRepository $repo */
		$repo    = $this->container->make( ApiKeyRepository::class );
		$deleted = $repo->delete( $id );

		if ( ! $deleted ) {
			return ResponseFactory::error( 'wplm_not_found', __( 'API key not found.', 'wp-license-manager' ), 404 );
		}

		return ResponseFactory::success(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Prepare a safe API key representation (never expose the hash as "the key").
	 *
	 * @param ApiKey $key Model.
	 * @return array
	 */
	private function prepare_key( ApiKey $key ): array {
		return array(
			'id'             => $key->id,
			'user_id'        => $key->user_id,
			'description'    => $key->description,
			'permissions'    => $key->permissions,
			'truncated_key'  => $key->truncated_key,
			'last_access_at' => $key->last_access_at,
			'created_at'     => $key->created_at,
		);
	}
}
