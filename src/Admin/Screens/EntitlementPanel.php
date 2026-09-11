<?php
/**
 * The entitlement licence panels on the licence edit screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Admin\Flash;
use WPLM\Admin\LicenceAdminActions;
use WPLM\Integrations\Qr\QrGenerator;
use WPLM\Licensing\CheckInService;
use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Entitlement;
use WPLM\Models\License;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\EntitlementService;

/**
 * Renders, for a licence with a profile (e.g. Super Ledger): what the office's token grants today,
 * the entitlement lines with add / edit / extend / remove, the computers with offline codes and the
 * move limit, the status with a required note, and the recent licence log.
 *
 * Every form posts to `admin-post.php?action=wplm_licence_action` with a `do` field; the handler in
 * Menu runs {@see LicenceAdminActions::dispatch()} and comes back here with the result.
 */
class EntitlementPanel {

	/** admin-post action every panel form uses. */
	public const ACTION = 'wplm_licence_action';

	/** Log events worth showing the owner, with their labels resolved at render time. */
	private const LOG_EVENTS = array( 'status_note', 'offline_code', 'lapse_notice', 'move', 'move_reset', 'revoke', 'activate', 'deactivate' );

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var MachineRepository */
	private MachineRepository $machines;

	/** @var CheckInService */
	private CheckInService $check_in;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log;

	public function __construct( EntitlementService $entitlements, ProfileRegistry $profiles, MachineRepository $machines, CheckInService $check_in, ActivationLogRepository $log ) {
		$this->entitlements = $entitlements;
		$this->profiles     = $profiles;
		$this->machines     = $machines;
		$this->check_in     = $check_in;
		$this->log          = $log;
	}

	// -------------------------------------------------------------------------
	// Results carried across the redirect
	// -------------------------------------------------------------------------

	/** Keep an action's result for the admin's next page view ({@see Flash}). */
	public static function flash( int $user_id, array $result ): void {
		Flash::set( $user_id, $result );
	}

