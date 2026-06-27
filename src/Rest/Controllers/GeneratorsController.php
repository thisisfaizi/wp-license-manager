<?php
/**
 * REST controller for generator CRUD and bulk generation.
 *
 * @package WPLM\Rest\Controllers
 */

namespace WPLM\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Support\ResponseFactory;

/**
 * Handles all /generators endpoints.
 */
class GeneratorsController extends BaseController {

	/** Base REST namespace. */
	const NAMESPACE = 'wplm/v1';

	/**
	 * Register all generator routes with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		// Collection.
		register_rest_route(
			self::NAMESPACE,
			'/generators',
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

		// Single item.
		register_rest_route(
			self::NAMESPACE,
			'/generators/(?P<id>\d+)',
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

		// Bulk key / license generation.
		register_rest_route(
			self::NAMESPACE,
			'/generators/(?P<id>\d+)/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'require_write' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /generators
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $req ) {
		try {
			/** @var GeneratorService $service */
			$service    = $this->container->make( GeneratorService::class );
			$generators = $service->get_all();

			return ResponseFactory::success(
				array( 'generators' => array_map( fn( $g ) => $g->to_array(), $generators ) ),
				array( 'total' => count( $generators ) )
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /generators
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $req ) {
		try {
			$body = $req->get_json_params() ?: array();
			$data = $this->sanitize_generator_data( $body );

			/** @var GeneratorService $service */
			$service   = $this->container->make( GeneratorService::class );
			$generator = $service->create_generator( $data );

			return ResponseFactory::success( $generator->to_array(), array(), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return ResponseFactory::error( 'wplm_invalid_argument', $e->getMessage(), 400 );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /generators/{id}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $req ) {
		try {
			$id = absint( $req->get_param( 'id' ) );

			/** @var GeneratorService $service */
			$service   = $this->container->make( GeneratorService::class );
			$generator = $service->get_generator( $id );

			if ( null === $generator ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'Generator not found.', 'wp-license-manager' ), 404 );
			}

			return ResponseFactory::success( $generator->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * PUT /generators/{id}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $req ) {
		try {
			$id   = absint( $req->get_param( 'id' ) );
			$body = $req->get_json_params() ?: array();

			/** @var GeneratorService $service */
			$service   = $this->container->make( GeneratorService::class );
			$generator = $service->get_generator( $id );

			if ( null === $generator ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'Generator not found.', 'wp-license-manager' ), 404 );
			}

			$data = $this->sanitize_generator_data( $body );
			$service->update_generator( $id, $data );

			// Return the updated generator.
			$updated = $service->get_generator( $id );

			return ResponseFactory::success( $updated ? $updated->to_array() : $generator->to_array() );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * DELETE /generators/{id}
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $req ) {
		try {
			$id = absint( $req->get_param( 'id' ) );

			/** @var GeneratorService $service */
			$service   = $this->container->make( GeneratorService::class );
			$generator = $service->get_generator( $id );

			if ( null === $generator ) {
				return ResponseFactory::error( 'wplm_not_found', __( 'Generator not found.', 'wp-license-manager' ), 404 );
			}

			$deleted = $service->delete_generator( $id );

			if ( ! $deleted ) {
				return ResponseFactory::error( 'wplm_delete_failed', __( 'Failed to delete generator.', 'wp-license-manager' ), 500 );
			}

			return ResponseFactory::success(
				array(
					'deleted' => true,
					'id'      => $id,
				)
			);
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	/**
	 * POST /generators/{id}/generate
	 *
	 * Bulk-generates license keys (or full license records) from a generator.
	 *
	 * @param \WP_REST_Request $req Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( \WP_REST_Request $req ) {
		try {
			$id   = absint( $req->get_param( 'id' ) );
			$body = $req->get_json_params() ?: array();

			$count    = isset( $body['count'] ) ? absint( $body['count'] ) : 1;
			$defaults = isset( $body['license_defaults'] ) && is_array( $body['license_defaults'] )
				? $body['license_defaults']
				: array();

			// Clamp count to 1–1000.
			$count = max( 1, min( 1000, $count ) );

			/** @var GeneratorService $gen_service */
			$gen_service = $this->container->make( GeneratorService::class );

			/** @var LicenseService $license_service */
			$license_service = $this->container->make( LicenseService::class );

			// Inline create callback — generates and persists a full license record.
			$create_fn = function ( string $key_string, $generator, array $license_defaults ) use ( $license_service ): array {
				$args               = $license_defaults;
				$args['key_string'] = $key_string;
				$args['source']     = 1; // generator
				$license            = $license_service->create( $args );
				return $license->to_array( true );
			};

			$results = $gen_service->generate_batch( $id, $count, $defaults, $create_fn );

			return ResponseFactory::success(
				array( 'licenses' => $results ),
				array( 'total' => count( $results ) )
			);
		} catch ( \InvalidArgumentException $e ) {
			return ResponseFactory::error( 'wplm_invalid_argument', $e->getMessage(), 400 );
		} catch ( \RuntimeException $e ) {
			return ResponseFactory::error( 'wplm_server_error', $e->getMessage(), 500 );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize generator fields from a request body.
	 *
	 * @param array $body Raw body array.
	 * @return array Sanitized data.
	 */
	private function sanitize_generator_data( array $body ): array {
		$data = array();

		if ( isset( $body['name'] ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( $body['name'] ) );
		}
		if ( isset( $body['charset'] ) ) {
			$data['charset'] = sanitize_text_field( wp_unslash( $body['charset'] ) );
		}
		if ( isset( $body['chunks'] ) ) {
			$data['chunks'] = absint( $body['chunks'] );
		}
		if ( isset( $body['chunk_length'] ) ) {
			$data['chunk_length'] = absint( $body['chunk_length'] );
		}
		if ( isset( $body['separator'] ) ) {
			$data['separator'] = sanitize_text_field( wp_unslash( $body['separator'] ) );
		}
		if ( isset( $body['prefix'] ) ) {
			$data['prefix'] = sanitize_text_field( wp_unslash( $body['prefix'] ) );
		}
		if ( isset( $body['suffix'] ) ) {
			$data['suffix'] = sanitize_text_field( wp_unslash( $body['suffix'] ) );
		}
		if ( isset( $body['expires_in_days'] ) ) {
			$data['expires_in_days'] = absint( $body['expires_in_days'] );
		}
		if ( isset( $body['max_activations'] ) ) {
			$data['max_activations'] = absint( $body['max_activations'] );
		}
		if ( isset( $body['is_floating'] ) ) {
			$data['is_floating'] = (int) (bool) $body['is_floating'];
		}

		return $data;
	}
}
