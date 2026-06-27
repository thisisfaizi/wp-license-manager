<?php
/**
 * Front-end account-based license self-service: [wplm_license_manager].
 *
 * @package WPLM\Integrations
 */

namespace WPLM\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a logged-in customer's purchased licenses on any page via the
 * [wplm_license_manager] shortcode: every license tied to their account, with
 * status, expiry, seat usage, and bound devices — and a per-device Deactivate
 * button that frees a seat. Guests are prompted to log in.
 */
class LicenseShortcode {

	/** Register the shortcode and its form handler. */
	public function register(): void {
		add_shortcode( 'wplm_license_manager', array( $this, 'render' ) );
		add_action( 'admin_post_wplm_frontend_deactivate', array( $this, 'handle_deactivate' ) );
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'title' => __( 'My Licenses', 'wp-license-manager' ),
			),
			$atts,
			'wplm_license_manager'
		);

		ob_start();
		echo '<div class="wplm-license-manager" style="max-width:760px;margin:0 auto;">';
		echo '<h3>' . esc_html( $atts['title'] ) . '</h3>';

		if ( ! is_user_logged_in() ) {
			$this->render_login_prompt();
			echo '</div>';
			return (string) ob_get_clean();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash notice only.
		if ( isset( $_GET['wplm_done'] ) ) {
			$code   = sanitize_key( wp_unslash( $_GET['wplm_done'] ) );
			$notice = 'ok' === $code
				? __( 'Device deactivated — the seat is now free.', 'wp-license-manager' )
				: __( 'Could not deactivate that device. Please try again.', 'wp-license-manager' );
			$colour = 'ok' === $code ? '#00a32a' : '#d63638';
			$bg     = 'ok' === $code ? '#e6f4ea' : '#fcebea';
			echo '<p class="wplm-notice" style="padding:8px 12px;background:' . esc_attr( $bg )
				. ';border:1px solid ' . esc_attr( $colour ) . ';border-radius:4px;">'
				. esc_html( $notice ) . '</p>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_user_licenses( get_current_user_id() );

		echo '</div>';
		return (string) ob_get_clean();
	}

	/** Render a prompt asking the visitor to log in. */
	private function render_login_prompt(): void {
		$login_url = wp_login_url( get_permalink() ?: home_url() );
		echo '<p>' . esc_html__( 'Please log in to view and manage your licenses.', 'wp-license-manager' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $login_url ) . '">'
			. esc_html__( 'Log in', 'wp-license-manager' ) . '</a></p>';
	}

	/**
	 * List every license owned by the given user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return void
	 */
	private function render_user_licenses( int $user_id ): void {
		$result   = function_exists( 'wplm_get_licenses' )
			? wplm_get_licenses(
				array(
					'user_id'  => $user_id,
					'per_page' => 100,
					'orderby'  => 'created_at',
					'order'    => 'DESC',
				)
			)
			: array( 'items' => array() );
		$licenses = $result['items'] ?? array();

		if ( empty( $licenses ) ) {
			echo '<p>' . esc_html__( 'You have no licenses on this account yet.', 'wp-license-manager' ) . '</p>';
			return;
		}

		foreach ( $licenses as $license ) {
			$this->render_license_card( $license );
		}
	}

	/**
	 * Render a single license card with its devices.
	 *
	 * @param \WPLM\Models\License $license License model (plaintext key).
	 * @return void
	 */
	private function render_license_card( \WPLM\Models\License $license ): void {
		$statuses     = array(
			0 => __( 'Pending', 'wp-license-manager' ),
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Inactive', 'wp-license-manager' ),
			3 => __( 'Expired', 'wp-license-manager' ),
			4 => __( 'Suspended', 'wp-license-manager' ),
			5 => __( 'Revoked', 'wp-license-manager' ),
			6 => __( 'Terminated', 'wp-license-manager' ),
		);
		$status_label = $statuses[ (int) $license->status ] ?? (string) $license->status;
		$seats        = null !== $license->max_activations ? (int) $license->max_activations : '∞';
		$product_name = $license->product_id ? get_the_title( (int) $license->product_id ) : '';

		echo '<div class="wplm-license-card" style="border:1px solid #e2e2e2;border-radius:8px;padding:16px;margin-bottom:20px;">';

		if ( $product_name ) {
			echo '<h4 style="margin:0 0 8px;">' . esc_html( $product_name ) . '</h4>';
		}

		echo '<p style="font-family:monospace;font-size:15px;margin:0 0 12px;background:#f6f7f7;padding:8px;border-radius:4px;">'
			. esc_html( $license->license_key ) . '</p>';

		echo '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">';
		$this->row( __( 'Status', 'wp-license-manager' ), $status_label );
		$this->row( __( 'Seats used', 'wp-license-manager' ), $license->activation_count . ' / ' . $seats );
		if ( $license->expires_at ) {
			$this->row( __( 'Expires', 'wp-license-manager' ), $license->expires_at );
		}
		echo '</table>';

		$devices = function_exists( 'wplm_get_devices' ) ? wplm_get_devices( $license->license_key ) : array();
		$this->render_devices( $devices );

		echo '</div>';
	}

	/**
	 * Render the device list for a license (all statuses).
	 *
	 * @param \WPLM\Models\Machine[] $devices Device models.
	 * @return void
	 */
	private function render_devices( array $devices ): void {
		echo '<h5 style="margin:0 0 6px;">' . esc_html__( 'Devices', 'wp-license-manager' ) . '</h5>';

		if ( empty( $devices ) ) {
			echo '<p style="margin:0;color:#777;">' . esc_html__( 'No devices activated.', 'wp-license-manager' ) . '</p>';
			return;
		}

		$device_status = array(
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Deactivated', 'wp-license-manager' ),
			3 => __( 'Revoked', 'wp-license-manager' ),
		);

		echo '<table style="width:100%;border-collapse:collapse;">';
		foreach ( $devices as $device ) {
			$label = $device->name
				? $device->name
				: ( $device->hostname ? $device->hostname : substr( $device->fingerprint, 0, 12 ) . '…' );
			$state = $device_status[ (int) $device->status ] ?? (string) $device->status;

			echo '<tr style="border-bottom:1px solid #eee;">';
			echo '<td style="padding:8px 4px;">' . esc_html( $label );
			if ( $device->platform ) {
				echo ' <small style="color:#777;">(' . esc_html( $device->platform ) . ')</small>';
			}
			if ( $device->activated_at ) {
				echo '<br><small style="color:#999;">'
					/* translators: %s: activation date */
					. esc_html( sprintf( __( 'Activated %s', 'wp-license-manager' ), $device->activated_at ) )
					. '</small>';
			}
			echo '</td>';

			echo '<td style="padding:8px 4px;text-align:right;">';
			if ( 1 === (int) $device->status ) {
				$this->render_deactivate_button( (int) $device->id );
			} else {
				echo '<small style="color:#999;">' . esc_html( $state ) . '</small>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Render the per-device Deactivate form (submits the machine id).
	 *
	 * @param int $machine_id Machine row id.
	 * @return void
	 */
	private function render_deactivate_button( int $machine_id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="wplm_frontend_deactivate">
			<input type="hidden" name="machine_id" value="<?php echo esc_attr( (string) $machine_id ); ?>">
			<?php wp_nonce_field( 'wplm_frontend_deactivate_' . $machine_id ); ?>
			<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Deactivate this device?', 'wp-license-manager' ) ); ?>');">
				<?php esc_html_e( 'Deactivate', 'wp-license-manager' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Output a two-cell summary row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private function row( string $label, string $value ): void {
		echo '<tr style="border-bottom:1px solid #eee;">';
		echo '<th style="text-align:left;padding:8px 4px;width:40%;">' . esc_html( $label ) . '</th>';
		echo '<td style="padding:8px 4px;">' . esc_html( $value ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Handle a front-end "deactivate device" submission.
	 *
	 * Requires login, a valid nonce, and that the device's license belongs to
	 * the current user. Deactivates by machine id (no fingerprint re-hashing).
	 *
	 * @return void
	 */
	public function handle_deactivate(): void {
		$machine_id = isset( $_POST['machine_id'] ) ? absint( wp_unslash( $_POST['machine_id'] ) ) : 0;

		check_admin_referer( 'wplm_frontend_deactivate_' . $machine_id );

		$ok = $machine_id > 0 && is_user_logged_in() && $this->user_owns_machine( $machine_id, get_current_user_id() );
		if ( $ok ) {
			$result = wplm_deactivate_device_by_id( $machine_id );
			$ok     = ! is_wp_error( $result ) && false !== $result;
		}

		$redirect = wp_get_referer() ?: home_url();
		$redirect = remove_query_arg( array( 'wplm_done' ), $redirect );
		$redirect = add_query_arg( array( 'wplm_done' => $ok ? 'ok' : 'fail' ), $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Whether the given machine's license belongs to the given user.
	 *
	 * @param int $machine_id Machine row id.
	 * @param int $user_id    WordPress user id.
	 * @return bool
	 */
	private function user_owns_machine( int $machine_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$c            = \WPLM\Plugin::get_instance()->container();
		$machine_repo = $c->make( \WPLM\Repositories\MachineRepository::class );
		$machine      = $machine_repo->find_by_id( $machine_id );
		if ( ! $machine ) {
			return false;
		}
		$license_repo = $c->make( \WPLM\Repositories\LicenseRepository::class );
		$license      = $license_repo->find_by_id( $machine->license_id );

		return null !== $license && (int) $license->user_id === $user_id;
	}
}
