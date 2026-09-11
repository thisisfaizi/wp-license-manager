<?php
/**
 * Settings page registration and rendering for the WPLM plugin.
 *
 * @package WPLM\Admin\Settings
 */

namespace WPLM\Admin\Settings;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;

/**
 * Registers all WPLM settings sections and fields via the WordPress Settings API,
 * and renders the Settings admin page.
 *
 * Settings group : wplm_settings
 * Page slug      : wplm-settings  (used by do_settings_sections)
 */
class SettingsPage {

	/** Largest check-in window or grace a licence profile accepts, in days. */
	public const MAX_PROFILE_DAYS = 60;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/**
	 * @param ProfileRegistry|null $profiles Licence profiles whose timing is configured here.
	 */
	public function __construct( ?ProfileRegistry $profiles = null ) {
		$this->profiles = $profiles ?? new ProfileRegistry();
	}

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Default values for every registered option.
	 *
	 * @var array<string, mixed>
	 */
	private array $defaults = array(
		'wplm_default_max_activations'  => 1,
		'wplm_order_complete_status'    => 'completed',
		'wplm_hide_key_in_email'        => 0,
		'wplm_qr_enabled'               => 0,
		'wplm_force_ssl'                => 0,
		'wplm_default_overage_strategy' => 'deny',
		'wplm_heartbeat_interval'       => 300,
		'wplm_zombie_window'            => 600,
		'wplm_telemetry_retention_days' => 90,
		'wplm_sub_engine_enabled'       => 0,
		'wplm_default_grace_days'       => 7,
		'wplm_dunning_schedule'         => '1,3,5',
		'wplm_webhook_retry_attempts'   => 3,
	);

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Hook settings registration into admin_init.
	 *
	 * Call this method from Plugin::boot() or Menu::register_menus().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// -------------------------------------------------------------------------
	// Settings API registration
	// -------------------------------------------------------------------------