	/** Take (and forget) the admin's pending result, or null ({@see Flash}). */
	public static function take_flash( int $user_id ): ?array {
		return Flash::take( $user_id );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render every panel for a licence. Does nothing for a classic licence.
	 *
	 * @param License    $license The licence.
	 * @param array|null $flash   The result of the action that led here ({@see take_flash()}); an
	 *                            offline code in it is shown once, beside the computers.
	 * @param int|null   $now     Unix time (tests).
	 * @return void
	 */
	public function render( License $license, ?array $flash = null, ?int $now = null ): void {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return;
		}

		$now      = $now ?? time();
		$today    = wp_date( 'Y-m-d', $now );
		$grace    = $profile->grace_days();
		$lines    = $this->entitlements->lines( $license->id );
		$summary  = EntitlementService::summarize_lines( $lines, $profile, $today, $grace );
		$machines = $this->machines->get_by_license( $license->id, 1 );
		?>
		<style>
			.wplm-ent h2 { margin-top: 2em; }
			.wplm-ent .wplm-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; line-height: 20px; background: #dcdcde; color: #1d2327; }
			.wplm-ent .wplm-badge.is-active, .wplm-ent .wplm-badge.is-lifetime { background: #d1e7dd; color: #0a3622; }
			.wplm-ent .wplm-badge.is-due_soon { background: #fff3cd; color: #664d03; }
			.wplm-ent .wplm-badge.is-grace { background: #ffe5d0; color: #7a3300; }
			.wplm-ent .wplm-badge.is-read_only { background: #f8d7da; color: #58151c; }
			.wplm-ent table.widefat td, .wplm-ent table.widefat th { vertical-align: middle; }
			.wplm-ent .wplm-inline { display: inline-flex; gap: 4px; align-items: center; flex-wrap: wrap; }
			.wplm-ent textarea.wplm-code { width: 100%; font-family: monospace; font-size: 12px; }
		</style>
		<div class="wplm-ent">
			<?php
			$this->render_grants( $profile, $summary, $today, $grace, $machines );
			$this->render_lines( $license, $profile, $lines, $today, $grace );
			$this->render_machines( $license, $profile, $machines, $flash, $now );
			$this->render_status( $license );
			$this->render_log( $license );
			?>
		</div>
		<?php
	}

	/** What the token grants today, per module and limit. */
	private function render_grants( Profile $profile, array $summary, string $today, int $grace, array $machines ): void {
		$usage = array();
		foreach ( $machines as $machine ) {
			foreach ( (array) $machine->usage as $code => $count ) {
				$usage[ $code ] = max( $usage[ $code ] ?? 0, (int) $count );
			}
		}
		?>
		<h2 id="wplm-grants"><?php echo esc_html( sprintf( /* translators: %s: product name */ __( '%s: what the office may use today', 'wp-license-manager' ), $profile->label ) ); ?></h2>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: grace days, 2: due-soon days */
					__( 'Each module works until its paid-through date, shows "due soon" %2$d days before it, keeps working for %1$d days of grace after it, then becomes read-only. The office learns changes at its next check-in.', 'wp-license-manager' ),
					$grace,
					EntitlementService::DUE_SOON_DAYS
				)
			);
			?>
		</p>
		<table class="widefat striped" style="max-width:720px">
			<thead><tr>
				<th><?php esc_html_e( 'Module', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Paid through', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'State today', 'wp-license-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( array() === $summary['modules'] ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No modules yet. Add a base line below, or the office cannot work.', 'wp-license-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $summary['modules'] as $code => $module ) : ?>
				<?php $state = EntitlementService::module_state( $module['until'], $today, $grace ); ?>
				<tr>
					<td><?php echo esc_html( $profile->code_label( $code ) ); ?></td>
					<td><?php echo esc_html( null === $module['until'] ? __( 'Lifetime', 'wp-license-manager' ) : $this->date( $module['until'] ) ); ?></td>
					<td><span class="wplm-badge is-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $this->state_label( $state ) ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! isset( $summary['modules']['base'] ) && array() !== $summary['modules'] ) : ?>
				<tr><td colspan="3"><strong><?php esc_html_e( 'No base line: the whole office is read-only whatever the other modules say.', 'wp-license-manager' ); ?></strong></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<table class="widefat striped" style="max-width:720px;margin-top:8px">
			<thead><tr>
				<th><?php esc_html_e( 'Limit', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Allowed', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'In use (last check-in)', 'wp-license-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $summary['limits'] as $code => $allowed ) : ?>
				<tr>
					<td><?php echo esc_html( $profile->code_label( $code ) ); ?></td>
					<td><?php echo esc_html( (string) $allowed ); ?></td>
					<td>
						<?php
						$used = $usage[ $code ] ?? null;
						echo esc_html( null === $used ? '—' : (string) $used );
						if ( null !== $used && $used > $allowed ) {
							echo ' <span class="wplm-badge is-read_only">' . esc_html__( 'over the limit', 'wp-license-manager' ) . '</span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** The lines, each editable, plus the add form. */
	private function render_lines( License $license, Profile $profile, array $lines, string $today, int $grace ): void {
		?>
		<h2 id="wplm-lines"><?php esc_html_e( 'Entitlement lines', 'wp-license-manager' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A module works until the latest paid-through date among its lines; a line with no date is lifetime. Limits add up across lines that are not past grace. Extend uses the renewal rule: inside grace it continues from the old date, after grace it starts today.', 'wp-license-manager' ); ?></p>
		<?php foreach ( $lines as $line ) : ?>
			<form id="wplm-line-<?php echo esc_attr( (string) $line->id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden( $license, 'line_save', array( 'line_id' => $line->id ) ); ?>
			</form>
			<form id="wplm-line-extend-<?php echo esc_attr( (string) $line->id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden( $license, 'line_extend', array( 'line_id' => $line->id ) ); ?>
			</form>
			<form id="wplm-line-delete-<?php echo esc_attr( (string) $line->id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this line? The office loses what it grants at its next check-in. Its records are never deleted.', 'wp-license-manager' ) ); ?>');">
				<?php $this->hidden( $license, 'line_delete', array( 'line_id' => $line->id ) ); ?>
			</form>
		<?php endforeach; ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Grants', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Quantity', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Paid through (blank = lifetime)', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'State', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Source', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-license-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( array() === $lines ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No lines.', 'wp-license-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $lines as $line ) : ?>
				<?php
				$form   = 'wplm-line-' . $line->id;
				$state  = EntitlementService::module_state( $line->paid_through, $today, $grace );
				$source = $line->source;
				if ( null !== $line->subscription_id ) {
					/* translators: %d: subscription id */
					$source .= ' · ' . sprintf( __( 'subscription #%d', 'wp-license-manager' ), $line->subscription_id );
				}
				?>
				<tr>
					<td>
						<?php
						echo esc_html( $profile->code_label( $line->code ) );
						echo ' <span class="description">(' . esc_html( Entitlement::KIND_MODULE === $line->kind ? __( 'module', 'wp-license-manager' ) : __( 'limit', 'wp-license-manager' ) ) . ')</span>';
						?>
					</td>
					<td>
						<?php if ( Entitlement::KIND_LIMIT === $line->kind ) : ?>
							<input type="number" min="1" step="1" class="small-text" name="qty" form="<?php echo esc_attr( $form ); ?>" value="<?php echo esc_attr( (string) $line->qty ); ?>" aria-label="<?php esc_attr_e( 'Quantity', 'wp-license-manager' ); ?>">
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<span class="wplm-inline">
							<input type="date" name="paid_through" form="<?php echo esc_attr( $form ); ?>" value="<?php echo esc_attr( (string) $line->paid_through ); ?>" aria-label="<?php esc_attr_e( 'Paid through', 'wp-license-manager' ); ?>">
							<button type="submit" class="button" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Save', 'wp-license-manager' ); ?></button>
						</span>
					</td>
					<td><span class="wplm-badge is-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $this->state_label( $state ) ); ?></span></td>
					<td><?php echo esc_html( $source ); ?></td>
					<td>
						<span class="wplm-inline">
							<?php if ( null !== $line->paid_through ) : ?>
								<input type="number" min="1" max="<?php echo esc_attr( (string) LicenceAdminActions::MAX_EXTEND_MONTHS ); ?>" step="1" value="1" class="small-text" name="months" form="wplm-line-extend-<?php echo esc_attr( (string) $line->id ); ?>" aria-label="<?php esc_attr_e( 'Months', 'wp-license-manager' ); ?>">
								<button type="submit" class="button" form="wplm-line-extend-<?php echo esc_attr( (string) $line->id ); ?>"><?php esc_html_e( 'Extend months', 'wp-license-manager' ); ?></button>
							<?php endif; ?>
							<button type="submit" class="button-link-delete" form="wplm-line-delete-<?php echo esc_attr( (string) $line->id ); ?>"><?php esc_html_e( 'Remove', 'wp-license-manager' ); ?></button>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Add a line', 'wp-license-manager' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wplm-inline">
			<?php $this->hidden( $license, 'line_save' ); ?>
			<select name="code" required aria-label="<?php esc_attr_e( 'Module or limit', 'wp-license-manager' ); ?>">
				<option value=""><?php esc_html_e( '— Module or limit —', 'wp-license-manager' ); ?></option>
				<optgroup label="<?php esc_attr_e( 'Modules', 'wp-license-manager' ); ?>">
					<?php foreach ( $profile->module_codes as $code ) : ?>
						<option value="<?php echo esc_attr( 'module:' . $code ); ?>"><?php echo esc_html( $profile->code_label( $code ) ); ?></option>
					<?php endforeach; ?>
				</optgroup>
				<optgroup label="<?php esc_attr_e( 'Limits', 'wp-license-manager' ); ?>">
					<?php foreach ( $profile->limit_codes as $code ) : ?>
						<option value="<?php echo esc_attr( 'limit:' . $code ); ?>"><?php echo esc_html( $profile->code_label( $code ) ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			</select>
			<label><?php esc_html_e( 'Quantity (limits)', 'wp-license-manager' ); ?> <input type="number" name="qty" min="1" step="1" class="small-text"></label>
			<label><?php esc_html_e( 'Paid through', 'wp-license-manager' ); ?> <input type="date" name="paid_through"></label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add line', 'wp-license-manager' ); ?></button>
			<span class="description"><?php esc_html_e( 'Leave the date blank for lifetime.', 'wp-license-manager' ); ?></span>
		</form>
		<?php
	}

	/** The licence's computers, their offline codes and the move limit. */
	private function render_machines( License $license, Profile $profile, array $machines, ?array $flash, int $now ): void {
		?>
		<h2 id="wplm-machines"><?php esc_html_e( 'Computers', 'wp-license-manager' ); ?></h2>
		<?php if ( null !== $flash && ! empty( $flash['token'] ) ) : ?>
			<div class="notice notice-info inline" style="padding:12px">
				<p><strong><?php esc_html_e( 'Offline renewal code', 'wp-license-manager' ); ?></strong> — <?php echo esc_html( (string) $flash['message'] ); ?></p>
				<p><?php esc_html_e( 'Send it to the customer (for example over WhatsApp). They paste it into Super Ledger on that computer. It works only on that computer, and never extends what is paid for. It is shown once.', 'wp-license-manager' ); ?></p>
				<textarea id="wplm-offline-code" class="wplm-code" rows="4" readonly><?php echo esc_textarea( (string) $flash['token'] ); ?></textarea>
				<p>
					<button type="button" class="button" onclick="(function(b){var t=document.getElementById('wplm-offline-code');t.select();(navigator.clipboard?navigator.clipboard.writeText(t.value):Promise.reject()).then(function(){b.textContent=b.dataset.done;},function(){document.execCommand('copy');b.textContent=b.dataset.done;});})(this)" data-done="<?php esc_attr_e( 'Copied', 'wp-license-manager' ); ?>"><?php esc_html_e( 'Copy code', 'wp-license-manager' ); ?></button>
				</p>
				<?php if ( class_exists( '\Endroid\QrCode\QrCode' ) || has_filter( 'wplm_qr_url' ) ) : ?>
					<p><?php echo ( new QrGenerator() )->img_tag( (string) $flash['token'], 240, __( 'Offline renewal code', 'wp-license-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- img_tag() escapes. ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Computer', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Last check-in', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'App version', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Usage reported', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Offline renewal code', 'wp-license-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( array() === $machines ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No active computer. The customer activates Super Ledger on the office computer with the licence key.', 'wp-license-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $machines as $machine ) : ?>
				<tr>
					<td>
						<?php echo esc_html( $machine->name ?: ( $machine->hostname ?: sprintf( /* translators: %d: machine id */ __( 'Computer #%d', 'wp-license-manager' ), $machine->id ) ) ); ?>
						<br><span class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Activated %s', 'wp-license-manager' ), $this->datetime( $machine->activated_at, wp_timezone_string() ) ) ); ?></span>
					</td>
					<td>
						<?php
						// Activation is the first check-in. last_heartbeat_at is UTC; activated_at is site time.
						echo esc_html( $machine->last_heartbeat_at ? $this->datetime( $machine->last_heartbeat_at, 'UTC' ) : $this->datetime( $machine->activated_at, wp_timezone_string() ) );
						?>
					</td>
					<td><?php echo esc_html( $machine->app_version ?: '—' ); ?></td>
					<td>
						<?php
						if ( null === $machine->usage ) {
							echo '—';
						} else {
							$parts = array();
							foreach ( $machine->usage as $code => $count ) {
								$parts[] = $profile->code_label( (string) $code ) . ' ' . (int) $count;
							}
							echo esc_html( implode( ', ', $parts ) );
						}
						?>
					</td>
					<td>
						<?php if ( null === $machine->token_fp ) : ?>
							<span class="description"><?php esc_html_e( 'Available after this computer checks in once.', 'wp-license-manager' ); ?></span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wplm-inline">
								<?php $this->hidden( $license, 'offline_code', array( 'machine_id' => $machine->id ) ); ?>
								<label><?php esc_html_e( 'Days', 'wp-license-manager' ); ?> <input type="number" name="days" value="30" min="1" max="365" step="1" class="small-text"></label>
								<button type="submit" class="button"><?php esc_html_e( 'Issue code', 'wp-license-manager' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php $left = $this->check_in->moves_left( $license, $now ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wplm-inline" style="margin-top:8px">
			<?php $this->hidden( $license, 'reset_moves' ); ?>
			<span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: moves left */
						_n( 'The customer can move the licence to another computer %d more time in the next 30 days.', 'The customer can move the licence to another computer %d more times in the next 30 days.', $left, 'wp-license-manager' ),
						$left
					)
				);
				?>
			</span>
			<button type="submit" class="button"><?php esc_html_e( 'Reset moves', 'wp-license-manager' ); ?></button>
		</form>
		<?php
	}

	/** Suspend, reinstate or revoke, with a note. */
	private function render_status( License $license ): void {
		$labels = array(
			0 => __( 'Pending', 'wp-license-manager' ),
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Inactive (no computer yet)', 'wp-license-manager' ),
			3 => __( 'Expired', 'wp-license-manager' ),
			4 => __( 'Suspended', 'wp-license-manager' ),
			5 => __( 'Revoked', 'wp-license-manager' ),
			6 => __( 'Terminated', 'wp-license-manager' ),
		);
		$locked = in_array( $license->status, array( 4, 5 ), true );
		?>
		<h2 id="wplm-status"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></h2>
		<p>
			<?php
			/* translators: %s: status */
			echo esc_html( sprintf( __( 'This licence is %s.', 'wp-license-manager' ), $labels[ $license->status ] ?? (string) $license->status ) );
			echo ' ';
			esc_html_e( 'Nothing locks a customer automatically: an unpaid module goes read-only on its own after grace. Suspend is your manual lock; revoke is for fraud and chargebacks.', 'wp-license-manager' );
			?>
		</p>
		<?php if ( 6 === $license->status ) : ?>
			<p><?php esc_html_e( 'A terminated licence cannot be changed.', 'wp-license-manager' ); ?></p>
		<?php elseif ( $locked ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden( $license, 'status', array( 'status_action' => 'reinstate' ) ); ?>
				<p><label for="wplm-note-reinstate"><?php esc_html_e( 'Note (optional)', 'wp-license-manager' ); ?></label><br>
				<textarea id="wplm-note-reinstate" name="note" rows="2" class="large-text"></textarea></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Reinstate', 'wp-license-manager' ); ?></button>
				<span class="description"><?php esc_html_e( 'The office unlocks at its next check-in. A revoked licence must be activated again on each computer.', 'wp-license-manager' ); ?></span>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden( $license, 'status', array( 'status_action' => 'suspend' ) ); ?>
				<p><label for="wplm-note-suspend"><?php esc_html_e( 'Why are you suspending it? (required, kept in the log)', 'wp-license-manager' ); ?></label><br>
				<textarea id="wplm-note-suspend" name="note" rows="2" class="large-text" required></textarea></p>
				<button type="submit" class="button"><?php esc_html_e( 'Suspend', 'wp-license-manager' ); ?></button>
				<span class="description"><?php esc_html_e( 'The office becomes read-only at its next check-in. Nothing is deleted.', 'wp-license-manager' ); ?></span>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this licence? Every computer is deactivated and the customer gets no further tokens.', 'wp-license-manager' ) ); ?>');">
				<?php $this->hidden( $license, 'status', array( 'status_action' => 'revoke' ) ); ?>
				<p><label for="wplm-note-revoke"><?php esc_html_e( 'Why are you revoking it? (required, kept in the log)', 'wp-license-manager' ); ?></label><br>
				<textarea id="wplm-note-revoke" name="note" rows="2" class="large-text" required></textarea></p>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke', 'wp-license-manager' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/** Recent licence events the owner cares about. */
	private function render_log( License $license ): void {
		$events = array_values( array_filter( $this->log->get_by_license( $license->id, 100 ), static fn( $e ) => in_array( $e->event, self::LOG_EVENTS, true ) ) );
		$events = array_slice( $events, 0, 20 );
		?>
		<h2 id="wplm-log"><?php esc_html_e( 'Licence log', 'wp-license-manager' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'What', 'wp-license-manager' ); ?></th>
				<th><?php esc_html_e( 'Details', 'wp-license-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( array() === $events ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'Nothing yet.', 'wp-license-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $events as $event ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $event->created_at ); ?></td>
					<td><?php echo esc_html( $event->event . ( 'success' !== $event->result ? ' (' . $event->result . ')' : '' ) ); ?></td>
					<td><?php echo esc_html( $this->describe( $event->event, (array) $event->meta ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Hidden fields every panel form carries. */
	private function hidden( License $license, string $form, array $extra = array() ): void {
		// Printed by hand: wp_nonce_field() gives every form the same element id.
		printf( '<input type="hidden" name="_wplm_nonce" value="%s">', esc_attr( wp_create_nonce( self::ACTION . '_' . $license->id ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION ) );
		printf( '<input type="hidden" name="license_id" value="%d">', (int) $license->id );
		printf( '<input type="hidden" name="do" value="%s">', esc_attr( $form ) );
		foreach ( $extra as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
	}

	/** A one-line summary of a log event's meta. */
	private function describe( string $event, array $meta ): string {
		$who = isset( $meta['user_id'] ) && $meta['user_id'] ? get_userdata( (int) $meta['user_id'] ) : false;
		$by  = $who ? ' — ' . $who->display_name : '';
		switch ( $event ) {
			case 'status_note':
				return ucfirst( (string) ( $meta['action'] ?? '' ) ) . ( '' !== (string) ( $meta['note'] ?? '' ) ? ': ' . $meta['note'] : '' ) . $by;
			case 'offline_code':
				/* translators: 1: days, 2: date */
				return sprintf( __( '%1$d days, works offline until %2$s', 'wp-license-manager' ), (int) ( $meta['days'] ?? 0 ), isset( $meta['check_in_by'] ) ? wp_date( 'Y-m-d H:i', (int) $meta['check_in_by'] ) : '?' ) . $by;
			case 'lapse_notice':
				/* translators: 1: modules, 2: date, 3: address */
				return sprintf( __( '%1$s read-only from %2$s, emailed to %3$s', 'wp-license-manager' ), implode( ', ', (array) ( $meta['modules'] ?? array() ) ), (string) ( $meta['read_only_on'] ?? '' ), (string) ( $meta['to'] ?? '—' ) );
			default:
				return $by ? ltrim( $by, ' —' ) : '';
		}
	}

	/** Label for a module state. */
	private function state_label( string $state ): string {
		$labels = array(
			EntitlementService::STATE_ACTIVE    => __( 'Active', 'wp-license-manager' ),
			EntitlementService::STATE_DUE_SOON  => __( 'Due soon', 'wp-license-manager' ),
			EntitlementService::STATE_GRACE     => __( 'Grace', 'wp-license-manager' ),
			EntitlementService::STATE_READ_ONLY => __( 'Read-only', 'wp-license-manager' ),
			EntitlementService::STATE_LIFETIME  => __( 'Lifetime', 'wp-license-manager' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/** A Y-m-d date in the site's format. */
	private function date( string $ymd ): string {
		return wp_date( (string) get_option( 'date_format' ), ( new \DateTimeImmutable( $ymd . ' 12:00:00', wp_timezone() ) )->getTimestamp() );
	}

	/**
	 * A stored datetime in the site's date and time format.
	 *
	 * @param string $value    Y-m-d H:i:s.
	 * @param string $timezone The time zone it was stored in.
	 */
	private function datetime( string $value, string $timezone ): string {
		try {
			$at = new \DateTimeImmutable( $value, new \DateTimeZone( $timezone ) );
		} catch ( \Exception $e ) {
			return '—';
		}
		return '' === $value ? '—' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at->getTimestamp() );
	}
}
