<?php
/**
 * REST controller for subscription management.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Services\Subscriptions\SwitchManager;
use WPLM\Support\ResponseFactory;

/**
 * Handles all /subscriptions endpoints including lifecycle transitions.
 */
class SubscriptionsController extends BaseController {

	/** Base REST namespace. */
	const NAMESPACE = 'wplm/v1';

	/**
	 * Register all subscription routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		// Collection.
		register_rest_route(
			self::NAMESPACE,
			'/subscriptions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'require_read' ),
					'args'                => array(
						'user_id' => array(
							'type'    => 'integer',
							'default' => null,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'require_write' ),
				),
			)
		);

		// Single item.
		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)',
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
			)
		);

		// Lifecycle transitions.
		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/pause',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pause' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/resume',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/switch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'switch_plan' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/renew',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'renew' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		// Renewal history.
		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/(?P<id>\d+)/renewals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_renewals' ),
				'permission_callback' => array( $this, 'require_read' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /subscriptions
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $req ) {
		try {
			$args = array();

			if ( null !== $req->get_param( 'user_id' ) ) {
				$args['user_id'] = absint( $req->get_param( 'user_id' ) );
			}

			/** @var SubscriptionService $service */
			$service       = $this->container->make( SubscriptionService::class );
			$subscriptions = $service->get_list( $args );

