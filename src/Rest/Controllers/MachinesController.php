<?php
/**
 * REST controller for machine (device) management.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\MachineRepository;
use WPLM\Services\ActivationService;
use WPLM\Services\LicenseService;
use WPLM\Services\RevocationService;
use WPLM\Support\ResponseFactory;

/**
 * Handles /licenses/{key}/machines and /machines/{id} endpoints.
 */
class MachinesController extends BaseController {

	/** Base REST namespace. */
	const NAMESPACE = 'wplm/v1';

	/**
	 * Register all machine routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		// List machines for a license.
		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<key>[A-Z0-9\-]+)/machines',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_machines_for_license' ),
				'permission_callback' => array( $this, 'require_read' ),
			)
		);

		// Single machine — get.
		register_rest_route(
			self::NAMESPACE,
			'/machines/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_machine' ),
					'permission_callback' => array( $this, 'require_read' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deactivate_machine' ),
					'permission_callback' => array( $this, 'require_write' ),
				),
			)
		);

		// Revoke a single machine.
		register_rest_route(
			self::NAMESPACE,
			'/machines/(?P<id>\d+)/revoke',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_machine' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /licenses/{key}/machines
	 *
	 * Lists all machines associated with the resolved license. Returns all
	 * machines regardless of status (status = -1) so the caller can see
	 * deactivated and revoked devices too.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_machines_for_license( \WP_REST_Request $req ) {
		try {
			$key_string = sanitize_text_field( wp_unslash( $req->get_param( 'key' ) ) );

			/** @var LicenseService $license_service */
			$license_service = $this->container->make( LicenseService::class );
			$license         = $license_service->get_by_key( $key_string );

			if ( null === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			/** @var MachineRepository $machine_repo */
			$machine_repo = $this->container->make( MachineRepository::class );
			$machines     = $machine_repo->get_by_license( $license->id, -1 );

			return ResponseFactory::success(
				array( 'machines' => array_map( fn( $m ) => $m->to_array(), $machines ) ),
				array( 'total' => count( $machines ) )
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /machines/{id}
	 *
	 * Returns a single machine record.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_machine( \WP_REST_Request $req ) {
		try {
			$machine_id = absint( $req->get_param( 'id' ) );

			/** @var MachineRepository $machine_repo */
			$machine_repo = $this->container->make( MachineRepository::class );
			$machine      = $machine_repo->find_by_id( $machine_id );

			if ( null === $machine ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'Machine not found.', 'wp-license-manager' ), 404 );
			}

			return ResponseFactory::success( $machine->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * DELETE /machines/{id}
	 *
	 * Deactivates the specified machine (does not permanently delete).
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function deactivate_machine( \WP_REST_Request $req ) {
		try {
			$machine_id = absint( $req->get_param( 'id' ) );

			/** @var ActivationService $service */
			$service = $this->container->make( ActivationService::class );
			$result  = $service->deactivate_by_machine_id( $machine_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return ResponseFactory::success(
				array(
					'deactivated' => true,
					'id'          => $machine_id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /machines/{id}/revoke
	 *
	 * Permanently revokes the specified machine (status → 3).
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_machine( \WP_REST_Request $req ) {
		try {
			$machine_id = absint( $req->get_param( 'id' ) );

			/** @var RevocationService $service */
			$service = $this->container->make( RevocationService::class );
			$service->revoke_device( $machine_id );

			return ResponseFactory::success(
				array(
					'revoked' => true,
					'id'      => $machine_id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}
}
