<?php
/**
 * API key service: issue and revoke the REST API's consumer key pairs.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Repositories\ApiKeyRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One place that mints consumer key pairs, for the REST route and the API Keys screen.
 *
 * Only SHA-256 hashes of the key and secret are stored, so the plain-text pair exists in the
 * result of create() and nowhere else. Results are `array{ok: bool, message: string}` plus any
 * extra the caller needs.
 */
class ApiKeyService {

	/** Scopes a key can carry. */
	public const PERMISSIONS = array( 'read', 'write', 'read_write' );

	/** Characters of the consumer key kept in plain text to tell keys apart (fits CHAR(7)). */
	public const TRUNCATED_LENGTH = 7;

	/** @var ApiKeyRepository */
	private ApiKeyRepository $repo;

	/**
	 * @param ApiKeyRepository $repo API key data-access layer.
	 */
	public function __construct( ApiKeyRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Issue a new key pair for a user.
	 *
	 * @param int    $user_id     The user the key acts as on WPLM routes.
	 * @param string $description What the key is for.
	 * @param string $permissions One of PERMISSIONS.
	 * @return array{ok: bool, message: string, key?: array}
	 */
	public function create( int $user_id, string $description, string $permissions ): array {
		if ( ! in_array( $permissions, self::PERMISSIONS, true ) ) {
			return $this->fail( __( 'Choose read, write or read and write.', 'wp-license-manager' ) );
		}
		if ( $user_id <= 0 || false === get_userdata( $user_id ) ) {
			return $this->fail( __( 'The key must belong to an existing user.', 'wp-license-manager' ) );
		}

		$raw_key     = 'ck_' . bin2hex( random_bytes( 20 ) );
		$raw_secret  = 'cs_' . bin2hex( random_bytes( 20 ) );
		$description = mb_substr( trim( $description ), 0, 191 );
		$truncated   = substr( $raw_key, -self::TRUNCATED_LENGTH );

		try {
			$id = $this->repo->create(
				array(
					'user_id'         => $user_id,
					'description'     => $description,
					'permissions'     => $permissions,
					'consumer_key'    => hash( 'sha256', $raw_key ),
					'consumer_secret' => hash( 'sha256', $raw_secret ),
					'truncated_key'   => $truncated,
				)
			);
		} catch ( \RuntimeException $e ) {
			return $this->fail( __( 'The API key could not be saved.', 'wp-license-manager' ) );
		}

		return array(
			'ok'      => true,
			'message' => __( 'API key created. Copy the secret now: it is not shown again.', 'wp-license-manager' ),
			'key'     => array(
				'id'              => $id,
				'consumer_key'    => $raw_key,
				'consumer_secret' => $raw_secret,
				'description'     => $description,
				'permissions'     => $permissions,
				'user_id'         => $user_id,
				'truncated_key'   => $truncated,
			),
		);
	}

	/**
	 * Revoke (delete) a key. Requests signed with it fail from then on.
	 *
	 * @param int $id Key id.
	 * @return array{ok: bool, message: string}
	 */
	public function revoke( int $id ): array {
		if ( $id <= 0 || ! $this->repo->delete( $id ) ) {
			return $this->fail( __( 'API key not found.', 'wp-license-manager' ) );
		}
		return array(
			'ok'      => true,
			'message' => __( 'API key revoked.', 'wp-license-manager' ),
		);
	}

	/** @return array{ok: false, message: string} */
	private function fail( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
		);
	}
}