	/**
	 * Register option groups, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {

		// ------------------------------------------------------------------
		// Single register_setting() call for the whole group.
		// The sanitize callback validates and sanitizes every field at once.
		// ------------------------------------------------------------------
		register_setting(
			'wplm_settings',
			'wplm_settings',
			array(
				'sanitize_callback' => array( $this, 'validate_settings' ),
				'default'           => $this->defaults,
			)
		);

		// ------------------------------------------------------------------
		// Also register each option individually so get_option() works
		// with their individual option names (flat storage pattern).
		// ------------------------------------------------------------------
		$number_fields = array(
			'wplm_default_max_activations',
			'wplm_heartbeat_interval',
			'wplm_zombie_window',
			'wplm_telemetry_retention_days',
			'wplm_webhook_retry_attempts',
			'wplm_default_grace_days',
		);

		foreach ( $number_fields as $option ) {
			register_setting(
				'wplm_settings',
				$option,
				array(
					'sanitize_callback' => 'absint',
					'default'           => $this->defaults[ $option ] ?? 0,
				)
			);
		}

		$checkbox_fields = array(
			'wplm_hide_key_in_email',
			'wplm_qr_enabled',
			'wplm_force_ssl',
			'wplm_sub_engine_enabled',
		);

		foreach ( $checkbox_fields as $option ) {
			register_setting(
				'wplm_settings',
				$option,
				array(
					'sanitize_callback' => array( $this, '_sanitize_checkbox' ),
					'default'           => 0,
				)
			);
		}

		$text_fields = array(
			'wplm_order_complete_status',
			'wplm_default_overage_strategy',
			'wplm_dunning_schedule',
		);

		foreach ( $text_fields as $option ) {
			register_setting(
				'wplm_settings',
				$option,
				array(
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => $this->defaults[ $option ] ?? '',
				)
			);
		}

		// ------------------------------------------------------------------
		// Section: General
		// ------------------------------------------------------------------
		add_settings_section(
			'wplm_general',
			__( 'General', 'wp-license-manager' ),
			array( $this, 'render_section_general' ),
			'wplm-settings'
		);

		add_settings_field(
			'wplm_default_max_activations',
			__( 'Default Max Activations', 'wp-license-manager' ),
			array( $this, 'render_field_default_max_activations' ),
			'wplm-settings',
			'wplm_general'
		);

		add_settings_field(
			'wplm_order_complete_status',
			__( 'Auto-delivery trigger status', 'wp-license-manager' ),
			array( $this, 'render_field_order_complete_status' ),
			'wplm-settings',
			'wplm_general'
		);

		add_settings_field(
			'wplm_hide_key_in_email',
			__( 'Hide license key in email', 'wp-license-manager' ),
			array( $this, 'render_field_hide_key_in_email' ),
			'wplm-settings',
			'wplm_general'
		);

		add_settings_field(
			'wplm_qr_enabled',
			__( 'Enable QR codes', 'wp-license-manager' ),
			array( $this, 'render_field_qr_enabled' ),
			'wplm-settings',
			'wplm_general'
		);

		// ------------------------------------------------------------------
		// Section: Security
		// ------------------------------------------------------------------
		add_settings_section(
			'wplm_security',
			__( 'Security', 'wp-license-manager' ),
			array( $this, 'render_section_security' ),
			'wplm-settings'
		);

		add_settings_field(
			'wplm_force_ssl',
			__( 'Force HTTPS for REST API', 'wp-license-manager' ),
			array( $this, 'render_field_force_ssl' ),
			'wplm-settings',
			'wplm_security'
		);

		// ------------------------------------------------------------------
		// Section: Activation
		// ------------------------------------------------------------------
		add_settings_section(
			'wplm_activation',
			__( 'Activation', 'wp-license-manager' ),
			array( $this, 'render_section_activation' ),
			'wplm-settings'
		);

		add_settings_field(
			'wplm_default_overage_strategy',
			__( 'Default overage strategy', 'wp-license-manager' ),
			array( $this, 'render_field_default_overage_strategy' ),
			'wplm-settings',
			'wplm_activation'
		);

		// ------------------------------------------------------------------
		// Section: Monitoring
		// ------------------------------------------------------------------
		add_settings_section(
			'wplm_monitoring',
			__( 'Monitoring', 'wp-license-manager' ),
			array( $this, 'render_section_monitoring' ),
			'wplm-settings'
		);

		add_settings_field(
			'wplm_heartbeat_interval',
			__( 'Heartbeat interval (seconds)', 'wp-license-manager' ),
			array( $this, 'render_field_heartbeat_interval' ),
			'wplm-settings',
			'wplm_monitoring'
		);

		add_settings_field(
			'wplm_zombie_window',
			__( 'Dead-machine cull window (seconds)', 'wp-license-manager' ),
			array( $this, 'render_field_zombie_window' ),
			'wplm-settings',
			'wplm_monitoring'
		);

		add_settings_field(
			'wplm_telemetry_retention_days',
			__( 'Log retention (days)', 'wp-license-manager' ),
			array( $this, 'render_field_telemetry_retention_days' ),
			'wplm-settings',
			'wplm_monitoring'
		);

		// ------------------------------------------------------------------
		// Section: Subscriptions
		// ------------------------------------------------------------------
		add_settings_section(
			'wplm_subscriptions_settings',
			__( 'Subscriptions', 'wp-license-manager' ),
			array( $this, 'render_section_subscriptions' ),
			'wplm-settings'
		);

		add_settings_field(
			'wplm_sub_engine_enabled',
			__( 'Enable native subscription engine', 'wp-license-manager' ),
			array( $this, 'render_field_sub_engine_enabled' ),
			'wplm-settings',
			'wplm_subscriptions_settings'
		);

		add_settings_field(
			'wplm_default_grace_days',
			__( 'Default grace (days)', 'wp-license-manager' ),
			array( $this, 'render_field_default_grace_days' ),
			'wplm-settings',
			'wplm_subscriptions_settings'
		);

		// ------------------------------------------------------------------
		// Sections: one per licence profile.
		// ------------------------------------------------------------------
		foreach ( $this->profiles->all() as $profile ) {
			$this->register_profile_settings( $profile );
		}

		// ------------------------------------------------------------------
		// Section: Dunning
		// ------------------------------------------------------------------
		add_settings_section(
			'wplm_dunning',
			__( 'Dunning', 'wp-license-manager' ),
			array( $this, 'render_section_dunning' ),
			'wplm-settings'
		);

		add_settings_field(
			'wplm_dunning_schedule',
			__( 'Retry schedule (days after failure)', 'wp-license-manager' ),
			array( $this, 'render_field_dunning_schedule' ),
			'wplm-settings',
			'wplm_dunning'
		);

		add_settings_field(
			'wplm_webhook_retry_attempts',
			__( 'Webhook retry attempts', 'wp-license-manager' ),
			array( $this, 'render_field_webhook_retry_attempts' ),
			'wplm-settings',
			'wplm_dunning'
		);
	}

	/**
	 * Register a licence profile's check-in window and grace.
	 *
	 * @param Profile $profile The profile.
	 * @return void
	 */
	private function register_profile_settings( Profile $profile ): void {
		$fields = array(
			'check_in_days' => array(
				'label'       => __( 'Check-in window (days)', 'wp-license-manager' ),
				'min'         => 1,
				'default'     => Profile::DEFAULT_CHECK_IN_DAYS,
				'description' => __( 'How long a computer keeps working without reaching this server. Every successful check-in starts the window again. Past it, the computer goes read-only until it checks in or gets an offline renewal code.', 'wp-license-manager' ),
			),
			'grace_days'    => array(
				'label'       => __( 'Grace (days)', 'wp-license-manager' ),
				'min'         => 0,
				'default'     => Profile::DEFAULT_GRACE_DAYS,
				'description' => __( 'How long an unpaid module keeps working after its paid-through date before it becomes read-only. A payment inside grace continues from the old paid-through date.', 'wp-license-manager' ),
			),
		);

		$section = 'wplm_profile_' . str_replace( '-', '_', $profile->code );
		add_settings_section(
			$section,
			/* translators: %s: product name */
			sprintf( __( '%s licences', 'wp-license-manager' ), $profile->label ),
			static function () use ( $profile ): void {
				/* translators: %s: product name */
				echo '<p>' . esc_html( sprintf( __( 'Timing signed into every %s token. A change reaches each computer at its next check-in.', 'wp-license-manager' ), $profile->label ) ) . '</p>';
			},
			'wplm-settings'
		);

		foreach ( $fields as $key => $field ) {
			$option = $profile->option_name( $key );
			register_setting(
				'wplm_settings',
				$option,
				array(
					'sanitize_callback' => static fn( $value ) => self::sanitize_profile_days( $value, $field['min'] ),
					'default'           => '',
				)
			);
			add_settings_field(
				$option,
				$field['label'],
				array( $this, 'render_field_profile_days' ),
				'wplm-settings',
				$section,
				array(
					'label_for'   => $option,
					'option'      => $option,
					'min'         => $field['min'],
					'default'     => $field['default'],
					'description' => $field['description'],
				)
			);
		}
	}

