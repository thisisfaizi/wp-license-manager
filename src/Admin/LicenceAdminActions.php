<?php
/**
 * The owner's actions on the licence screen, as plain calls the admin-post handlers make.
 *
 * @package WPLM\Admin
 */

namespace WPLM\Admin;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\CheckInService;
use WPLM\Licensing\OfflineCodeService;
use WPLM\Models\Entitlement;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Services\RevocationService;

/**
 * Every decision the licence screen makes lives here, so it is tested without HTTP; the handlers in
 * Menu only check the nonce and capability, call one method and redirect with its message.
 *
 * Each method takes the licence the screen is showing and refuses a line or machine that belongs to
 * another licence, so a tampered id cannot reach someone else's data. Results are
 * `array{ok: bool, message: string}` plus any extra the caller needs.
 */
class LicenceAdminActions {

	/** Activation-log event carrying the owner's note on a status change. */
	public const EVENT_STATUS_NOTE = 'status_note';

	/** Most months one Extend adds. */
	public const MAX_EXTEND_MONTHS = 36;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var EntitlementRepository */
	private EntitlementRepository $lines;

	/** @var LicenseService */
	private LicenseService $licence_service;

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var MachineRepository */
	private MachineRepository $machines;

	/** @var RevocationService */
	private RevocationService $revocation;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log;

	/** @var OfflineCodeService */
	private OfflineCodeService $offline_codes;

	/** @var CheckInService */
	private CheckInService $check_in;

	/** @var GeneratorService */
	private GeneratorService $generators;

	public function __construct(
		EntitlementService $entitlements,
		EntitlementRepository $lines,
		LicenseService $licence_service,
		LicenseRepository $licenses,
		MachineRepository $machines,
		RevocationService $revocation,
		ActivationLogRepository $log,
		OfflineCodeService $offline_codes,
		CheckInService $check_in,
		GeneratorService $generators
	) {
		$this->entitlements    = $entitlements;
		$this->lines           = $lines;
		$this->licence_service = $licence_service;
		$this->licenses        = $licenses;
		$this->machines        = $machines;
		$this->revocation      = $revocation;
		$this->log             = $log;
		$this->offline_codes   = $offline_codes;
		$this->check_in        = $check_in;
		$this->generators      = $generators;
	}

	/** Forms on the entitlement licence screen, by their `do` field. */
	public const FORMS = array( 'line_save', 'line_extend', 'line_delete', 'status', 'offline_code', 'reset_moves' );

	/**
	 * Run one form posted from the entitlement licence screen.
	 *
	 * @param array $post    Unslashed form fields: `license_id`, `do`, and the form's own fields.
	 * @param int   $user_id The admin.
	 * @return array{ok: bool, message: string}
	 */
	public function dispatch( array $post, int $user_id ): array {
		$license_id = absint( $post['license_id'] ?? 0 );
		$do         = (string) ( $post['do'] ?? '' );

		if ( ! in_array( $do, self::FORMS, true ) ) {
			return $this->fail( __( 'Unknown action.', 'wp-license-manager' ) );
		}
		if ( null === $this->licenses->find_by_id( $license_id ) ) {
			return $this->fail( __( 'That licence no longer exists.', 'wp-license-manager' ) );
		}

		switch ( $do ) {
			case 'line_save':
				return $this->save_line( $license_id, $post );
			case 'line_extend':
				return $this->extend_line( $license_id, absint( $post['line_id'] ?? 0 ), absint( $post['months'] ?? 0 ) );
			case 'line_delete':
				return $this->delete_line( $license_id, absint( $post['line_id'] ?? 0 ) );
			case 'status':
				return $this->change_status( $license_id, (string) ( $post['status_action'] ?? '' ), (string) ( $post['note'] ?? '' ), $user_id );
			case 'offline_code':
				return $this->offline_code( $license_id, absint( $post['machine_id'] ?? 0 ), (int) ( $post['days'] ?? 0 ), $user_id );
			default:
				return $this->reset_moves( $license_id, $user_id );
		}
	}