			return ResponseFactory::success(
				array( 'subscriptions' => array_map( fn( $s ) => $s->to_array(), $subscriptions ) ),
				array( 'total' => count( $subscriptions ) )
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /subscriptions
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $req ) {
		try {
			$body = $req->get_json_params() ?: array();
			$args = $this->sanitize_subscription_data( $body );

			/** @var SubscriptionService $service */
			$service      = $this->container->make( SubscriptionService::class );
			$subscription = $service->create( $args );

			return ResponseFactory::success( $subscription->to_array(), array(), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return ResponseFactory::error( 'wplm_invalid_argument', $e->getMessage(), 400 );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /subscriptions/{id}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $req ) {
		try {
			$id           = absint( $req->get_param( 'id' ) );
			$subscription = $this->resolve_subscription( $id );

			if ( is_wp_error( $subscription ) ) {
				return $subscription;
			}

			return ResponseFactory::success( $subscription->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * PUT /subscriptions/{id}
	 *
	 * Allows updating billing schedule and amount.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $req ) {
		try {
			$id           = absint( $req->get_param( 'id' ) );
			$subscription = $this->resolve_subscription( $id );

			if ( is_wp_error( $subscription ) ) {
				return $subscription;
			}

			$body         = $req->get_json_params() ?: array();
			$allowed_data = array();

			if ( isset( $body['billing_interval'] ) ) {
				$allowed_data['billing_interval'] = absint( $body['billing_interval'] );
			}
			if ( isset( $body['billing_period'] ) ) {
				$allowed_data['billing_period'] = sanitize_text_field( wp_unslash( $body['billing_period'] ) );
			}
			if ( isset( $body['recurring_total'] ) ) {
				$allowed_data['recurring_total'] = (float) $body['recurring_total'];
			}
			if ( isset( $body['next_payment'] ) ) {
				$allowed_data['next_payment'] = sanitize_text_field( wp_unslash( $body['next_payment'] ) );
			}
			if ( isset( $body['end_date'] ) ) {
				$allowed_data['end_date'] = sanitize_text_field( wp_unslash( $body['end_date'] ) );
			}

			if ( empty( $allowed_data ) ) {
				return ResponseFactory::error( 'wplm_no_fields', __( 'No updatable fields provided.', 'wp-license-manager' ), 400 );
			}

			/** @var SubscriptionRepository $sub_repo */
			$sub_repo = $this->container->make( SubscriptionRepository::class );
			$sub_repo->update( $id, $allowed_data );

			/** @var SubscriptionService $service */
			$service = $this->container->make( SubscriptionService::class );
			$updated = $service->get( $id );

			return ResponseFactory::success( $updated ? $updated->to_array() : $subscription->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /subscriptions/{id}/pause
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pause( \WP_REST_Request $req ) {
		try {
			$id = absint( $req->get_param( 'id' ) );

			/** @var SubscriptionService $service */
			$service = $this->container->make( SubscriptionService::class );
			$service->pause( $id );

			$updated = $service->get( $id );

			return ResponseFactory::success(
				$updated ? $updated->to_array() : array(
					'id'     => $id,
					'status' => 'on-hold',
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /subscriptions/{id}/resume
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume( \WP_REST_Request $req ) {
		try {
			$id = absint( $req->get_param( 'id' ) );

			/** @var SubscriptionService $service */
			$service = $this->container->make( SubscriptionService::class );
			$service->resume( $id );

			$updated = $service->get( $id );

			return ResponseFactory::success(
				$updated ? $updated->to_array() : array(
					'id'     => $id,
					'status' => 'active',
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /subscriptions/{id}/cancel
	 *
	 * Body: at_period_end (bool, default false).
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( \WP_REST_Request $req ) {
		try {
			$id            = absint( $req->get_param( 'id' ) );
			$body          = $req->get_json_params() ?: array();
			$at_period_end = (bool) ( $body['at_period_end'] ?? false );

			/** @var SubscriptionService $service */
			$service = $this->container->make( SubscriptionService::class );
			$service->cancel( $id, $at_period_end );

			$updated = $service->get( $id );
			$status  = $at_period_end ? 'pending-cancel' : 'cancelled';

			return ResponseFactory::success(
				$updated ? $updated->to_array() : array(
					'id'     => $id,
					'status' => $status,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /subscriptions/{id}/switch
	 *
	 * Body: new_plan array (product_id, recurring_total, billing_interval, billing_period, max_activations).
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function switch_plan( \WP_REST_Request $req ) {
		try {
			$id       = absint( $req->get_param( 'id' ) );
			$body     = $req->get_json_params() ?: array();
			$new_plan = array();

			if ( isset( $body['product_id'] ) ) {
				$new_plan['product_id'] = absint( $body['product_id'] );
			}
			if ( isset( $body['variation_id'] ) ) {
				$new_plan['variation_id'] = absint( $body['variation_id'] );
			}
			if ( isset( $body['recurring_total'] ) ) {
				$new_plan['recurring_total'] = (float) $body['recurring_total'];
			}
			if ( isset( $body['billing_interval'] ) ) {
				$new_plan['billing_interval'] = absint( $body['billing_interval'] );
			}
			if ( isset( $body['billing_period'] ) ) {
				$new_plan['billing_period'] = sanitize_text_field( wp_unslash( $body['billing_period'] ) );
			}
			if ( isset( $body['max_activations'] ) ) {
				$new_plan['max_activations'] = absint( $body['max_activations'] );
			}

			/** @var SwitchManager $switch_manager */
			$switch_manager = $this->container->make( SwitchManager::class );
			$result         = $switch_manager->switch_plan( $id, $new_plan );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return ResponseFactory::success( $result->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /subscriptions/{id}/renew
	 *
	 * Triggers an immediate renewal attempt for the subscription.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function renew( \WP_REST_Request $req ) {
		try {
			$id           = absint( $req->get_param( 'id' ) );
			$subscription = $this->resolve_subscription( $id );

			if ( is_wp_error( $subscription ) ) {
				return $subscription;
			}

			/** @var RenewalProcessor $processor */
			$processor = $this->container->make( RenewalProcessor::class );
			$processor->process_single( $subscription );

			// Reload the subscription to return its updated state.
			/** @var SubscriptionService $service */
			$service = $this->container->make( SubscriptionService::class );
			$updated = $service->get( $id );

			return ResponseFactory::success( $updated ? $updated->to_array() : $subscription->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /subscriptions/{id}/renewals
	 *
	 * Returns the renewal history for a subscription.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_renewals( \WP_REST_Request $req ) {
		try {
			$id           = absint( $req->get_param( 'id' ) );
			$subscription = $this->resolve_subscription( $id );

			if ( is_wp_error( $subscription ) ) {
				return $subscription;
			}

			/** @var RenewalRepository $renewal_repo */
			$renewal_repo = $this->container->make( RenewalRepository::class );
			$renewals     = $renewal_repo->get_by_subscription( $id );

			return ResponseFactory::success(
				array( 'renewals' => array_map( fn( $r ) => $r->to_array(), $renewals ) ),
				array( 'total' => count( $renewals ) )
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Load a subscription by id, returning it or a WP_Error 404.
	 *
	 * @param int $id Subscription row id.
	 * @return \WPLM\Models\Subscription|\WP_Error
	 */
	private function resolve_subscription( int $id ) {
		/** @var SubscriptionService $service */
		$service      = $this->container->make( SubscriptionService::class );
		$subscription = $service->get( $id );

		if ( null === $subscription ) {
			return ResponseFactory::error( 'wplm_not_found', __( 'Subscription not found.', 'wp-license-manager' ), 404 );
		}

		return $subscription;
	}

	/**
	 * Sanitize subscription fields from a raw request body.
	 *
	 * @param array $body Raw body array.
	 * @return array Sanitized data.
	 */
	private function sanitize_subscription_data( array $body ): array {
		$data = array();

		if ( isset( $body['user_id'] ) ) {
			$data['user_id'] = absint( $body['user_id'] );
		}
		if ( isset( $body['billing_interval'] ) ) {
			$data['billing_interval'] = absint( $body['billing_interval'] );
		}
		if ( isset( $body['billing_period'] ) ) {
			$data['billing_period'] = sanitize_text_field( wp_unslash( $body['billing_period'] ) );
		}
		if ( isset( $body['recurring_total'] ) ) {
			$data['recurring_total'] = (float) $body['recurring_total'];
		}
		if ( isset( $body['currency'] ) ) {
			$data['currency'] = strtoupper( sanitize_text_field( wp_unslash( $body['currency'] ) ) );
		}
		if ( isset( $body['trial_end'] ) ) {
			$data['trial_end'] = sanitize_text_field( wp_unslash( $body['trial_end'] ) );
		}
		if ( isset( $body['next_payment'] ) ) {
			$data['next_payment'] = sanitize_text_field( wp_unslash( $body['next_payment'] ) );
		}
		if ( isset( $body['parent_order_id'] ) ) {
			$data['parent_order_id'] = absint( $body['parent_order_id'] );
		}
		if ( isset( $body['license_id'] ) ) {
			$data['license_id'] = absint( $body['license_id'] );
		}
		if ( isset( $body['signup_fee'] ) ) {
			$data['signup_fee'] = (float) $body['signup_fee'];
		}
		if ( isset( $body['payment_method'] ) ) {
			$data['payment_method'] = sanitize_text_field( wp_unslash( $body['payment_method'] ) );
		}
		if ( isset( $body['payment_token_id'] ) ) {
			$data['payment_token_id'] = absint( $body['payment_token_id'] );
		}
		if ( isset( $body['end_date'] ) ) {
			$data['end_date'] = sanitize_text_field( wp_unslash( $body['end_date'] ) );
		}
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			$data['items'] = $body['items'];
		}

		return $data;
	}
}