	/**
	 * Keep a profile day count within bounds; blank or non-numeric input clears it (the default applies).
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Smallest accepted value.
	 * @return string '' or a whole number as a string.
	 */
	public static function sanitize_profile_days( $value, int $min ): string {
		if ( null === $value || ! is_scalar( $value ) || ! is_numeric( trim( (string) $value ) ) ) {
			return '';
		}
		return (string) min( self::MAX_PROFILE_DAYS, max( $min, (int) $value ) );
	}

	/**
	 * The warning shown when tokens are signed with a keypair from the database on a production site.
	 *
	 * @param bool   $from_constant Whether `WPLM_SIGNING_KEYPAIR` is defined.
	 * @param string $environment   `wp_get_environment_type()`.
	 * @return string The warning, or '' when there is nothing to warn about.
	 */
	public static function signing_key_warning( bool $from_constant, string $environment ): string {
		if ( $from_constant || 'production' !== $environment ) {
			return '';
		}
		return __( 'Licence tokens are signed with a keypair stored in the database. On a production licence server, define WPLM_SIGNING_KEYPAIR in wp-config.php and keep an encrypted offline backup of it: anyone who reads or restores the database could otherwise sign licences, and losing it strands every office.', 'wp-license-manager' );
	}

	// -------------------------------------------------------------------------
	// Section descriptions
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_section_general(): void {
		echo '<p>' . esc_html__( 'Global defaults applied to newly created licenses and email delivery.', 'wp-license-manager' ) . '</p>';
	}