	// -------------------------------------------------------------------------
	// The licence form
	// -------------------------------------------------------------------------

	/**
	 * Create or update a licence from the edit form.
	 *
	 * On create, a blank key is generated with the chosen generator (else the default one), and
	 * `profile` makes it an entitlement licence. On update, `profile` is only changed when posted.
	 *
	 * @param array $post Unslashed form fields.
	 * @return array{ok: bool, message: string, license_id: int}
	 */
	public function save_licence( array $post ): array {
		$license_id = absint( $post['license_id'] ?? 0 );
		$args       = array();

		foreach ( array( 'product_id', 'order_id', 'user_id', 'valid_for_days' ) as $field ) {
			if ( array_key_exists( $field, $post ) ) {
				$args[ $field ] = absint( $post[ $field ] ) ?: null;
			}
		}
		if ( array_key_exists( 'max_activations', $post ) ) {
			$args['max_activations'] = max( 1, absint( $post['max_activations'] ) );
		}
		if ( array_key_exists( 'expires_at', $post ) ) {
			$args['expires_at'] = sanitize_text_field( (string) $post['expires_at'] ) ?: null;
		}
		if ( array_key_exists( 'grace_days', $post ) ) {
			$args['grace_days'] = absint( $post['grace_days'] );
		}
		if ( array_key_exists( 'overage_strategy', $post ) ) {
			$args['overage_strategy'] = sanitize_text_field( (string) $post['overage_strategy'] );
		}
		if ( $license_id > 0 || array_key_exists( 'is_floating', $post ) ) {
			$args['is_floating'] = ! empty( $post['is_floating'] );
		}
		if ( array_key_exists( 'profile', $post ) ) {
			$args['profile'] = sanitize_key( (string) $post['profile'] ) ?: null;
		}
		$key = sanitize_text_field( (string) ( $post['key_string'] ?? '' ) );

		try {
			if ( $license_id > 0 ) {
				if ( '' !== $key ) {
					$args['key_string'] = $key;
				}
				if ( ! $this->licence_service->update( $license_id, $args ) ) {
					return $this->fail( __( 'That licence no longer exists.', 'wp-license-manager' ), array( 'license_id' => $license_id ) );
				}
				return $this->done( __( 'Licence saved.', 'wp-license-manager' ), array( 'license_id' => $license_id ) );
			}

			if ( '' === $key ) {
				$generator_id = absint( $post['generator_id'] ?? 0 ) ?: (int) get_option( 'wplm_default_generator_id', 0 );
				if ( $generator_id <= 0 ) {
					return $this->fail( __( 'Type a licence key, or choose a key generator.', 'wp-license-manager' ), array( 'license_id' => 0 ) );
				}
				$key = (string) ( $this->generators->generate_batch( $generator_id, 1 )[0] ?? '' );
			}
			$args['key_string'] = $key;
			$created            = $this->licence_service->create( $args );
			return $this->done( __( 'Licence created.', 'wp-license-manager' ), array( 'license_id' => $created->id ) );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			return $this->fail( $e->getMessage(), array( 'license_id' => $license_id ) );
		}
	}

	// -------------------------------------------------------------------------
	// Entitlement lines
	// -------------------------------------------------------------------------

