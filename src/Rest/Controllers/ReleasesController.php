<?php
/**
 * REST controller for releases and update delivery.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Crypto\Signer;
use WPLM\Repositories\ReleaseRepository;
use WPLM\Services\LicenseService;
use WPLM\Support\ResponseFactory;

/**
 * Handles /releases CRUD and the /updates/check + /updates/download endpoints.
 */
class ReleasesController extends BaseController {

	/** Base REST namespace. */
	const NAMESPACE = 'wplm/v1';

	/** Signed download token lifetime in seconds (1 hour). */
	const TOKEN_TTL = 3600;

	/**
	 * Register all release routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		// Release CRUD.
		register_rest_route(
			self::NAMESPACE,
			'/releases',
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
				),
			)
		);

		// Update check (public).
		register_rest_route(
			self::NAMESPACE,
			'/updates/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_update' ),
				'permission_callback' => array( $this, 'public_route' ),
				'args'                => array(
					'license_key'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'product_id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'current_version' => array(
						'type'     => 'string',
						'required' => true,
					),
					'channel'         => array(
						'type'    => 'string',
						'default' => 'stable',
					),
				),
			)
		);

		// Download via signed token (public, token-gated).
		register_rest_route(
			self::NAMESPACE,
			'/updates/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'public_route' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /releases
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $req ) {
		try {
			$product_id = $req->get_param( 'product_id' ) ? absint( $req->get_param( 'product_id' ) ) : 0;

			/** @var ReleaseRepository $repo */
			$repo = $this->container->make( ReleaseRepository::class );

			if ( $product_id > 0 ) {
				$releases = $repo->get_by_product( $product_id );
			} else {
				// No global list method — return empty for now (can be extended).
				$releases = array();
			}

			return ResponseFactory::success(
				array( 'releases' => array_map( fn( $r ) => $r->to_array(), $releases ) ),
				array( 'total' => count( $releases ) )
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /releases
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $req ) {
		try {
			$body = $req->get_json_params() ?: array();

			$product_id = isset( $body['product_id'] ) ? absint( $body['product_id'] ) : 0;
			if ( $product_id < 1 ) {
				return ResponseFactory::error( 'wplm_missing_product_id', __( 'product_id is required.', 'wp-license-manager' ), 400 );
			}

			$version = sanitize_text_field( wp_unslash( $body['version'] ?? '' ) );
			if ( '' === $version ) {
				return ResponseFactory::error( 'wplm_missing_version', __( 'version is required.', 'wp-license-manager' ), 400 );
			}

			$data = array(
				'product_id'      => $product_id,
				'version'         => $version,
				'channel'         => sanitize_text_field( wp_unslash( $body['channel'] ?? 'stable' ) ),
				'file_path'       => esc_url_raw( wp_unslash( $body['file_path'] ?? '' ) ),
				'changelog'       => wp_kses_post( wp_unslash( $body['changelog'] ?? '' ) ),
				'min_app_version' => sanitize_text_field( wp_unslash( $body['min_app_version'] ?? '' ) ),
			);

			/** @var ReleaseRepository $repo */
			$repo    = $this->container->make( ReleaseRepository::class );
			$id      = $repo->create( $data );
			$release = $repo->find_by_id( $id );

			return ResponseFactory::success( $release ? $release->to_array() : array( 'id' => $id ), array(), 201 );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /updates/check
	 *
	 * Checks for a newer release for the given license and product.
	 * Returns a short-lived signed download token when an update is available.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_update( \WP_REST_Request $req ) {
		try {
			$license_key     = sanitize_text_field( wp_unslash( $req->get_param( 'license_key' ) ) );
			$product_id      = absint( $req->get_param( 'product_id' ) );
			$current_version = sanitize_text_field( wp_unslash( $req->get_param( 'current_version' ) ) );
			$channel         = sanitize_text_field( wp_unslash( $req->get_param( 'channel' ) ?: 'stable' ) );

			// Validate the license.
			/** @var LicenseService $license_service */
			$license_service = $this->container->make( LicenseService::class );
			$license         = $license_service->get_by_key( $license_key );

			if ( null === $license ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'License not found.', 'wp-license-manager' ), 404 );
			}

			if ( ! $license->is_active() && 2 !== $license->status ) {
				return ResponseFactory::error( 'wplm_license_invalid', __( 'License is not active.', 'wp-license-manager' ), 403 );
			}

			// Check for a newer release.
			/** @var ReleaseRepository $release_repo */
			$release_repo = $this->container->make( ReleaseRepository::class );
			$latest       = $release_repo->get_latest_for_product( $product_id, $channel );

			if ( null === $latest ) {
				return ResponseFactory::success( array( 'update_available' => false ) );
			}

			// Compare versions (PHP's version_compare is sufficient for semver-like strings).
			if ( version_compare( $latest->version, $current_version, '<=' ) ) {
				return ResponseFactory::success(
					array(
						'update_available' => false,
						'current_version'  => $current_version,
					)
				);
			}

			// Build a signed, time-limited download token.
			/** @var Signer $signer */
			$signer = $this->container->make( Signer::class );
			$token  = $signer->sign(
				array(
					'release_id' => $latest->id,
					'license_id' => $license->id,
					'exp'        => time() + self::TOKEN_TTL,
				)
			);

			return ResponseFactory::success(
				array(
					'update_available' => true,
					'latest_version'   => $latest->version,
					'current_version'  => $current_version,
					'channel'          => $latest->channel,
					'changelog'        => $latest->changelog ?? '',
					'min_app_version'  => $latest->min_app_version ?? '',
					'download_token'   => $token,
					'token_expires_in' => self::TOKEN_TTL,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /updates/download
	 *
	 * Verifies the signed download token and redirects the client to the file URL.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function download( \WP_REST_Request $req ) {
		try {
			$raw_token = sanitize_text_field( wp_unslash( $req->get_param( 'token' ) ) );

			if ( '' === $raw_token ) {
				return ResponseFactory::error( 'wplm_missing_token', __( 'Download token is required.', 'wp-license-manager' ), 400 );
			}

			/** @var Signer $signer */
			$signer  = $this->container->make( Signer::class );
			$payload = $signer->verify( $raw_token );

			if ( null === $payload ) {
				return ResponseFactory::error( 'wplm_invalid_token', __( 'Download token is invalid or has been tampered with.', 'wp-license-manager' ), 403 );
			}

			// Check expiry.
			if ( isset( $payload['exp'] ) && time() > (int) $payload['exp'] ) {
				return ResponseFactory::error( 'wplm_token_expired', __( 'Download token has expired.', 'wp-license-manager' ), 403 );
			}

			$release_id = isset( $payload['release_id'] ) ? absint( $payload['release_id'] ) : 0;
			if ( $release_id < 1 ) {
				return ResponseFactory::error( 'wplm_invalid_token', __( 'Download token is missing release reference.', 'wp-license-manager' ), 400 );
			}

			/** @var ReleaseRepository $release_repo */
			$release_repo = $this->container->make( ReleaseRepository::class );
			$release      = $release_repo->find_by_id( $release_id );

			if ( null === $release ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'Release not found.', 'wp-license-manager' ), 404 );
			}

			$file_path = $release->file_path ?? '';

			if ( '' === $file_path ) {
				return ResponseFactory::error( 'wplm_no_file', __( 'No download file is attached to this release.', 'wp-license-manager' ), 404 );
			}

			// Redirect the client to the actual file URL / path.
			header( 'Location: ' . esc_url_raw( $file_path ), true, 302 );
			exit;
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}
}
