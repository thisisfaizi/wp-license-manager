<?php
/**
 * Generator model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a license key generator configuration from wplm_generators.
 */
class Generator {

	public int $id                       = 0;
	public string $name                  = '';
	public string $charset               = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	public int $chunks                   = 4;
	public int $chunk_length             = 4;
	public string $separator             = '-';
	public string $prefix                = '';
	public string $suffix                = '';
	public ?int $default_max_activations = null;
	public ?int $default_valid_days      = null;
	public string $created_at            = '';

	/**
	 * Create a Generator from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$g                          = new self();
		$g->id                      = (int) ( $row['id'] ?? 0 );
		$g->name                    = $row['name'] ?? '';
		$g->charset                 = $row['charset'] ?? 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$g->chunks                  = (int) ( $row['chunks'] ?? 4 );
		$g->chunk_length            = (int) ( $row['chunk_length'] ?? 4 );
		$g->separator               = $row['separator'] ?? '-';
		$g->prefix                  = $row['prefix'] ?? '';
		$g->suffix                  = $row['suffix'] ?? '';
		$g->default_max_activations = isset( $row['default_max_activations'] ) ? (int) $row['default_max_activations'] : null;
		$g->default_valid_days      = isset( $row['default_valid_days'] ) ? (int) $row['default_valid_days'] : null;
		$g->created_at              = $row['created_at'] ?? '';
		return $g;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'                      => $this->id,
			'name'                    => $this->name,
			'charset'                 => $this->charset,
			'chunks'                  => $this->chunks,
			'chunk_length'            => $this->chunk_length,
			'separator'               => $this->separator,
			'prefix'                  => $this->prefix,
			'suffix'                  => $this->suffix,
			'default_max_activations' => $this->default_max_activations,
			'default_valid_days'      => $this->default_valid_days,
			'created_at'              => $this->created_at,
		);
	}
}