	/**
	 * Add a line (`code` = "kind:code") or edit one (`line_id`: its quantity and paid-through date).
	 * A blank paid-through date means lifetime.
	 *
	 * @param int   $license_id The licence on screen.
	 * @param array $input      Unslashed form fields.
	 * @return array{ok: bool, message: string}
	 */
	public function save_line( int $license_id, array $input ): array {
		$line_id = absint( $input['line_id'] ?? 0 );

		try {
			if ( $line_id > 0 ) {
				$owned = $this->owned_line( $license_id, $line_id );
				if ( null === $owned ) {
					return $this->not_this_licence();
				}
				$changes = array();
				if ( array_key_exists( 'paid_through', $input ) ) {
					$changes['paid_through'] = trim( (string) $input['paid_through'] );
				}
				if ( array_key_exists( 'qty', $input ) && Entitlement::KIND_LIMIT === $owned->kind ) {
					$changes['qty'] = trim( (string) $input['qty'] );
				}
				$this->entitlements->update_line( $line_id, $changes );
				return $this->done( __( 'Line saved.', 'wp-license-manager' ) );
			}

			$parts = explode( ':', (string) ( $input['code'] ?? '' ), 2 );
			if ( 2 !== count( $parts ) ) {
				return $this->fail( __( 'Choose a module or limit.', 'wp-license-manager' ) );
			}
			$this->entitlements->add_line(
				$license_id,
				array(
					'kind'         => $parts[0],
					'code'         => $parts[1],
					'qty'          => trim( (string) ( $input['qty'] ?? '' ) ),
					'paid_through' => trim( (string) ( $input['paid_through'] ?? '' ) ),
					'source'       => 'manual',
				)
			);
			return $this->done( __( 'Line added.', 'wp-license-manager' ) );
		} catch ( \InvalidArgumentException $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	/**
	 * Extend a dated line by whole months, with the renewal rule (inside grace from the old end, after
	 * it from today).
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function extend_line( int $license_id, int $line_id, int $months, ?int $now = null ): array {
		if ( $months < 1 || $months > self::MAX_EXTEND_MONTHS ) {
			/* translators: %d: maximum months */
			return $this->fail( sprintf( __( 'Extend by 1 to %d months.', 'wp-license-manager' ), self::MAX_EXTEND_MONTHS ) );
		}
		if ( null === $this->owned_line( $license_id, $line_id ) ) {
			return $this->not_this_licence();
		}

		try {
			$line = $this->entitlements->extend_line( $line_id, $months, 'month', $now );
		} catch ( \InvalidArgumentException $e ) {
			return $this->fail( $e->getMessage() );
		}
		/* translators: %s: date */
		return $this->done( sprintf( __( 'Paid through %s.', 'wp-license-manager' ), (string) $line->paid_through ) );
	}

	/**
	 * Remove a line.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function delete_line( int $license_id, int $line_id ): array {
		if ( null === $this->owned_line( $license_id, $line_id ) ) {
			return $this->not_this_licence();
		}
		$this->entitlements->delete_line( $line_id );
		return $this->done( __( 'Line removed.', 'wp-license-manager' ) );
	}

	// -------------------------------------------------------------------------
	// Status (D7: locking is the owner's action, with a note)
	// -------------------------------------------------------------------------

	/**
	 * Suspend, reinstate or revoke a licence. Suspending and revoking need a note; every change is
	 * logged with the note and who made it.
	 *
	 * @param int    $license_id The licence.
	 * @param string $action     suspend|reinstate|revoke.
	 * @param string $note       The owner's reason.
	 * @param int    $user_id    The admin.
	 * @return array{ok: bool, message: string}
	 */
	public function change_status( int $license_id, string $action, string $note, int $user_id ): array {
		$messages = array(
			'suspend'   => __( 'Licence suspended. Its computers become read-only at their next check-in.', 'wp-license-manager' ),
			'reinstate' => __( 'Licence reinstated. Its computers unlock at their next check-in.', 'wp-license-manager' ),
			'revoke'    => __( 'Licence revoked. Its computers were deactivated.', 'wp-license-manager' ),
		);
		if ( ! isset( $messages[ $action ] ) ) {
			return $this->fail( __( 'Unknown action.', 'wp-license-manager' ) );
		}

		$note = trim( sanitize_textarea_field( $note ) );
		if ( '' === $note && 'reinstate' !== $action ) {
			return $this->fail( __( 'Write a note saying why. It is kept in the licence log.', 'wp-license-manager' ) );
		}

		try {
			if ( 'suspend' === $action ) {
				$this->revocation->suspend_license( $license_id );
			} elseif ( 'revoke' === $action ) {
				$this->revocation->revoke_license( $license_id );
			} else {
				$this->revocation->reinstate_license( $license_id );
			}
		} catch ( \RuntimeException $e ) {
			return $this->fail( $e->getMessage() );
		}

		$this->log->create(
			array(
				'license_id' => $license_id,
				'event'      => self::EVENT_STATUS_NOTE,
				'result'     => 'success',
				'meta'       => array(
					'action'  => $action,
					'note'    => $note,
					'user_id' => $user_id,
				),
			)
		);

		return $this->done( $messages[ $action ] );
	}

