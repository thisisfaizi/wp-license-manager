<?php
/**
 * REST response factory — wraps all WPLM REST responses in the standard envelope.
 *
 * @package WPLM\Support
 */

namespace WPLM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper that wraps REST responses in the standard WPLM envelope.
 *
 * Success envelope:
 *   { "success": true, "data": ..., "meta": { ... } }
 *
 * Error responses use WP_Error so WordPress REST infrastructure converts
 * them to the correct HTTP status automatically.
 */
class ResponseFactory {

	/**
	 * Build a successful REST response wrapped in the WPLM envelope.
	 *
	 * @param mixed $data   The resource payload to include under the "data" key.
	 * @param array $meta   Optional metadata (e.g. pagination totals) for the "meta" key.
	 * @param int   $status HTTP status code (default 200).
	 * @return \WP_REST_Response
	 */
	public static function success( $data, array $meta = array(), int $status = 200 ): \WP_REST_Response {
		$body = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $meta,
		);

		return new \WP_REST_Response( $body, $status );
	}

	/**
	 * Build a WP_Error for REST error responses.
	 *
	 * The $extra array is merged into the error data so callers can pass
	 * additional context fields alongside the mandatory 'status' key.
	 *
	 * @param string $code    Machine-readable error code, e.g. 'wplm_not_found'.
	 * @param string $message Human-readable error message.
	 * @param int    $status  HTTP status code (default 400).
	 * @param array  $extra   Extra fields merged into the WP_Error data array.
	 * @return \WP_Error
	 */
	public static function error(
		string $code,
		string $message,
		int $status = 400,
		array $extra = array()
	): \WP_Error {
		$data = array_merge(
			array( 'status' => $status ),
			$extra
		);

		return new \WP_Error( $code, $message, $data );
	}
}
