<?php
/**
 * WP_List_Table implementation for the API Keys admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Admin\Flash;
use WPLM\Repositories\ApiKeyRepository;
use WPLM\Services\ApiKeyService;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * REST API keys: the list, a revoke action per key, and a form that issues a new key.
 *
 * A new key's secret is shown on the response to the create form and nowhere else: it is
 * never stored in plain text, not even briefly, so it cannot be shown again after a redirect.
 */
class ApiKeyListTable extends \WP_List_Table {

	/** admin-post action and nonce prefix for revoking a key. */
	public const REVOKE_ACTION = 'wplm_revoke_api_key';

	/** Nonce action for the create form, which posts back to this screen. */
	private const CREATE_ACTION = 'wplm_create_api_key';

	/** @var ApiKeyRepository */
	private ApiKeyRepository $repo;

	/** @var ApiKeyService */
	private ApiKeyService $service;

	/**
	 * @param ApiKeyRepository $repo    API key data-access layer.
	 * @param ApiKeyService    $service Issues keys.
	 */
	public function __construct( ApiKeyRepository $repo, ApiKeyService $service ) {
		$this->repo    = $repo;
		$this->service = $service;
		parent::__construct(
			array(
				'singular' => 'api_key',
				'plural'   => 'api_keys',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Translated permission labels keyed by scope.
	 *
	 * @return array<string,string>
	 */
	public static function permission_labels(): array {
		return array(
			'read'       => __( 'Read', 'wp-license-manager' ),
			'write'      => __( 'Write', 'wp-license-manager' ),
			'read_write' => __( 'Read and write', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return array(
			'description'    => __( 'Description', 'wp-license-manager' ),
			'truncated_key'  => __( 'Consumer key ends in', 'wp-license-manager' ),
			'permissions'    => __( 'Permissions', 'wp-license-manager' ),
			'user_id'        => __( 'Owner', 'wp-license-manager' ),
			'last_access_at' => __( 'Last used', 'wp-license-manager' ),
			'created_at'     => __( 'Created', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'truncated_key':
				return '<code>…' . esc_html( (string) $item->truncated_key ) . '</code>';

			case 'permissions':
				$labels = self::permission_labels();
				return sprintf(
					'<span class="wplm-badge">%s</span>',
					esc_html( $labels[ $item->permissions ] ?? $item->permissions )
				);

			case 'user_id':
				$user = get_userdata( (int) $item->user_id );
				return $user ? esc_html( $user->display_name ) : esc_html__( 'Deleted user', 'wp-license-manager' );

			case 'last_access_at':
				return $item->last_access_at ? $this->date( $item->last_access_at ) : esc_html__( 'Never', 'wp-license-manager' );

			case 'created_at':
				return $this->date( (string) $item->created_at );
		}
		return '';
	}

	public function column_description( $item ): string {
		$id         = (int) $item->id;
		$revoke_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::REVOKE_ACTION . '&id=' . $id ),
			self::REVOKE_ACTION . '_' . $id
		);

		$title = '' !== (string) $item->description
			? '<strong>' . esc_html( (string) $item->description ) . '</strong>'
			: '<strong>' . esc_html__( '(no description)', 'wp-license-manager' ) . '</strong>';

		$actions = array(
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $revoke_url ),
				esc_js( __( 'Revoke this key? Apps using it stop working at once.', 'wp-license-manager' ) ),
				esc_html__( 'Revoke', 'wp-license-manager' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	/** A stored UTC datetime in the site's date and time format. */
	private function date( string $utc ): string {
		$ts = strtotime( $utc . ' UTC' );
		if ( false === $ts ) {
			return '';
		}
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) );
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$this->items           = $this->repo->get_all();
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	public function no_items(): void {
		esc_html_e( 'No API keys yet. Create one above to call the management API.', 'wp-license-manager' );
	}

	// -------------------------------------------------------------------------
	// Page-level render
	// -------------------------------------------------------------------------

	/**
	 * Issue a key from the submitted create form, if there is one.
	 *
	 * @return array|null The service result, or null when nothing was submitted.
	 */
	private function maybe_create(): ?array {
		if ( ! isset( $_POST['wplm_api_key_nonce'] ) ) {
			return null;
		}
		check_admin_referer( self::CREATE_ACTION, 'wplm_api_key_nonce' );

		return $this->service->create(
			get_current_user_id(),
			sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
			sanitize_key( wp_unslash( $_POST['permissions'] ?? '' ) )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-license-manager' ) );
		}

		$created = $this->maybe_create();
		$api_url = rest_url( 'wplm/v1/' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'API Keys', 'wp-license-manager' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			Flash::render( Flash::take( get_current_user_id() ) );
			if ( null !== $created ) {
				Flash::render( $created );
			}
			?>

			<p>
				<?php
				printf(
					/* translators: %s: REST base URL, e.g. https://example.com/wp-json/wplm/v1/ */
					esc_html__( 'Keys authenticate calls to the management API at %s with HTTP Basic auth: the consumer key is the username and the consumer secret is the password. A key works only on these routes, not on the rest of the WordPress API. Client apps do not need a key to validate or activate a license.', 'wp-license-manager' ),
					'<code>' . esc_html( $api_url ) . '</code>'
				);
				?>
			</p>

			<?php if ( null !== $created && $created['ok'] ) : ?>
				<?php $key = $created['key']; ?>
				<div class="wplm-add-form" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #00a32a;padding:16px;margin-bottom:20px;max-width:760px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Your new API key', 'wp-license-manager' ); ?></h2>
					<p><strong><?php esc_html_e( 'Copy the consumer secret now. It is stored only as a hash and cannot be shown again.', 'wp-license-manager' ); ?></strong></p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Consumer key', 'wp-license-manager' ); ?></th>
								<td>
									<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $key['consumer_key'] ); ?>" onfocus="this.select();">
									<button type="button" class="button wplm-copy-key" data-key="<?php echo esc_attr( $key['consumer_key'] ); ?>"><?php esc_html_e( 'Copy', 'wp-license-manager' ); ?></button>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Consumer secret', 'wp-license-manager' ); ?></th>
								<td>
									<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $key['consumer_secret'] ); ?>" onfocus="this.select();">
									<button type="button" class="button wplm-copy-key" data-key="<?php echo esc_attr( $key['consumer_secret'] ); ?>"><?php esc_html_e( 'Copy', 'wp-license-manager' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<!-- Create key form -->
			<div class="wplm-add-form" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin-bottom:20px;max-width:600px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Create API Key', 'wp-license-manager' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wplm-api-keys' ) ); ?>">
					<?php wp_nonce_field( self::CREATE_ACTION, 'wplm_api_key_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="wplm_api_key_description"><?php esc_html_e( 'Description', 'wp-license-manager' ); ?></label></th>
								<td>
									<input type="text" id="wplm_api_key_description" name="description" class="regular-text" maxlength="191" required>
									<p class="description"><?php esc_html_e( 'Where the key is used, e.g. "Billing server".', 'wp-license-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wplm_api_key_permissions"><?php esc_html_e( 'Permissions', 'wp-license-manager' ); ?></label></th>
								<td>
									<select id="wplm_api_key_permissions" name="permissions">
										<?php foreach ( self::permission_labels() as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, 'read' ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Read lists and fetches. Write creates, changes and deletes. Most integrations need read and write.', 'wp-license-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Owner', 'wp-license-manager' ); ?></th>
								<td><?php echo esc_html( wp_get_current_user()->display_name ); ?></td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Create API Key', 'wp-license-manager' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<?php
			$this->prepare_items();
			$this->display();
			?>
		</div>
		<?php
	}
}
