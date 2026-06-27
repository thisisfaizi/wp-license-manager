<?php
/**
 * Generator service — license key string generation and generator CRUD.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Crypto\Fingerprint;
use WPLM\Models\Generator;
use WPLM\Repositories\GeneratorRepository;
use WPLM\Repositories\LicenseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles all business logic for license key generators:
 * building key strings, batch generation, and generator CRUD operations.
 */
class GeneratorService {

	/** @var GeneratorRepository */
	private GeneratorRepository $gen_repo;

	/** @var LicenseRepository */
	private LicenseRepository $license_repo;

	/**
	 * @param GeneratorRepository $gen_repo     Generator data-access layer.
	 * @param LicenseRepository   $license_repo License data-access layer (used for uniqueness checks).
	 */
	public function __construct(
		GeneratorRepository $gen_repo,
		LicenseRepository $license_repo
	) {
		$this->gen_repo     = $gen_repo;
		$this->license_repo = $license_repo;
	}

	// -------------------------------------------------------------------------
	// Key string building
	// -------------------------------------------------------------------------

	/**
	 * Build a raw key string from a Generator configuration.
	 *
	 * Steps:
	 *  1. Build $generator->chunks segments of $generator->chunk_length random chars
	 *     drawn from $generator->charset using random_int().
	 *  2. Join segments with $generator->separator.
	 *  3. Prepend $generator->prefix and append $generator->suffix when non-empty.
	 *  4. Pass through the wplm_generated_key_string filter.
	 *
	 * @param Generator $generator Generator configuration model.
	 * @return string The final key string.
	 */
	public function generate_key_string( Generator $generator ): string {
		$charset     = $generator->charset;
		$charset_len = strlen( $charset );
		$segments    = array();

		for ( $s = 0; $s < $generator->chunks; $s++ ) {
			$segment = '';
			for ( $c = 0; $c < $generator->chunk_length; $c++ ) {
				$segment .= $charset[ random_int( 0, $charset_len - 1 ) ];
			}
			$segments[] = $segment;
		}

		$key = implode( $generator->separator, $segments );

		if ( '' !== $generator->prefix ) {
			$key = $generator->prefix . $key;
		}

		if ( '' !== $generator->suffix ) {
			$key = $key . $generator->suffix;
		}

		/**
		 * Filter the generated key string before it is returned.
		 *
		 * @param string    $key       The generated key string.
		 * @param Generator $generator The generator configuration.
		 */
		$key = (string) apply_filters( 'wplm_generated_key_string', $key, $generator );

		return $key;
	}

	// -------------------------------------------------------------------------
	// Batch generation
	// -------------------------------------------------------------------------

	/**
	 * Generate a batch of unique key strings (or license records) from a generator.
	 *
	 * Uniqueness is verified by computing the SHA-256 hash of each candidate and
	 * checking the database; up to 5 retries per slot before throwing.
	 *
	 * @param int           $generator_id     ID of the generator to use.
	 * @param int           $count            Number of keys to produce.
	 * @param array         $license_defaults Extra fields to merge into each license (passed to $create_fn).
	 * @param callable|null $create_fn        Optional callback: fn( string $key_string, Generator $generator, array $defaults ) : mixed.
	 *                                        When null, only the key strings are returned.
	 * @return array Array of key strings (when $create_fn is null) or whatever $create_fn returns.
	 * @throws \InvalidArgumentException When the generator is not found.
	 * @throws \RuntimeException         When a unique key cannot be produced after max retries.
	 */
	public function generate_batch(
		int $generator_id,
		int $count,
		array $license_defaults = array(),
		?callable $create_fn = null
	): array {
		$generator = $this->gen_repo->find_by_id( $generator_id );

		if ( null === $generator ) {
			throw new \InvalidArgumentException(
				sprintf( 'WPLM GeneratorService: generator with ID %d not found.', $generator_id )
			);
		}

		$results     = array();
		$max_retries = 5;

		for ( $i = 0; $i < $count; $i++ ) {
			$key_string = null;
			$attempts   = 0;

			while ( $attempts < $max_retries ) {
				$candidate = $this->generate_key_string( $generator );
				$hash      = Fingerprint::sha256( $candidate );

				$existing = $this->license_repo->find_by_hash( $hash );
				if ( null === $existing ) {
					$key_string = $candidate;
					break;
				}

				++$attempts;
			}

			if ( null === $key_string ) {
				throw new \RuntimeException(
					sprintf(
						'WPLM GeneratorService: could not generate a unique key after %d attempts (slot %d).',
						$max_retries,
						$i + 1
					)
				);
			}

			if ( null === $create_fn ) {
				$results[] = $key_string;
			} else {
				$results[] = $create_fn( $key_string, $generator, $license_defaults );
			}
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Generator CRUD
	// -------------------------------------------------------------------------

	/**
	 * Load a single generator by primary key.
	 *
	 * @param int $id Row id.
	 * @return Generator|null
	 */
	public function get_generator( int $id ): ?Generator {
		return $this->gen_repo->find_by_id( $id );
	}

	/**
	 * Return all generators, ordered by name.
	 *
	 * @return Generator[]
	 */
	public function get_all(): array {
		return $this->gen_repo->get_all();
	}

	/**
	 * Create a new generator.
	 *
	 * @param array $data Column/value pairs. 'name' is required.
	 * @return Generator The newly created generator model.
	 * @throws \InvalidArgumentException When 'name' is missing or empty.
	 * @throws \RuntimeException         On database insertion failure (from repository).
	 */
	public function create_generator( array $data ): Generator {
		$name = trim( $data['name'] ?? '' );

		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'WPLM GeneratorService: generator name is required.' );
		}

		$data['name'] = $name;

		$id = $this->gen_repo->create( $data );

		$generator = $this->gen_repo->find_by_id( $id );

		if ( null === $generator ) {
			throw new \RuntimeException(
				sprintf( 'WPLM GeneratorService: could not reload generator after INSERT (id %d).', $id )
			);
		}

		return $generator;
	}

	/**
	 * Update an existing generator.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column/value pairs to update.
	 * @return bool True when at least one row was affected.
	 */
	public function update_generator( int $id, array $data ): bool {
		return $this->gen_repo->update( $id, $data );
	}

	/**
	 * Delete a generator by primary key.
	 *
	 * @param int $id Row id.
	 * @return bool True when the row was deleted.
	 */
	public function delete_generator( int $id ): bool {
		return $this->gen_repo->delete( $id );
	}
}
