<?php
/**
 * The Settings page: per-profile licence timing (M5-27a §3), the grace default, the dunning schedule
 * the page saves, and the production signing-key warning.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Admin;

use WPLM\Admin\Settings\SettingsPage;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Services\Subscriptions\DunningManager;
use WPLM\Tests\TestCase;

class SettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		( new SettingsPage() )->register_settings();
	}

	private function profile(): \WPLM\Licensing\Profile {
		return $this->make( ProfileRegistry::class )->get( ProfileRegistry::SUPER_LEDGER );
	}

	/** Save options the way options.php does: every option registered in the group, from POST. */
	private function save( array $post ): void {
		foreach ( get_registered_settings() as $option => $args ) {
			if ( 'wplm_settings' !== ( $args['group'] ?? '' ) ) {
				continue;
			}
			$value = $post[ $option ] ?? null;
			if ( ! is_array( $value ) && null !== $value ) {
				$value = trim( (string) $value );
			}
			update_option( $option, wp_unslash( $value ) );
		}
	}

	private function render(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		( new SettingsPage() )->render_settings_page();
		return (string) ob_get_clean();
	}

	public function test_the_page_saves_without_error_when_the_group_option_is_not_posted(): void {
		$this->save( array( 'wplm_default_max_activations' => '2' ) );

		$this->assertSame( 2, (int) get_option( 'wplm_default_max_activations' ) );
	}

	public function test_profile_check_in_window_and_grace_are_saved_and_used(): void {
		$profile = $this->profile();

		$this->save(
			array(
				$profile->option_name( 'check_in_days' ) => '14',
				$profile->option_name( 'grace_days' )    => '10',
			)
		);

		$this->assertSame( 14, $this->profile()->check_in_days() );
		$this->assertSame( 10, $this->profile()->grace_days() );
	}

	public function test_profile_days_are_kept_within_bounds_and_blank_means_the_default(): void {
		$profile = $this->profile();

		$this->save(
			array(
				$profile->option_name( 'check_in_days' ) => '0',
				$profile->option_name( 'grace_days' )    => '500',
			)
		);
		$this->assertSame( 1, $this->profile()->check_in_days() );
		$this->assertSame( SettingsPage::MAX_PROFILE_DAYS, $this->profile()->grace_days() );

		$this->save(
			array(
				$profile->option_name( 'check_in_days' ) => '',
				$profile->option_name( 'grace_days' )    => 'abc',
			)
		);
		$this->assertSame( 7, $this->profile()->check_in_days() );
		$this->assertSame( 7, $this->profile()->grace_days() );
	}

	public function test_the_page_shows_profile_fields_and_the_grace_default(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'name="' . $this->profile()->option_name( 'check_in_days' ) . '"', $html );
		$this->assertStringContainsString( 'name="' . $this->profile()->option_name( 'grace_days' ) . '"', $html );
		$this->assertStringContainsString( 'name="wplm_default_grace_days"', $html );
	}

	public function test_the_grace_default_is_saved(): void {
		$this->save( array( 'wplm_default_grace_days' => '5' ) );

		$this->assertSame( 5, \WPLM\Models\Package::from_row( array() )->effective_grace_days() );
	}

	public function test_the_dunning_schedule_the_page_saves_is_the_one_dunning_uses(): void {
		$this->save( array( 'wplm_dunning_schedule' => '2, 4,9' ) );

		$this->assertSame( array( 2, 4, 9 ), $this->make( DunningManager::class )->retry_schedule() );
	}

	public function test_the_dunning_field_shows_the_real_default(): void {
		delete_option( 'wplm_dunning_schedule' );

		$this->assertStringContainsString( 'value="1,3,5"', $this->render() );
		$this->assertSame( array( 1, 3, 5 ), $this->make( DunningManager::class )->retry_schedule() );
	}

	public function test_a_signing_key_from_the_database_on_production_is_warned_about(): void {
		$this->assertNotSame( '', SettingsPage::signing_key_warning( false, 'production' ) );
		$this->assertSame( '', SettingsPage::signing_key_warning( true, 'production' ), 'The wp-config constant is the production setup.' );
		$this->assertSame( '', SettingsPage::signing_key_warning( false, 'staging' ) );
		$this->assertSame( '', SettingsPage::signing_key_warning( false, 'local' ) );
	}

	public function test_the_warning_appears_on_the_page(): void {
		$expected = SettingsPage::signing_key_warning( defined( 'WPLM_SIGNING_KEYPAIR' ) && WPLM_SIGNING_KEYPAIR, wp_get_environment_type() );
		$this->assertNotSame( '', $expected, 'The test site keeps its keypair in the database and runs as production.' );

		$this->assertStringContainsString( esc_html( $expected ), $this->render() );
	}
}
