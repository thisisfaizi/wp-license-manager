<?php
/**
 * REST server bootstrap — registers all WPLM routes and authentication.
 *
 * @package WPLM\Rest
 */

namespace WPLM\Rest;

defined( 'ABSPATH' ) || exit;

use WPLM\Container;
use WPLM\Repositories\ApiKeyRepository;
use WPLM\Rest\Controllers\AnalyticsController;
use WPLM\Rest\Controllers\ApiKeysController;
use WPLM\Rest\Controllers\GeneratorsController;
use WPLM\Rest\Controllers\LicensesController;
use WPLM\Rest\Controllers\MachinesController;
use WPLM\Rest\Controllers\ReleasesController;
use WPLM\Rest\Controllers\SubscriptionsController;
use WPLM\Rest\Controllers\ValidationController;
use WPLM\Rest\Controllers\WebhooksController;

/**
 * Instantiates all REST controllers, registers their routes, and wires up
 * HTTP Basic authentication for the wplm/v1 namespace.
 */
class RestServer {

	/** @var Container */
	private Container $container;

	/**
	 * @param Container $container The plugin DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register routes and the authentication filter.
	 *
	 * Intended to be called inside a `rest_api_init` action hook.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_routes();

		// Wire up HTTP Basic auth using the WPLM consumer-key scheme.
		$auth = new Auth\BasicAuth( $this->container->make( ApiKeyRepository::class ) );
		$auth->register();

		// Guarantee clean JSON: if the site runs with WP_DEBUG_DISPLAY on, a PHP
		// notice/deprecation emitted mid-request would be echoed into the body and
		// corrupt the response (clients then see "Unexpected response"). For our
		// own routes, stop errors from being *displayed* (they are still logged).
		add_filter( 'rest_pre_dispatch', array( $this, 'suppress_error_display' ), 10, 3 );
	}

	/**
	 * Disable PHP error *display* (not logging) for wplm/v1 REST requests so a
	 * stray notice can never corrupt the JSON response body.
	 *
	 * @param mixed            $result  Dispatch short-circuit result (passed through).
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed The unmodified $result.
	 */
	public function suppress_error_display( $result, $server, $request ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' )
			? (string) $request->get_route()
			: '';
		if ( 0 === strpos( $route, '/wplm/v1' ) ) {
			// Intentional: WP_DEBUG_DISPLAY is a wp-config constant set before this
			// plugin loads, so ini_set is the only runtime way to keep JSON clean.
			// Errors are still recorded when WP_DEBUG_LOG is enabled.
			@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors.Discouraged

			// $wpdb echoes DB errors via print_error() regardless of display_errors,
			// which would corrupt the JSON body. Suppress that for our routes too;
			// the failure still surfaces through the normal error handling.
			if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
				$GLOBALS['wpdb']->hide_errors();
			}
		}
		return $result;
	}

	/**
	 * Instantiate every controller and call its register() method.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		( new LicensesController( $this->container ) )->register();
		( new ValidationController( $this->container ) )->register();
		( new MachinesController( $this->container ) )->register();
		( new GeneratorsController( $this->container ) )->register();
		( new ReleasesController( $this->container ) )->register();
		( new SubscriptionsController( $this->container ) )->register();
		( new ApiKeysController( $this->container ) )->register();
		( new WebhooksController( $this->container ) )->register();
		( new AnalyticsController( $this->container ) )->register();
	}
}
