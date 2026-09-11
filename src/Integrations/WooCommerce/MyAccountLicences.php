<?php
/**
 * My Account → Licenses: the customer's licences, their computers, and the Move button of an entitlement licence.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Admin\Flash;
use WPLM\Licensing\CheckInService;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\License;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\EntitlementService;

/**
 * A customer sees only their own licences. For an entitlement licence the detail view
 * shows what is paid for, the active computers, the moves left, and a **Move** button per computer: a
 * self-service move frees that computer so the licence can be activated on another, and counts against
 * the move limit (2 per 30 days) exactly like a move made from the app.
 */
class MyAccountLicences {

	/** admin-post action of the Move button. */
	public const MOVE_ACTION = 'wplm_move_machine';

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var MachineRepository */
	private MachineRepository $machines;

	/** @var CheckInService */
	private CheckInService $check_in;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	public function __construct( LicenseRepository $licenses, MachineRepository $machines, CheckInService $check_in, EntitlementService $entitlements, ProfileRegistry $profiles ) {
		$this->licenses     = $licenses;
		$this->machines     = $machines;
		$this->check_in     = $check_in;
		$this->entitlements = $entitlements;
		$this->profiles     = $profiles;
	}

	/** Hook the Move button's handler. */
	public function register(): void {
		add_action( 'admin_post_' . self::MOVE_ACTION, array( $this, 'handle_move' ) );
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	/**
	 * Move a computer off the customer's own entitlement licence.
	 *
	 * @param int      $user_id    The customer.
	 * @param int      $license_id Their licence.
	 * @param int      $machine_id One of its computers.
	 * @param int|null $now        Unix time (tests).
	 * @return array{ok: bool, message: string}
	 */
	public function move( int $user_id, int $license_id, int $machine_id, ?int $now = null ): array {
		$license = $this->own_licence( $user_id, $license_id );
		$machine = $this->machines->find_by_id( $machine_id );
		// A computer of another licence is refused by move_off() itself.
		if ( null === $license || null === $machine ) {
			return $this->result( false, __( 'That computer was not found on your licence.', 'wp-license-manager' ) );
		}
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return $this->result( false, __( 'This licence is moved from the software itself.', 'wp-license-manager' ) );
		}

		$moved = $this->check_in->move_off( $license, $machine, $now );
		if ( is_wp_error( $moved ) ) {
			$data  = (array) $moved->get_error_data();
			$reset = isset( $data['moves_reset_at'] ) ? strtotime( (string) $data['moves_reset_at'] ) : false;
			$text  = $moved->get_error_message();
			if ( false !== $reset ) {
				/* translators: %s: date */
				$text .= ' ' . sprintf( __( 'You can move it yourself again from %s.', 'wp-license-manager' ), wp_date( (string) get_option( 'date_format' ), $reset ) );
			}
			return $this->result( false, $text );
		}

		/* translators: %s: product name */
		return $this->result( true, sprintf( __( 'The computer was removed from your licence. Now activate %s on the new computer with your licence key.', 'wp-license-manager' ), $profile->label ) );
	}

	/** Handle the Move button (admin-post, logged-in customers only). */
	public function handle_move(): void {
		$license_id = absint( $_POST['license_id'] ?? 0 );
		$machine_id = absint( $_POST['machine_id'] ?? 0 );
		check_admin_referer( self::MOVE_ACTION . '_' . $machine_id );

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			Flash::set( $user_id, $this->move( $user_id, $license_id, $machine_id ) );
		}
		wp_safe_redirect( add_query_arg( 'view', $license_id, wc_get_account_endpoint_url( 'wplm-licenses' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * The customer's licences.
	 *
	 * @param int $user_id The customer.
	 * @return void
	 */
	public function render_list( int $user_id ): void {
		$result   = $this->licenses->get_list(
			array(
				'user_id'  => $user_id,
				'per_page' => 100,
			)
		);
		$licenses = $result['items'] ?? array();
		$today    = wp_date( 'Y-m-d' );
		$summary  = array();
		foreach ( $licenses as $license ) {
			$summary[ $license->id ] = $this->modules( $license, $today );
		}

		wc_get_template(
			'myaccount/wplm-licenses.php',
			array(
				'licenses' => $licenses,
				'modules'  => $summary,
			),
			'',
			WPLM_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * One licence: what it grants, its computers, and (entitlement licences) the Move buttons.
	 *
	 * @param int $user_id    The customer.
	 * @param int $license_id The licence to show; another customer's licence shows nothing of it.
	 * @return void
	 */
	public function render_view( int $user_id, int $license_id ): void {
		$license = $this->own_licence( $user_id, $license_id );
		$flash   = Flash::take( $user_id );

		wc_get_template(
			'myaccount/wplm-licence-computers.php',
			array(
				'license'     => $license,
				'profile'     => null !== $license ? $this->profiles->get( $license->profile ) : null,
				'modules'     => null !== $license ? $this->modules( $license, wp_date( 'Y-m-d' ) ) : array(),
				'machines'    => null !== $license ? $this->machines->get_by_license( $license->id, 1 ) : array(),
				'moves_left'  => null !== $license && null !== $license->profile ? $this->check_in->moves_left( $license ) : 0,
				'flash'       => $flash,
				'move_action' => self::MOVE_ACTION,
				'back_url'    => wc_get_account_endpoint_url( 'wplm-licenses' ),
			),
			'',
			WPLM_PLUGIN_DIR . 'templates/'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** The licence when it belongs to the customer, else null. */
	private function own_licence( int $user_id, int $license_id ): ?License {
		$license = $user_id > 0 ? $this->licenses->find_by_id( $license_id ) : null;
		return null !== $license && (int) $license->user_id === $user_id ? $license : null;
	}

	/**
	 * An entitlement licence's modules for display: label, paid-through date and state. Empty for a
	 * classic licence.
	 *
	 * @return array<string, array{label: string, until: string|null, state: string}>
	 */
	private function modules( License $license, string $today ): array {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return array();
		}
		$grace   = $profile->grace_days();
		$summary = EntitlementService::summarize_lines( $this->entitlements->lines( $license->id ), $profile, $today, $grace );
		$out     = array();
		foreach ( $summary['modules'] as $code => $module ) {
			$out[ $code ] = array(
				'label' => $profile->code_label( $code ),
				'until' => $module['until'],
				'state' => EntitlementService::module_state( $module['until'], $today, $grace ),
			);
		}
		return $out;
	}

	/** @return array{ok: bool, message: string} */
	private function result( bool $ok, string $message ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}
}
