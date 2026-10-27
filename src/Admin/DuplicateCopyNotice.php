<?php
/**
 * Warns when a second copy of the plugin is installed under another folder name.
 *
 * @package WPLM\Admin
 */

namespace WPLM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A copy downloaded from GitHub's "Code → Download ZIP" unpacks to `wp-license-manager-main`; the
 * release ZIP to `wp-license-manager`. Uploading one over the other installs a second plugin instead
 * of replacing the first, and Delete on the old copy runs its uninstall.php. Before 1.3.2 that deleted
 * every licence, device and subscription. The old copy's code is what runs, so only a warning before
 * the click can help.
 */
class DuplicateCopyNotice {

	/** Hook the notice. */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Other installed plugins that are WP License Manager, by plugin file.
	 *
	 * @param array<string, array<string, string>>|null $plugins `get_plugins()` result; read when null.
	 * @param string                                     $this_copy This copy's plugin basename.
	 * @return array<string, string> Plugin file => version.
	 */
	public function other_copies( ?array $plugins = null, string $this_copy = WPLM_PLUGIN_BASENAME ): array {
		if ( null === $plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
		}

		$copies = array();
		foreach ( $plugins as $file => $data ) {
			if ( $file !== $this_copy && 'WP License Manager' === ( $data['Name'] ?? '' ) ) {
				$copies[ $file ] = (string) ( $data['Version'] ?? '' );
			}
		}
		return $copies;
	}

	/** Print the notice for users who can delete plugins. */
	public function render(): void {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return;
		}

		foreach ( $this->other_copies() as $file => $version ) {
			$folder = dirname( $file );
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s <code>%4$s</code></p></div>',
				esc_html__( 'A second copy of WP License Manager is installed.', 'wp-license-manager' ),
				esc_html(
					sprintf(
						/* translators: 1: plugin folder, 2: version */
						__( 'The copy in wp-content/plugins/%1$s (version %2$s) is not the one in use. Do not use Delete on it: a copy older than 1.3.2 deletes every licence, device, subscription and setting when it is deleted. Deactivate it, then remove its folder with your host\'s file manager, FTP or SSH, which does not run its uninstall.', 'wp-license-manager' ),
						$folder,
						'' !== $version ? $version : '?'
					)
				),
				esc_html__( 'Over SSH, from the WordPress folder:', 'wp-license-manager' ),
				esc_html( 'rm -r wp-content/plugins/' . $folder )
			);
		}
	}
}
