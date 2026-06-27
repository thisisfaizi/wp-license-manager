<?php
/**
 * REST controller for analytics and anomaly-detection data.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\AnalyticsService;
use WPLM\Support\ResponseFactory;

/**
 * Exposes dashboard analytics and anomaly detection over the REST API.
 * All endpoints are read-scoped.
 */
class AnalyticsController extends BaseController {

	const NAMESPACE = 'wplm/v1';

	/**
	 * Register analytics routes.
	 *
	 * @return void
	 */
	public function register(): void {
		// Overview stats (dashboard widgets).
		register_rest_route(
			self::NAMESPACE,
			'/analytics/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_overview' ),
				'permission_callback' => array( $this, 'require_read' ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => array( '7d', '30d', '90d' ),
						'default' => '30d',
					),
				),
			)
		);

		// Anomaly detection results.
		register_rest_route(
			self::NAMESPACE,
			'/analytics/anomalies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_anomalies' ),
				'permission_callback' => array( $this, 'require_read' ),
			)
		);

		// Validation time-series.
		register_rest_route(
			self::NAMESPACE,
			'/analytics/validations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_validations' ),
				'permission_callback' => array( $this, 'require_read' ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => array( '7d', '30d', '90d' ),
						'default' => '7d',
					),
				),
			)
		);

		// Expiring-soon licenses.
		register_rest_route(
			self::NAMESPACE,
			'/analytics/expiring',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_expiring' ),
				'permission_callback' => array( $this, 'require_read' ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 90,
					),
				),
			)
		);
	}

	/**
	 * GET /analytics/overview
	 *
	 * Returns KPI summary cached for 10 minutes.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_overview( \WP_REST_Request $req ) {
		$period = sanitize_text_field( wp_unslash( $req->get_param( 'period' ) ) );

		/** @var AnalyticsService $service */
		$service  = $this->container->make( AnalyticsService::class );
		$overview = $service->get_overview( $period );

		return ResponseFactory::success( $overview );
	}

	/**
	 * GET /analytics/anomalies
	 *
	 * Returns current anomaly flags.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_anomalies( \WP_REST_Request $req ) {
		/** @var AnalyticsService $service */
		$service   = $this->container->make( AnalyticsService::class );
		$anomalies = $service->detect_anomalies();

		return ResponseFactory::success( array( 'anomalies' => $anomalies ) );
	}

	/**
	 * GET /analytics/validations
	 *
	 * Returns a daily time-series of validate call counts.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_validations( \WP_REST_Request $req ) {
		$period = sanitize_text_field( wp_unslash( $req->get_param( 'period' ) ) );
		$days   = (int) rtrim( $period, 'd' );

		/** @var AnalyticsService $service */
		$service = $this->container->make( AnalyticsService::class );
		$series  = $service->get_validation_series( $days );

		return ResponseFactory::success(
			array(
				'series' => $series,
				'period' => $period,
			)
		);
	}

	/**
	 * GET /analytics/expiring
	 *
	 * Returns licenses expiring within the next N days.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_expiring( \WP_REST_Request $req ) {
		$days = absint( $req->get_param( 'days' ) ) ?: 30;

		/** @var AnalyticsService $service */
		$service  = $this->container->make( AnalyticsService::class );
		$expiring = $service->get_expiring_soon( $days );

		return ResponseFactory::success(
			array(
				'licenses' => array_map( fn( $l ) => $l->to_array(), $expiring ),
				'days'     => $days,
			)
		);
	}
}
