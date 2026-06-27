<?php
/**
 * REST controller for public validation, activation, and heartbeat endpoints.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Crypto\Signer;
use WPLM\Services\ActivationService;
use WPLM\Services\HeartbeatService;
use WPLM\Services\LicenseService;
use WPLM\Services\RevocationService;
use WPLM\Support\ResponseFactory;

/**
 * All routes in this controller are public — no API key is required.
 *
 * Endpoints: POST /validate, POST /activate, POST /deactivate,
 *            POST /heartbeat, GET /crl, GET /public-key.
 */
class ValidationController extends BaseController {

	/** Base REST namespace. */
	const NAMESPACE = 'wplm/v1';

	/**
	 * Register all validation routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => array( $this, 'public_route' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/activate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => array( $this, 'public_route' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/deactivate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate' ),
				'permission_callback' => array( $this, 'public_route' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/heartbeat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'heartbeat' ),
				'permission_callback' => array( $this, 'public_route' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/crl',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_crl' ),
				'permission_callback' => array( $this, 'public_route' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public-key',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_key' ),
				'permission_callback' => array( $this, 'public_route' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * POST /validate
	 *
	 * Validates a license key. Returns the full validation result envelope.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate( \WP_REST_Request $req ) {
		try {
			$body        = $req->get_json_params() ?: array();
			$license_key = sanitize_text_field( wp_unslash( $body['license_key'] ?? '' ) );

			if ( '' === $license_key ) {
				return ResponseFactory::error( 'wplm_missing_license_key', __( 'license_key is required.', 'wp-license-manager' ), 400 );
			}

			$fingerprint = sanitize_text_field( wp_unslash( $body['fingerprint'] ?? '' ) );
			$ip_address  = sanitize_text_field( wp_unslash( $body['ip_address'] ?? '' ) );

			$context = array(
				'ip'      => $ip_address ?: $this->get_client_ip(),
				'country' => '',
			);

			/** @var LicenseService $service */
			$service = $this->container->make( LicenseService::class );
			$result  = $service->validate( $license_key, $fingerprint ?: null, $context );

			$status = $result['valid'] ? 200 : 422;

			return ResponseFactory::success( $result, array(), $status );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /activate
	 *
	 * Activates a device against a license key.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate( \WP_REST_Request $req ) {
		try {
			$body        = $req->get_json_params() ?: array();
			$license_key = sanitize_text_field( wp_unslash( $body['license_key'] ?? '' ) );
			$fingerprint = sanitize_text_field( wp_unslash( $body['fingerprint'] ?? '' ) );

			if ( '' === $license_key ) {
				return ResponseFactory::error( 'wplm_missing_license_key', __( 'license_key is required.', 'wp-license-manager' ), 400 );
			}

			if ( '' === $fingerprint ) {
				return ResponseFactory::error( 'wplm_missing_fingerprint', __( 'fingerprint is required.', 'wp-license-manager' ), 400 );
			}

			$meta = array(
				'name'        => sanitize_text_field( wp_unslash( $body['name'] ?? '' ) ),
				'hostname'    => sanitize_text_field( wp_unslash( $body['hostname'] ?? '' ) ),
				'platform'    => sanitize_text_field( wp_unslash( $body['platform'] ?? '' ) ),
				'app_version' => sanitize_text_field( wp_unslash( $body['app_version'] ?? '' ) ),
				'ip_address'  => sanitize_text_field( wp_unslash( $body['ip_address'] ?? '' ) ) ?: $this->get_client_ip(),
				'components'  => is_array( $body['components'] ?? null ) ? $body['components'] : array(),
			);

			/** @var ActivationService $service */
			$service = $this->container->make( ActivationService::class );
			$machine = $service->activate( $license_key, $fingerprint, $meta );

			if ( is_wp_error( $machine ) ) {
				return $machine;
			}

			return ResponseFactory::success( $machine->to_array(), array(), 201 );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /deactivate
	 *
	 * Deactivates a device by fingerprint or directly by machine_id.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function deactivate( \WP_REST_Request $req ) {
		try {
			$body       = $req->get_json_params() ?: array();
			$machine_id = isset( $body['machine_id'] ) ? absint( $body['machine_id'] ) : 0;

			/** @var ActivationService $service */
			$service = $this->container->make( ActivationService::class );

			if ( $machine_id > 0 ) {
				$result = $service->deactivate_by_machine_id( $machine_id );
			} else {
				$license_key = sanitize_text_field( wp_unslash( $body['license_key'] ?? '' ) );
				$fingerprint = sanitize_text_field( wp_unslash( $body['fingerprint'] ?? '' ) );

				if ( '' === $license_key || '' === $fingerprint ) {
					return ResponseFactory::error(
						'wplm_missing_params',
						__( 'Provide machine_id, or both license_key and fingerprint.', 'wp-license-manager' ),
						400
					);
				}

				$result = $service->deactivate( $license_key, $fingerprint );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return ResponseFactory::success( array( 'deactivated' => true ) );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /heartbeat
	 *
	 * Records a floating-license heartbeat ping.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function heartbeat( \WP_REST_Request $req ) {
		try {
			$body        = $req->get_json_params() ?: array();
			$license_key = sanitize_text_field( wp_unslash( $body['license_key'] ?? '' ) );
			$fingerprint = sanitize_text_field( wp_unslash( $body['fingerprint'] ?? '' ) );

			if ( '' === $license_key || '' === $fingerprint ) {
				return ResponseFactory::error( 'wplm_missing_params', __( 'license_key and fingerprint are required.', 'wp-license-manager' ), 400 );
			}

			/** @var LicenseService $license_service */
			$license_service = $this->container->make( LicenseService::class );
			$license         = $license_service->get_by_key( $license_key );

			if ( null === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			$context = array(
				'ip_address'  => sanitize_text_field( wp_unslash( $body['ip_address'] ?? '' ) ) ?: $this->get_client_ip(),
				'app_version' => sanitize_text_field( wp_unslash( $body['app_version'] ?? '' ) ),
			);

			/** @var HeartbeatService $hb_service */
			$hb_service = $this->container->make( HeartbeatService::class );
			$machine    = $hb_service->record_heartbeat( $fingerprint, $license->id, $context );

			if ( is_wp_error( $machine ) ) {
				return $machine;
			}

			return ResponseFactory::success( $machine->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /crl
	 *
	 * Returns the signed Certificate Revocation List.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_crl( \WP_REST_Request $req ) {
		try {
			/** @var RevocationService $service */
			$service = $this->container->make( RevocationService::class );
			$crl     = $service->get_crl();

			return ResponseFactory::success( array( 'crl' => $crl ) );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /public-key
	 *
	 * Returns the Ed25519 public key as a base64-encoded string.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_public_key( \WP_REST_Request $req ) {
		try {
			/** @var Signer $signer */
			$signer     = $this->container->make( Signer::class );
			$public_key = $signer->get_public_key_base64();

			return ResponseFactory::success( array( 'public_key' => $public_key ) );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the best-guess client IP address from the current request.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$candidates = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For may be a comma-separated list; take the first value.
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}
}
