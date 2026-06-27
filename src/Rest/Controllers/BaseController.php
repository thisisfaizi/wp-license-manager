<?php
/**
 * Abstract base controller for all WPLM REST controllers.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Provides constructor injection of the DI container and shared permission
 * callbacks used by every concrete controller.
 */
abstract class BaseController {

	/** @var \WPLM\Container */
	protected \WPLM\Container $container;

	/**
	 * @param \WPLM\Container $container The plugin DI container.
	 */
	public function __construct( \WPLM\Container $container ) {
		$this->container = $container;
	}

	/**
	 * Each controller must register its own REST routes.
	 *
	 * @return void
	 */
	abstract public function register(): void;

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Require a valid API key with read permission.
	 *
	 * @param \WP_REST_Request $req Incoming request.
	 * @return bool
	 */
	public function require_read( \WP_REST_Request $req ): bool {
		$key = \WPLM\Rest\Auth\BasicAuth::get_current_api_key();
		if ( ! $key ) {
			return false;
		}
		return $this->filter_request_valid( $key->has_read_permission(), $req, 'read' );
	}

	/**
	 * Require a valid API key with write permission.
	 *
	 * @param \WP_REST_Request $req Incoming request.
	 * @return bool
	 */
	public function require_write( \WP_REST_Request $req ): bool {
		$key = \WPLM\Rest\Auth\BasicAuth::get_current_api_key();
		if ( ! $key ) {
			return false;
		}
		return $this->filter_request_valid( $key->has_write_permission(), $req, 'write' );
	}

	/**
	 * Let third parties inject custom REST request validation.
	 *
	 * The filter receives the current allow/deny decision and may veto an
	 * otherwise-authorised request (e.g. IP allow-listing, rate limiting).
	 *
	 * @param bool             $allowed Whether the request is currently allowed.
	 * @param \WP_REST_Request $req     Incoming request.
	 * @param string           $scope   'read' or 'write'.
	 * @return bool
	 */
	public function filter_request_valid( bool $allowed, \WP_REST_Request $req, string $scope ): bool {
		/**
		 * Filter the final validity decision for an authenticated REST request.
		 *
		 * @param bool             $allowed Current allow/deny decision.
		 * @param \WP_REST_Request $req     The REST request.
		 * @param string           $scope   The required scope: 'read' or 'write'.
		 */
		return (bool) apply_filters( 'wplm_rest_request_valid', $allowed, $req, $scope );
	}

	/**
	 * Public route — no authentication required.
	 *
	 * Used for validate / activate / deactivate / heartbeat / crl / public-key.
	 *
	 * @param \WP_REST_Request $req Incoming request.
	 * @return bool Always true.
	 */
	public function public_route( \WP_REST_Request $req ): bool {
		return true;
	}
}
