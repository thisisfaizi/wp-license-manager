<?php
/**
 * Simple dependency-injection container.
 *
 * @package WPLM
 */

namespace WPLM;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight service container. Binds closures as factories and caches resolved
 * instances so each binding is only instantiated once (singleton semantics by default).
 */
class Container {

	/** @var array<string, callable> */
	private array $bindings = array();

	/** @var array<string, mixed> */
	private array $instances = array();

	/**
	 * Register a factory for an abstract identifier.
	 *
	 * @param string   $abstract The service identifier / class name.
	 * @param callable $factory  A closure that receives this container and returns the instance.
	 */
	public function bind( string $abstract, callable $factory ): void {
		$this->bindings[ $abstract ] = $factory;
		unset( $this->instances[ $abstract ] ); // invalidate cached instance on re-bind
	}

	/**
	 * Resolve a service by its abstract identifier. Instances are cached (singleton).
	 *
	 * @param string $abstract The service identifier.
	 * @return mixed
	 * @throws \RuntimeException When the abstract is not registered.
	 */
	public function make( string $abstract ) {
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}

		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			// Attempt to auto-resolve a concrete class with no dependencies.
			if ( class_exists( $abstract ) ) {
				$this->instances[ $abstract ] = new $abstract();
				return $this->instances[ $abstract ];
			}
			throw new \RuntimeException( "WPLM Container: no binding for [{$abstract}]." );
		}

		$this->instances[ $abstract ] = ( $this->bindings[ $abstract ] )( $this );

		return $this->instances[ $abstract ];
	}

	/**
	 * Check whether a binding or cached instance exists.
	 *
	 * @param string $abstract The service identifier.
	 */
	public function has( string $abstract ): bool {
		return isset( $this->bindings[ $abstract ] ) || isset( $this->instances[ $abstract ] );
	}

	/**
	 * Store an already-constructed instance directly.
	 *
	 * @param string $abstract The service identifier.
	 * @param mixed  $instance The concrete instance.
	 */
	public function instance( string $abstract, $instance ): void {
		$this->instances[ $abstract ] = $instance;
	}
}
