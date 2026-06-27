<?php
/**
 * HTTP Basic authentication handler for the WPLM REST API.
 *
 * @package WPLM\Rest\Auth
 */

namespace WPLM\Rest\Auth;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\ApiKey;
use WPLM\Repositories\ApiKeyRepository;
use WPLM\Support\Logger;

/**
 * Authenticates REST API requests via HTTP Basic auth using WPLM consumer keys.
 *
 * The consumer_key passed over the wire is hashed (SHA-256) and looked up in
 * wplm_api_keys. The consumer_secret is verified with hash_equals() against the
 * stored SHA-256 hash to prevent timing attacks.
 *
 * Behaviour:
 *   - No Authorization header → pass through (return $user unchanged).
 *   - Authorization: Basic present but invalid → return null (force unauthenticated).
 *   - Authorization: Basic present and valid → return the WP_User bound to the key.
 *
 * Register via:
 *   add_filter( 'determine_current_user', [ $auth, 'authenticate' ] );
 */
class BasicAuth {

	/** @var ApiKeyRepository */
	private ApiKeyRepository $api_key_repo;

	/**
	 * The API key that successfully authenticated the current request.
	 * Accessible via get_current_api_key() for permission callbacks.
	 *
	 * @var ApiKey|null
	 */
	private static ?ApiKey $current_api_key = null;

	/**
	 * @param ApiKeyRepository $repo Repository for looking up and touching API keys.
	 */
	public function __construct( ApiKeyRepository $repo ) {
		$this->api_key_repo = $repo;
	}

	/**
	 * Register the authentication filter with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'authenticate' ) );
	}

	/**
	 * Attempt to authenticate the request using HTTP Basic credentials.
	 *
	 * Hooked on 'determine_current_user'. Must return either:
	 *   - The resolved WP_User (authenticated)
	 *   - null (force unauthenticated — present but invalid credentials)
	 *   - $user unchanged (no Authorization header; defer to other auth methods)
	 *
	 * @param \WP_User|int|null $user Current user as resolved so far by WP.
	 * @return \WP_User|int|null
	 */
	public function authenticate( $user ) {
		$header = $this->get_authorization_header();

		// No Authorization header — pass through to let other auth mechanisms run.
		if ( null === $header ) {
			return $user;
		}

		// Header is present: strip the "Basic " prefix and base64-decode.
		if ( 0 !== strpos( $header, 'Basic ' ) ) {
			// Non-Basic scheme (e.g. Bearer) — not for us, pass through.
			return $user;
		}

		$encoded = substr( $header, 6 );
		$decoded = base64_decode( $encoded, true ); // strict mode.

		if ( false === $decoded || '' === $decoded ) {
			Logger::debug( 'BasicAuth: invalid base64 encoding in Authorization header.' );
			return null;
		}

		// Split on the first colon only — secrets may contain colons.
		$colon_pos = strpos( $decoded, ':' );

		if ( false === $colon_pos ) {
			Logger::debug( 'BasicAuth: no colon separator found in decoded credentials.' );
			return null;
		}

		$raw_key    = substr( $decoded, 0, $colon_pos );
		$raw_secret = substr( $decoded, $colon_pos + 1 );

		if ( '' === $raw_key || '' === $raw_secret ) {
			Logger::debug( 'BasicAuth: empty consumer_key or consumer_secret.' );
			return null;
		}

		// Hash the raw key for the DB lookup (stored value is hash('sha256', plaintext_key)).
		$hashed_key = hash( 'sha256', $raw_key );

		$api_key = $this->api_key_repo->find_by_consumer_key( $hashed_key );

		if ( null === $api_key ) {
			Logger::debug( 'BasicAuth: consumer_key not found.', array( 'hash_prefix' => substr( $hashed_key, 0, 8 ) ) );
			return null;
		}

		// Verify the secret with a timing-safe comparison.
		$hashed_secret = hash( 'sha256', $raw_secret );

		if ( ! hash_equals( $hashed_secret, $api_key->consumer_secret ) ) {
			Logger::debug( 'BasicAuth: consumer_secret mismatch.', array( 'api_key_id' => $api_key->id ) );
			return null;
		}

		// Credentials verified — update the last-access timestamp.
		$this->api_key_repo->touch_last_access( $api_key->id );

		// Store the key so permission callbacks can inspect its scope.
		self::$current_api_key = $api_key;

		Logger::debug(
			'BasicAuth: authenticated via API key.',
			array(
				'api_key_id' => $api_key->id,
				'user_id'    => $api_key->user_id,
			)
		);

		return get_user_by( 'id', $api_key->user_id );
	}

	/**
	 * Return the API key that authenticated the current request, if any.
	 *
	 * Use this in REST permission callbacks to check key scope:
	 *
	 *   $key = BasicAuth::get_current_api_key();
	 *   if ( $key && ! $key->has_write_permission() ) {
	 *       return new WP_Error( ... );
	 *   }
	 *
	 * @return ApiKey|null Null when the request was not authenticated via Basic auth.
	 */
	public static function get_current_api_key(): ?ApiKey {
		return self::$current_api_key;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the raw value of the HTTP Authorization header from the current
	 * request environment.
	 *
	 * PHP exposes this in different ways depending on the SAPI and web server:
	 *   1. $_SERVER['HTTP_AUTHORIZATION']        — Apache mod_php, most setups.
	 *   2. $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] — Apache with mod_rewrite.
	 *   3. getallheaders()['Authorization']       — when available (Apache/FPM).
	 *
	 * Returns null when no Authorization header is present at all.
	 *
	 * @return string|null Raw header value, or null if absent.
	 */
	private function get_authorization_header(): ?string {
		// Primary location.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) );
		}

		// Apache mod_rewrite sometimes places it here.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) );
		}

		// Fallback: use getallheaders() when available (works on Apache and some FPM configs).
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();

			// getallheaders() keys are case-folded differently across environments.
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( 'authorization', $name ) ) {
					return trim( $value );
				}
			}
		}

		return null;
	}
}
