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
