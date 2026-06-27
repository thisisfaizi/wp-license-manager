<?php
/**
 * REST controller for license CRUD and lifecycle actions.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Services\RevocationService;
use WPLM\Support\ResponseFactory;

/**
 * Handles all /licenses endpoints including CRUD, renew, suspend, revoke,
 * reinstate, and terminate.
 */
class LicensesController extends BaseController {

	/** Base REST namespace. */
	const NAMESPACE = 'wplm/v1';

	/**
	 * Register all license routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		// Collection.
		register_rest_route(
			self::NAMESPACE,
			'/licenses',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'require_read' ),
					'args'                => array(
						'status'     => array(
							'type'    => 'integer',
							'default' => null,
						),
						'product_id' => array(
							'type'    => 'integer',
							'default' => null,
						),
						'order_id'   => array(
							'type'    => 'integer',
							'default' => null,
						),
						'user_id'    => array(
							'type'    => 'integer',
							'default' => null,
						),
						'per_page'   => array(
							'type'    => 'integer',
							'default' => 20,
						),
						'page'       => array(
							'type'    => 'integer',
							'default' => 1,
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
			'/licenses/(?P<key>[A-Z0-9\-]+)',
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

		// Lifecycle actions.
		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<key>[A-Z0-9\-]+)/renew',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'renew_item' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<key>[A-Z0-9\-]+)/suspend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suspend_item' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<key>[A-Z0-9\-]+)/revoke',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_item' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<key>[A-Z0-9\-]+)/reinstate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reinstate_item' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<key>[A-Z0-9\-]+)/terminate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'terminate_item' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Collection handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /licenses
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $req ) {
		try {
			$params = array(
				'status'     => $req->get_param( 'status' ) !== null ? absint( $req->get_param( 'status' ) ) : null,
				'product_id' => $req->get_param( 'product_id' ) !== null ? absint( $req->get_param( 'product_id' ) ) : null,
				'order_id'   => $req->get_param( 'order_id' ) !== null ? absint( $req->get_param( 'order_id' ) ) : null,
				'user_id'    => $req->get_param( 'user_id' ) !== null ? absint( $req->get_param( 'user_id' ) ) : null,
				'per_page'   => absint( $req->get_param( 'per_page' ) ?: 20 ),
				'page'       => absint( $req->get_param( 'page' ) ?: 1 ),
			);

			// Remove null filter values so the repository ignores them.
			$params = array_filter( $params, fn( $v ) => null !== $v );

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$result  = $service->get_list( $params );

			$licenses = array_map( fn( $l ) => $l->to_array(), $result['items'] );

			return ResponseFactory::success(
				array( 'licenses' => $licenses ),
				array( 'total' => $result['total'] )
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /licenses
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $req ) {
		try {
			$body         = $req->get_json_params() ?: array();
			$key_string   = sanitize_text_field( wp_unslash( $body['key_string'] ?? '' ) );
			$generator_id = isset( $body['generator_id'] ) ? absint( $body['generator_id'] ) : 0;

			// If a generator is specified, produce the key string from it.
			if ( '' === $key_string && $generator_id > 0 ) {
				/** @var GeneratorService $gen_service */
				$gen_service = $this->container->make( GeneratorService::class );
				$keys        = $gen_service->generate_batch( $generator_id, 1 );
				$key_string  = $keys[0] ?? '';
			}

			if ( '' === $key_string ) {
				return ResponseFactory::error(
					'wplm_missing_key',
					__( 'Either key_string or a valid generator_id is required.', 'wp-license-manager' ),
					400
				);
			}

			$args = array(
				'key_string'       => $key_string,
				'product_id'       => isset( $body['product_id'] ) ? absint( $body['product_id'] ) : null,
				'order_id'         => isset( $body['order_id'] ) ? absint( $body['order_id'] ) : null,
				'user_id'          => isset( $body['user_id'] ) ? absint( $body['user_id'] ) : null,
				'max_activations'  => isset( $body['max_activations'] ) ? absint( $body['max_activations'] ) : null,
				'valid_for_days'   => isset( $body['valid_for_days'] ) ? absint( $body['valid_for_days'] ) : null,
				'expires_at'       => isset( $body['expires_at'] ) ? sanitize_text_field( wp_unslash( $body['expires_at'] ) ) : null,
				'grace_days'       => isset( $body['grace_days'] ) ? absint( $body['grace_days'] ) : 0,
				'overage_strategy' => sanitize_text_field( wp_unslash( $body['overage_strategy'] ?? 'deny' ) ),
				'source'           => isset( $body['source'] ) ? absint( $body['source'] ) : 2,
				'is_floating'      => ! empty( $body['is_floating'] ),
				'created_by'       => get_current_user_id() ?: null,
			);

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$license = $service->create( $args );

			return ResponseFactory::success( $license->to_array( true ), array(), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return ResponseFactory::error( 'wplm_invalid_argument', $e->getMessage(), 400 );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Single item handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /licenses/{key}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $req ) {
		try {
			$key_string = sanitize_text_field( wp_unslash( $req->get_param( 'key' ) ) );

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$license = $service->get_by_key( $key_string );

			if ( null === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			return ResponseFactory::success( $license->to_array( true ) );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * PUT /licenses/{key}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $req ) {
		try {
			$key_string = sanitize_text_field( wp_unslash( $req->get_param( 'key' ) ) );

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$license = $service->get_by_key( $key_string );

			if ( null === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			$body = $req->get_json_params() ?: array();

			// Only allow a well-defined set of fields to be updated.
			$allowed_data = array();

			if ( isset( $body['max_activations'] ) ) {
				$allowed_data['max_activations'] = absint( $body['max_activations'] );
			}
			if ( isset( $body['expires_at'] ) ) {
				$allowed_data['expires_at'] = sanitize_text_field( wp_unslash( $body['expires_at'] ) );
			}
			if ( isset( $body['grace_days'] ) ) {
				$allowed_data['grace_days'] = absint( $body['grace_days'] );
			}
			if ( isset( $body['status'] ) ) {
				$allowed_data['status'] = absint( $body['status'] );
			}
			if ( isset( $body['overage_strategy'] ) ) {
				$allowed_data['overage_strategy'] = sanitize_text_field( wp_unslash( $body['overage_strategy'] ) );
			}
			if ( isset( $body['valid_for_days'] ) ) {
				$allowed_data['valid_for_days'] = absint( $body['valid_for_days'] );
			}

			if ( empty( $allowed_data ) ) {
				return ResponseFactory::error( 'wplm_no_fields', __( 'No updatable fields provided.', 'wp-license-manager' ), 400 );
			}

			$service->update( $license->id, $allowed_data );
			$updated = $service->get_by_id( $license->id );

			return ResponseFactory::success( $updated ? $updated->to_array( true ) : $license->to_array( true ) );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * DELETE /licenses/{key}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $req ) {
		try {
			$key_string = sanitize_text_field( wp_unslash( $req->get_param( 'key' ) ) );

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$license = $service->get_by_key( $key_string );

			if ( null === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			$deleted = $service->delete( $license->id );

			if ( ! $deleted ) {
				return ResponseFactory::error( 'wplm_delete_failed', __( 'Failed to delete license.', 'wp-license-manager' ), 500 );
			}

			return ResponseFactory::success(
				array(
					'deleted' => true,
					'id'      => $license->id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Lifecycle action handlers
	// -------------------------------------------------------------------------

	/**
	 * POST /licenses/{key}/renew
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function renew_item( \WP_REST_Request $req ) {
		try {
			$key_string = sanitize_text_field( wp_unslash( $req->get_param( 'key' ) ) );
			$body       = $req->get_json_params() ?: array();
			$expires_at = sanitize_text_field( wp_unslash( $body['expires_at'] ?? '' ) );

			if ( '' === $expires_at ) {
				return ResponseFactory::error( 'wplm_missing_expires_at', __( 'expires_at is required.', 'wp-license-manager' ), 400 );
			}

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$license = $service->renew( $key_string, $expires_at );

			if ( false === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			return ResponseFactory::success( $license->to_array( true ) );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /licenses/{key}/suspend
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suspend_item( \WP_REST_Request $req ) {
		try {
			$license = $this->resolve_license( $req );
			if ( is_wp_error( $license ) ) {
				return $license;
			}

			/** @var RevocationService $service */
			$service = $this->container->make( RevocationService::class );
			$service->suspend_license( $license->id );

			return ResponseFactory::success(
				array(
					'suspended' => true,
					'id'        => $license->id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /licenses/{key}/revoke
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_item( \WP_REST_Request $req ) {
		try {
			$license = $this->resolve_license( $req );
			if ( is_wp_error( $license ) ) {
				return $license;
			}

			/** @var RevocationService $service */
			$service = $this->container->make( RevocationService::class );
			$service->revoke_license( $license->id );

			return ResponseFactory::success(
				array(
					'revoked' => true,
					'id'      => $license->id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /licenses/{key}/reinstate
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reinstate_item( \WP_REST_Request $req ) {
		try {
			$license = $this->resolve_license( $req );
			if ( is_wp_error( $license ) ) {
				return $license;
			}

			/** @var RevocationService $service */
			$service = $this->container->make( RevocationService::class );
			$service->reinstate_license( $license->id );

			return ResponseFactory::success(
				array(
					'reinstated' => true,
					'id'         => $license->id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /licenses/{key}/terminate
	 *
	 * Requires `confirm: true` in the request body.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function terminate_item( \WP_REST_Request $req ) {
		try {
			$body    = $req->get_json_params() ?: array();
			$confirm = ! empty( $body['confirm'] );

			if ( ! $confirm ) {
				return ResponseFactory::error(
					'wplm_confirm_required',
					__( 'Pass confirm: true to permanently terminate a license.', 'wp-license-manager' ),
					400
				);
			}

			$license = $this->resolve_license( $req );
			if ( is_wp_error( $license ) ) {
				return $license;
			}

			/** @var RevocationService $service */
			$service = $this->container->make( RevocationService::class );
			$service->terminate_license( $license->id );

			return ResponseFactory::success(
				array(
					'terminated' => true,
					'id'         => $license->id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a license by the {key} route param, returning it or a WP_Error.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WPLM\Models\License|\WP_Error
	 */
	private function resolve_license( \WP_REST_Request $req ) {
		$key_string = sanitize_text_field( wp_unslash( $req->get_param( 'key' ) ) );

		/** @var LicenseService $service */
		$service = $this->container->make( LicenseService::class );
		$license = $service->get_by_key( $key_string );

		if ( null === $license ) {
			return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
		}

		return $license;
	}
}