	/**
	 * Bulk suspend or revoke from the licence list. Entitlement licences are skipped: they are
	 * locked from their own screen, where a note is required.
	 *
	 * @param int[]  $ids    Licence ids.
	 * @param string $action suspend|revoke.
	 * @return array{changed: int, skipped: int}
	 */
	public function bulk_status( array $ids, string $action ): array {
		$changed = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$license = $this->licenses->find_by_id( (int) $id );
			if ( null === $license ) {
				continue;
			}
			if ( null !== $license->profile ) {
				++$skipped;
				continue;
			}
			try {
				if ( 'revoke' === $action ) {
					$this->revocation->revoke_license( $license->id );
				} else {
					$this->revocation->suspend_license( $license->id );
				}
				++$changed;
			} catch ( \RuntimeException $e ) {
				unset( $e ); // A licence that cannot transition (e.g. terminated) is left as it is.
			}
		}
		return array(
			'changed' => $changed,
			'skipped' => $skipped,
		);
	}

	// -------------------------------------------------------------------------
	// Machines
	// -------------------------------------------------------------------------

	/**
	 * Issue an offline renewal code for one of the licence's computers.
	 *
	 * @return array{ok: bool, message: string, token?: string, check_in_by?: int, machine_id?: int}
	 */
	public function offline_code( int $license_id, int $machine_id, int $days, int $user_id, ?int $now = null ): array {
		$machine = $this->machines->find_by_id( $machine_id );
		if ( null === $machine || $machine->license_id !== $license_id ) {
			return $this->fail( __( 'That computer is not on this licence.', 'wp-license-manager' ) );
		}

		$code = $this->offline_codes->issue( $machine_id, $days, $user_id, $now );
		if ( is_wp_error( $code ) ) {
			return $this->fail( $code->get_error_message() );
		}

		return $this->done(
			/* translators: %s: date */
			sprintf( __( 'Offline code issued. The computer works without checking in until %s.', 'wp-license-manager' ), wp_date( (string) get_option( 'date_format' ), $code['check_in_by'] ) ),
			array(
				'token'       => $code['token'],
				'check_in_by' => $code['check_in_by'],
				'machine_id'  => $machine_id,
			)
		);
	}

	/**
	 * Let the customer move the licence to another computer again.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function reset_moves( int $license_id, int $user_id ): array {
		$license = $this->licenses->find_by_id( $license_id );
		if ( null === $license || null === $license->profile ) {
			return $this->fail( __( 'Only an entitlement licence has a move limit.', 'wp-license-manager' ) );
		}
		$this->check_in->reset_moves( $license, $user_id );
		return $this->done( __( 'Moves reset. The customer can move the licence again.', 'wp-license-manager' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** A line that belongs to the licence, or null. */
	private function owned_line( int $license_id, int $line_id ): ?Entitlement {
		$line = $this->lines->find_by_id( $line_id );
		return null !== $line && $line->license_id === $license_id ? $line : null;
	}

	/** @return array{ok: false, message: string} */
	private function not_this_licence(): array {
		return $this->fail( __( 'That line is not on this licence.', 'wp-license-manager' ) );
	}

	/** @return array{ok: true, message: string} */
	private function done( string $message, array $extra = array() ): array {
		return array(
			'ok'      => true,
			'message' => $message,
		) + $extra;
	}

	/** @return array{ok: false, message: string} */
	private function fail( string $message, array $extra = array() ): array {
		return array(
			'ok'      => false,
			'message' => $message,
		) + $extra;
	}
}
