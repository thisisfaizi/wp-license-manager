<?php
/**
 * Plan service — business logic for subscription plans and their packages.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Models\Plan;
use WPLM\Repositories\PackageRepository;
use WPLM\Repositories\PlanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Owns plan + package lifecycle. A plan is a reusable offering assigned to any
 * native WooCommerce product (via _wplm_plan_id meta); a package is a tier the
 * customer chooses at purchase.
 */
class PlanService {

	/** @var PlanRepository */
	private PlanRepository $plan_repo;

	/** @var PackageRepository */
	private PackageRepository $package_repo;

	/**
	 * @param PlanRepository    $plan_repo    Plan data-access layer.
	 * @param PackageRepository $package_repo Package data-access layer.
	 */
	public function __construct( PlanRepository $plan_repo, PackageRepository $package_repo ) {
		$this->plan_repo    = $plan_repo;
		$this->package_repo = $package_repo;
	}

	/**
	 * Return all plans (with packages loaded).
	 *
	 * @param bool $active_only When true, only active plans.
	 * @return Plan[]
	 */
	public function get_all( bool $active_only = false ): array {
		$plans = $this->plan_repo->get_all( $active_only );
		foreach ( $plans as $plan ) {
			$plan->packages = $this->package_repo->get_by_plan( $plan->id, $active_only );
		}
		return $plans;
	}

	/**
	 * Fetch a single plan with its packages.
	 *
	 * @param int  $id          Plan id.
	 * @param bool $active_only When true, only active packages are loaded.
	 * @return Plan|null
	 */
	public function get( int $id, bool $active_only = false ): ?Plan {
		$plan = $this->plan_repo->find_by_id( $id, false );
		if ( null === $plan ) {
			return null;
		}
		$plan->packages = $this->package_repo->get_by_plan( $id, $active_only );
		return $plan;
	}

	/**
	 * Create a plan.
	 *
	 * @param array $data name, description, status.
	 * @return int New plan id.
	 * @throws \InvalidArgumentException When name is empty.
	 */
	public function create_plan( array $data ): int {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'WPLM PlanService: plan name is required.' );
		}
		return $this->plan_repo->create(
			array(
				'name'        => $name,
				'description' => isset( $data['description'] ) ? (string) $data['description'] : null,
				'status'      => isset( $data['status'] ) ? (int) $data['status'] : 1,
			)
		);
	}

	/**
	 * Update a plan's core fields.
	 *
	 * @param int   $id   Plan id.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public function update_plan( int $id, array $data ): bool {
		$update = array();
		if ( isset( $data['name'] ) ) {
			$update['name'] = trim( (string) $data['name'] );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$update['description'] = null !== $data['description'] ? (string) $data['description'] : null;
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = (int) $data['status'];
		}
		return empty( $update ) ? false : $this->plan_repo->update( $id, $update );
	}

	/**
	 * Delete a plan and its packages.
	 *
	 * @param int $id Plan id.
	 * @return bool
	 */
	public function delete_plan( int $id ): bool {
		return $this->plan_repo->delete( $id );
	}

	/**
	 * Replace a plan's packages with the supplied set (upsert + prune).
	 *
	 * Each entry may carry an 'id' to update an existing package; rows without
	 * a matching id are inserted, and existing packages absent from the input
	 * are deleted. Package ids are preserved so historical references stay valid.
	 *
	 * @param int   $plan_id  Plan id.
	 * @param array $packages Array of normalized package field arrays.
	 * @return void
	 */
	public function sync_packages( int $plan_id, array $packages ): void {
		$existing     = $this->package_repo->get_by_plan( $plan_id );
		$existing_ids = array_map( static fn( $p ) => $p->id, $existing );
		$kept_ids     = array();
		$order        = 0;

		foreach ( $packages as $pkg ) {
			$data               = $this->normalize_package( $pkg, $plan_id, $order );
			$incoming_id        = (int) ( $pkg['id'] ?? 0 );
			++$order;

			if ( $incoming_id > 0 && in_array( $incoming_id, $existing_ids, true ) ) {
				$this->package_repo->update( $incoming_id, $data );
				$kept_ids[] = $incoming_id;
			} else {
				$kept_ids[] = $this->package_repo->create( $data );
			}
		}

		foreach ( $existing_ids as $eid ) {
			if ( ! in_array( $eid, $kept_ids, true ) ) {
				$this->package_repo->delete( $eid );
			}
		}
	}

	/**
	 * Normalize a raw package input array into a storable row.
	 *
	 * @param array $pkg     Raw package fields.
	 * @param int   $plan_id Owning plan id.
	 * @param int   $order   Sort order.
	 * @return array
	 */
	private function normalize_package( array $pkg, int $plan_id, int $order ): array {
		$allowed_periods = array( 'day', 'week', 'month', 'year' );
		$allowed_types   = array( 'recurring', 'lifetime', 'onetime' );
		$type            = in_array( $pkg['billing_type'] ?? '', $allowed_types, true ) ? $pkg['billing_type'] : 'recurring';
		$period          = in_array( $pkg['billing_period'] ?? '', $allowed_periods, true ) ? $pkg['billing_period'] : 'month';

		$benefits = $pkg['benefits'] ?? array();
		if ( is_string( $benefits ) ) {
			$benefits = array_values( array_filter( array_map( 'trim', explode( "\n", $benefits ) ) ) );
		}

		return array(
			'plan_id'          => $plan_id,
			'name'             => trim( (string) ( $pkg['name'] ?? '' ) ),
			'billing_type'     => $type,
			'billing_period'   => $period,
			'billing_interval' => max( 1, (int) ( $pkg['billing_interval'] ?? 1 ) ),
			'price'            => (float) ( $pkg['price'] ?? 0 ),
			'signup_fee'       => (float) ( $pkg['signup_fee'] ?? 0 ),
			'trial_days'       => max( 0, (int) ( $pkg['trial_days'] ?? 0 ) ),
			'length_cycles'    => max( 0, (int) ( $pkg['length_cycles'] ?? 0 ) ),
			'generator_id'     => ! empty( $pkg['generator_id'] ) ? (int) $pkg['generator_id'] : null,
			'max_activations'  => isset( $pkg['max_activations'] ) && '' !== $pkg['max_activations'] ? (int) $pkg['max_activations'] : null,
			'overage_strategy' => (string) ( $pkg['overage_strategy'] ?? 'deny' ),
			'valid_for_days'   => isset( $pkg['valid_for_days'] ) && '' !== $pkg['valid_for_days'] ? (int) $pkg['valid_for_days'] : null,
			'grace_days'       => isset( $pkg['grace_days'] ) && '' !== $pkg['grace_days'] ? max( 0, (int) $pkg['grace_days'] ) : null,
			'benefits'         => array_values( (array) $benefits ),
			'sort_order'       => $order,
			'status'           => isset( $pkg['status'] ) ? (int) $pkg['status'] : 1,
		);
	}
}