	/** @return void */
	public function render_section_security(): void {
		echo '<p>' . esc_html__( 'Harden the REST API and key delivery endpoints.', 'wp-license-manager' ) . '</p>';
	}

	/** @return void */
	public function render_section_activation(): void {
		echo '<p>' . esc_html__( 'Control how the plugin handles activation seat overages.', 'wp-license-manager' ) . '</p>';
	}

	/** @return void */
	public function render_section_monitoring(): void {
		echo '<p>' . esc_html__( 'Configure floating-license heartbeat timing and telemetry log retention.', 'wp-license-manager' ) . '</p>';
	}

	/** @return void */
	public function render_section_subscriptions(): void {
		echo '<p>' . esc_html__( 'Enable or disable the WPLM native subscription billing engine.', 'wp-license-manager' ) . '</p>';
	}

	/** @return void */
	public function render_section_dunning(): void {
		echo '<p>' . esc_html__( 'Define retry schedules for failed payments and webhook delivery.', 'wp-license-manager' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers — General
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_field_default_max_activations(): void {
		$value = absint( get_option( 'wplm_default_max_activations', $this->defaults['wplm_default_max_activations'] ) );
		?>
		<input
			type="number"
			id="wplm_default_max_activations"
			name="wplm_default_max_activations"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			step="1"
			class="small-text"
		>
		<p class="description"><?php esc_html_e( 'Number of device activations allowed per license when no generator override is set.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_order_complete_status(): void {
		$value   = sanitize_text_field( get_option( 'wplm_order_complete_status', $this->defaults['wplm_order_complete_status'] ) );
		$options = array(
			'completed'  => __( 'Completed', 'wp-license-manager' ),
			'processing' => __( 'Processing', 'wp-license-manager' ),
		);
		?>
		<select id="wplm_order_complete_status" name="wplm_order_complete_status">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'WooCommerce order status that triggers automatic license key delivery.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_hide_key_in_email(): void {
		$value = (int) get_option( 'wplm_hide_key_in_email', $this->defaults['wplm_hide_key_in_email'] );
		?>
		<label for="wplm_hide_key_in_email">
			<input
				type="checkbox"
				id="wplm_hide_key_in_email"
				name="wplm_hide_key_in_email"
				value="1"
				<?php checked( 1, $value ); ?>
			>
			<?php esc_html_e( 'Do not include the license key in the order confirmation email.', 'wp-license-manager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When checked, customers must retrieve their key from the My Account dashboard.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_qr_enabled(): void {
		$value = (int) get_option( 'wplm_qr_enabled', $this->defaults['wplm_qr_enabled'] );
		?>
		<label for="wplm_qr_enabled">
			<input
				type="checkbox"
				id="wplm_qr_enabled"
				name="wplm_qr_enabled"
				value="1"
				<?php checked( 1, $value ); ?>
			>
			<?php esc_html_e( 'Generate a QR code image containing the license key for each new license.', 'wp-license-manager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'QR codes appear on the license detail screen and in PDF exports.', 'wp-license-manager' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers — Security
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_field_force_ssl(): void {
		$value = (int) get_option( 'wplm_force_ssl', $this->defaults['wplm_force_ssl'] );
		?>
		<label for="wplm_force_ssl">
			<input
				type="checkbox"
				id="wplm_force_ssl"
				name="wplm_force_ssl"
				value="1"
				<?php checked( 1, $value ); ?>
			>
			<?php esc_html_e( 'Reject REST API requests that arrive over plain HTTP.', 'wp-license-manager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Recommended for production environments. Requires a valid SSL certificate.', 'wp-license-manager' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers — Activation
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_field_default_overage_strategy(): void {
		$value   = sanitize_text_field( get_option( 'wplm_default_overage_strategy', $this->defaults['wplm_default_overage_strategy'] ) );
		$options = array(
			'deny'        => __( 'Deny — block activation above seat limit', 'wp-license-manager' ),
			'allow_1_25x' => __( 'Allow up to 1.25× seat limit', 'wp-license-manager' ),
			'allow_2x'    => __( 'Allow up to 2× seat limit', 'wp-license-manager' ),
		);
		?>
		<select id="wplm_default_overage_strategy" name="wplm_default_overage_strategy">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Applied when a license has no per-license overage strategy override.', 'wp-license-manager' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers — Monitoring
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_field_heartbeat_interval(): void {
		$value = absint( get_option( 'wplm_heartbeat_interval', $this->defaults['wplm_heartbeat_interval'] ) );
		?>
		<input
			type="number"
			id="wplm_heartbeat_interval"
			name="wplm_heartbeat_interval"
			value="<?php echo esc_attr( $value ); ?>"
			min="30"
			step="1"
			class="small-text"
		>
		<p class="description"><?php esc_html_e( 'How often (in seconds) floating-license clients must send a heartbeat ping to keep their lease alive.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_zombie_window(): void {
		$value = absint( get_option( 'wplm_zombie_window', $this->defaults['wplm_zombie_window'] ) );
		?>
		<input
			type="number"
			id="wplm_zombie_window"
			name="wplm_zombie_window"
			value="<?php echo esc_attr( $value ); ?>"
			min="60"
			step="1"
			class="small-text"
		>
		<p class="description"><?php esc_html_e( 'Machines that have not sent a heartbeat within this many seconds are considered dead and their seat is freed.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_telemetry_retention_days(): void {
		$value = absint( get_option( 'wplm_telemetry_retention_days', $this->defaults['wplm_telemetry_retention_days'] ) );
		?>
		<input
			type="number"
			id="wplm_telemetry_retention_days"
			name="wplm_telemetry_retention_days"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			step="1"
			class="small-text"
		>
		<p class="description"><?php esc_html_e( 'Activation log entries older than this number of days are pruned by the daily cron job.', 'wp-license-manager' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers — Subscriptions
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_field_sub_engine_enabled(): void {
		$value = (int) get_option( 'wplm_sub_engine_enabled', $this->defaults['wplm_sub_engine_enabled'] );
		?>
		<label for="wplm_sub_engine_enabled">
			<input
				type="checkbox"
				id="wplm_sub_engine_enabled"
				name="wplm_sub_engine_enabled"
				value="1"
				<?php checked( 1, $value ); ?>
			>
			<?php esc_html_e( 'Activate the WPLM billing scheduler, dunning manager, and renewal processor.', 'wp-license-manager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Disable this if you are using WooCommerce Subscriptions as the sole billing engine.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_default_grace_days(): void {
		$value = absint( get_option( 'wplm_default_grace_days', $this->defaults['wplm_default_grace_days'] ) );
		?>
		<input type="number" id="wplm_default_grace_days" name="wplm_default_grace_days" value="<?php echo esc_attr( (string) $value ); ?>" min="0" step="1" class="small-text">
		<p class="description"><?php esc_html_e( 'How long a recurring licence stays valid after its paid period ends, for packages that do not set their own grace.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/**
	 * A licence profile's day-count field.
	 *
	 * @param array{option: string, min: int, default: int, description: string} $args Field arguments.
	 * @return void
	 */
	public function render_field_profile_days( array $args ): void {
		$value = (string) get_option( $args['option'], '' );
		?>
		<input type="number" id="<?php echo esc_attr( $args['option'] ); ?>" name="<?php echo esc_attr( $args['option'] ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( (string) $args['default'] ); ?>" min="<?php echo esc_attr( (string) $args['min'] ); ?>" max="<?php echo esc_attr( (string) self::MAX_PROFILE_DAYS ); ?>" step="1" class="small-text">
		<p class="description">
			<?php
			echo esc_html( $args['description'] );
			echo ' ';
			/* translators: %d: default number of days */
			echo esc_html( sprintf( __( 'Leave blank for the default (%d).', 'wp-license-manager' ), $args['default'] ) );
			?>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers — Dunning
	// -------------------------------------------------------------------------

	/** @return void */
	public function render_field_dunning_schedule(): void {
		$value = sanitize_text_field( get_option( 'wplm_dunning_schedule', $this->defaults['wplm_dunning_schedule'] ) );
		$json  = json_decode( $value, true );
		if ( is_array( $json ) ) {
			$value = implode( ',', array_map( 'absint', $json ) ); // The installer seeds a JSON array.
		}
		?>
		<input
			type="text"
			id="wplm_dunning_schedule"
			name="wplm_dunning_schedule"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="1,3,5"
		>
		<p class="description"><?php esc_html_e( 'Comma-separated list of days after a payment failure on which a retry should be attempted (e.g. 1,3,5). A failed charge never suspends the licence.', 'wp-license-manager' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_field_webhook_retry_attempts(): void {
		$value = absint( get_option( 'wplm_webhook_retry_attempts', $this->defaults['wplm_webhook_retry_attempts'] ) );
		?>
		<input
			type="number"
			id="wplm_webhook_retry_attempts"
			name="wplm_webhook_retry_attempts"
			value="<?php echo esc_attr( $value ); ?>"
			min="0"
			max="10"
			step="1"
			class="small-text"
		>
		<p class="description"><?php esc_html_e( 'Maximum number of times a failed outbound webhook will be retried before being marked as permanently failed.', 'wp-license-manager' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Render the full Settings admin page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-license-manager' ) );
		}
		$this->maybe_render_tool_notice();
		$key_warning = self::signing_key_warning( defined( 'WPLM_SIGNING_KEYPAIR' ) && WPLM_SIGNING_KEYPAIR, wp_get_environment_type() );
		if ( '' !== $key_warning ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $key_warning ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPLM Settings', 'wp-license-manager' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wplm_settings' ); ?>
				<?php do_settings_sections( 'wplm-settings' ); ?>
				<?php submit_button(); ?>
			</form>

			<?php $this->render_tools_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render an admin notice after a Tools action completes (?wplm_tool=...&...).
	 *
	 * @return void
	 */
	private function maybe_render_tool_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flag set by our own redirect.
		if ( empty( $_GET['wplm_tool'] ) ) {
			return;
		}

		$tool    = sanitize_key( wp_unslash( $_GET['wplm_tool'] ) );
		$count   = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		$is_fail = ! empty( $_GET['failed'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'import'  => sprintf(
				/* translators: %d: number of licenses imported */
				_n( 'Imported %d license.', 'Imported %d licenses.', $count, 'wp-license-manager' ),
				$count
			),
			'crl'     => __( 'Certificate revocation list rebuilt.', 'wp-license-manager' ),
			'purge'   => sprintf(
				/* translators: %d: number of log rows purged */
				_n( 'Purged %d activation-log row.', 'Purged %d activation-log rows.', $count, 'wp-license-manager' ),
				$count
			),
			'keypair' => __( 'Signing keypair regenerated. Previously issued signed payloads are now invalid until clients fetch the new public key.', 'wp-license-manager' ),
			'resign'  => sprintf(
				/* translators: %d: number of licenses re-signed */
				_n( 'Re-signed %d license with product binding.', 'Re-signed %d licenses with product binding.', $count, 'wp-license-manager' ),
				$count
			),
		);

		if ( ! isset( $messages[ $tool ] ) ) {
			return;
		}

		$class = $is_fail ? 'notice-error' : 'notice-success';
		$text  = $is_fail
			? __( 'The requested tool action could not be completed.', 'wp-license-manager' )
			: $messages[ $tool ];

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	/**
	 * Render the Tools card (export/import CSV, rebuild CRL, purge logs, re-roll keypair).
	 *
	 * Each action posts to admin-post.php and is handled by the Menu controller.
	 *
	 * @return void
	 */
	private function render_tools_section(): void {
		$post_url = admin_url( 'admin-post.php' );
		?>
		<hr>
		<h2><?php esc_html_e( 'Tools', 'wp-license-manager' ); ?></h2>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Export licenses', 'wp-license-manager' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="wplm_tool_export_licenses">
							<?php wp_nonce_field( 'wplm_tool_export_licenses' ); ?>
							<?php submit_button( __( 'Download CSV', 'wp-license-manager' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Download every license as a CSV file (includes plaintext keys).', 'wp-license-manager' ); ?></p>
						</form>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Import licenses', 'wp-license-manager' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
							<input type="hidden" name="action" value="wplm_tool_import_licenses">
							<?php wp_nonce_field( 'wplm_tool_import_licenses' ); ?>
							<input type="file" name="wplm_csv" accept=".csv,text/csv" required>
							<?php submit_button( __( 'Import CSV', 'wp-license-manager' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Upload a CSV with a header row. Recognised columns: license_key, status, product_id, order_id, user_id, max_activations, valid_for_days, expires_at, overage_strategy. Rows whose key already exists are skipped.', 'wp-license-manager' ); ?></p>
						</form>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Rebuild revocation list', 'wp-license-manager' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="wplm_tool_rebuild_crl">
							<?php wp_nonce_field( 'wplm_tool_rebuild_crl' ); ?>
							<?php submit_button( __( 'Rebuild CRL', 'wp-license-manager' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Regenerate and re-sign the certificate revocation list served at /wplm/v1/crl.', 'wp-license-manager' ); ?></p>
						</form>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Purge activation logs', 'wp-license-manager' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="wplm_tool_purge_logs">
							<?php wp_nonce_field( 'wplm_tool_purge_logs' ); ?>
							<?php submit_button( __( 'Purge old logs', 'wp-license-manager' ), 'secondary', 'submit', false ); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %d: retention period in days */
									esc_html__( 'Delete activation-log rows older than the retention window (%d days).', 'wp-license-manager' ),
									(int) get_option( 'wplm_telemetry_retention_days', 90 )
								);
								?>
							</p>
						</form>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Re-sign licenses (product binding)', 'wp-license-manager' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="wplm_tool_resign_licenses">
							<?php wp_nonce_field( 'wplm_tool_resign_licenses' ); ?>
							<label>
								<?php esc_html_e( 'Backfill product ID for keys that have none:', 'wp-license-manager' ); ?>
								<input type="number" name="backfill_product_id" min="0" step="1" placeholder="<?php esc_attr_e( 'optional', 'wp-license-manager' ); ?>" style="width:90px;">
							</label>
							<?php submit_button( __( 'Re-sign all licenses', 'wp-license-manager' ), 'secondary', 'submit', false ); ?>
							<p class="description">
								<?php esc_html_e( 'Re-signs every license token to embed its product ID (the offline-verifiable "pid" field), so a product-locked SDK rejects keys issued for another product. Generator, API, and CSV-imported keys often have no product ID — enter one above to assign it to those keys before re-signing. Run this once after upgrading, and again whenever you bulk-import keys.', 'wp-license-manager' ); ?>
							</p>
						</form>
					</td>
				</tr>

				<tr>
					<th scope="row" style="color:#d63638;"><?php esc_html_e( 'Re-roll signing keypair', 'wp-license-manager' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This permanently replaces the Ed25519 signing keypair. Every previously issued signed license payload will fail verification until clients fetch the new public key. Continue?', 'wp-license-manager' ) ); ?>');">
							<input type="hidden" name="action" value="wplm_tool_reroll_keypair">
							<?php wp_nonce_field( 'wplm_tool_reroll_keypair' ); ?>
							<?php submit_button( __( 'Re-roll keypair', 'wp-license-manager' ), 'delete', 'submit', false ); ?>
							<p class="description" style="color:#d63638;"><?php esc_html_e( 'Danger zone. Only the signing keypair is rotated; the encryption key is left intact so stored keys remain readable.', 'wp-license-manager' ); ?></p>
						</form>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Sanitization / validation
	// -------------------------------------------------------------------------

	/**
	 * Sanitize every setting before it is stored.
	 *
	 * This callback is registered as the sanitize_callback for the group-level
	 * register_setting() call. It is also used internally when individual fields
	 * are updated.
	 *
	 * @param mixed $input Raw POST input. Untyped: options.php passes null when the group option is not
	 *                     posted, which the page's own form never does (a typed array fatals the save).
	 * @return array Sanitized values.
	 */
	public function validate_settings( $input ): array {
		$output = array();
		$input  = is_array( $input ) ? $input : array();

		// Numbers.
		$number_keys = array(
			'wplm_default_max_activations',
			'wplm_heartbeat_interval',
			'wplm_zombie_window',
			'wplm_telemetry_retention_days',
			'wplm_webhook_retry_attempts',
		);
		foreach ( $number_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = absint( $input[ $key ] );
			}
		}

		// Checkboxes — 1 when present and truthy, 0 otherwise.
		$checkbox_keys = array(
			'wplm_hide_key_in_email',
			'wplm_qr_enabled',
			'wplm_force_ssl',
			'wplm_sub_engine_enabled',
		);
		foreach ( $checkbox_keys as $key ) {
			$output[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		// Whitelisted selects.
		if ( isset( $input['wplm_order_complete_status'] ) ) {
			$allowed                              = array( 'completed', 'processing' );
			$output['wplm_order_complete_status'] = in_array( $input['wplm_order_complete_status'], $allowed, true )
				? $input['wplm_order_complete_status']
				: 'completed';
		}

		if ( isset( $input['wplm_default_overage_strategy'] ) ) {
			$allowed                                 = array( 'deny', 'allow_1_25x', 'allow_2x' );
			$output['wplm_default_overage_strategy'] = in_array( $input['wplm_default_overage_strategy'], $allowed, true )
				? $input['wplm_default_overage_strategy']
				: 'deny';
		}

		// Dunning schedule — allow only digits and commas.
		if ( isset( $input['wplm_dunning_schedule'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $input['wplm_dunning_schedule'] ) );
			// Strip anything that isn't a digit or comma.
			$output['wplm_dunning_schedule'] = preg_replace( '/[^0-9,]/', '', $raw );
		}

		return $output;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a checkbox value to 0 or 1 (used as individual register_setting callback).
	 *
	 * @param mixed $value Raw value.
	 * @return int 0 or 1.
	 */
	public function _sanitize_checkbox( $value ): int {
		return ! empty( $value ) ? 1 : 0;
	}
}
