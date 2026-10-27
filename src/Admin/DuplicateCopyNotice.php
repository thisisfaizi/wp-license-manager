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
	 * The running copy is recognised by its real path, not its basename: a basename WordPress could
	 * not strip (a symlinked or moved plugins folder) would otherwise name the running copy and hand
	 * out a command that removes it.
	 *
	 * @param array<string, array<string, string>>|null $plugins    `get_plugins()` result; read when null.
	 * @param string|null                               $this_file  This copy's main file; WPLM_PLUGIN_FILE when null.
	 * @param string|null                               $plugin_dir The plugins folder; WP_PLUGIN_DIR when null.
	 * @return array<string, string> Plugin file => version.
	 */
	public function other_copies( ?array $plugins = null, ?string $this_file = null, ?string $plugin_dir = null ): array {
		if ( null === $plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
		}
		$plugin_dir = $plugin_dir ?? WP_PLUGIN_DIR;
		$self       = self::real( $this_file ?? WPLM_PLUGIN_FILE );

		$copies = array();
		foreach ( $plugins as $file => $data ) {
			if ( 'WP License Manager' !== ( $data['Name'] ?? '' ) ) {
				continue;
			}
			if ( self::real( $plugin_dir . '/' . $file ) === $self ) {
				continue;
			}
			$copies[ $file ] = (string) ( $data['Version'] ?? '' );
		}
		return $copies;
	}

	/**
	 * The shell command that removes a copy without running its uninstall.
	 *
	 * A copy in its own folder loses the folder; a single file loose in the plugins folder loses only
	 * that file (`rm -r` of its dirname would be the whole plugins folder).
	 *
	 * @param string      $file       Plugin file relative to the plugins folder.
	 * @param string|null $plugin_dir The plugins folder; WP_PLUGIN_DIR when null.
	 * @return string
	 */
	public static function removal_command( string $file, ?string $plugin_dir = null ): string {
		$base   = rtrim( wp_normalize_path( $plugin_dir ?? WP_PLUGIN_DIR ), '/' );
		$folder = dirname( wp_normalize_path( $file ) );

		if ( '.' === $folder || '' === $folder ) {
			return 'rm ' . escapeshellarg( $base . '/' . basename( $file ) );
		}
		return 'rm -r ' . escapeshellarg( $base . '/' . $folder );
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
						/* translators: 1: plugin folder or file, 2: version */
						__( 'The copy at wp-content/plugins/%1$s (version %2$s) is not the one in use. Do not use Delete on it: a copy older than 1.3.2 deletes every licence, device, subscription and setting when it is deleted. Deactivate it, then remove it with your host\'s file manager, FTP or SSH, which does not run its uninstall.', 'wp-license-manager' ),
						'.' === $folder ? $file : $folder,
						'' !== $version ? $version : '?'
					)
				),
				esc_html__( 'Over SSH:', 'wp-license-manager' ),
				esc_html( self::removal_command( $file ) )
			);
		}
	}

	/** A path with symlinks resolved, or normalised as given when it does not exist. */
	private static function real( string $path ): string {
		$real = realpath( $path );
		return wp_normalize_path( false !== $real ? $real : $path );
	}
}
