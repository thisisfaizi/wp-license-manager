<?php
/**
 * "Mark add-ons settled": the shop closes add-on lines fulfilment refused for good.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\AddonService;

/**
 * An add-on line refused at fulfilment (the licence already has what it adds, the add-ons subscription
 * it was sold against ended, an earlier attempt left lines for the owner) stays open: the order is never
 * fulfilled, and the line holds its modules and add-ons cycle against the customer's next purchase. A
 * partial refund does not close it. The shop settles it here, once it has dealt with the customer (a
 * refund, a credit, nothing owed):
 *
 * - each line that can be settled ({@see AddonService::settleable()}) gets a "Mark add-ons settled" tick
 *   in the order's items (`woocommerce_after_order_itemmeta`), and the order a "Mark add-ons settled"
 *   order action with an optional note (`woocommerce_order_actions`, `woocommerce_order_actions_end`) —
 *   the same on the posts and the HPOS order screen;
 * - settling stamps the line `_wplm_addon_settled` (JSON: `by` user id, `at` UTC, `note`) and notes it
 *   on the order. Nothing else: no line is written or removed, no subscription changed, no money moved.
 *   The line then holds no module or cycle and is never added ({@see AddonService::released()}), and once
 *   every line of the order is done the order is marked fulfilled (`_wplm_plan_fulfilled`). The note
 *   names what settling leaves as it is: add-on lines an attempt cut off before recording them left on
 *   the licence ({@see AddonService::unrecorded_lines()}), and an add-ons subscription the line opened
 *   that never billed — still pending, it keeps the licence's add-ons on its cycle until cancelled;
 * - a line whose add-ons are on the licence already, which completing the order again records
 *   ({@see AddonService::retry_records()}), is never settled.
 *
 * Needs `manage_woocommerce` and this form's nonce. Settling a line twice changes nothing.
 */
class AddonSettlement {

	/** The order action, and the nonce action's prefix. */
	public const ACTION = 'wplm_settle_addons';

	/** The order line meta that records it. */
	public const META = '_wplm_addon_settled';

	/** @var AddonService */
	private AddonService $addons;

	/**
	 * @param AddonService $addons The add-ons service.
	 */
	public function __construct( AddonService $addons ) {
		$this->addons = $addons;
	}

	/** Register hooks. */
	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_actions_end', array( $this, 'render_note_field' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_item' ), 10, 3 );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * The lines of an order whose add-ons can be settled now.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, \WC_Order_Item_Product> By item id.
	 */
	public function settleable_items( \WC_Order $order ): array {
		$found = array();
		foreach ( $order->get_items() as $id => $item ) {
			if ( $this->addons->settleable( $order, $item ) ) {
				$found[ (int) $id ] = $item;
			}
		}
		return $found;
	}

	// -------------------------------------------------------------------------
	// The order screen
	// -------------------------------------------------------------------------

	/**
	 * `woocommerce_order_actions`: offer "Mark add-ons settled" on an order with a line to settle.
	 *
	 * @param mixed $actions Action key => label.
	 * @param mixed $order   The order (WooCommerce 7.x+), else the screen's.
	 * @return mixed
	 */
	public function order_actions( $actions, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : ( $GLOBALS['theorder'] ?? null );
		if ( ! is_array( $actions ) || ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' )
			|| array() === $this->settleable_items( $order ) ) {
			return $actions;
		}
		$actions[ self::ACTION ] = __( 'Mark add-ons settled', 'wp-license-manager' );
		return $actions;
	}

	/**
	 * `woocommerce_order_actions_end`: the note and the nonce for "Mark add-ons settled".
	 *
	 * @param mixed $order_id The order id.
	 * @return void
	 */
	public function render_note_field( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) || array() === $this->settleable_items( $order ) ) {
			return;
		}
		echo '<li class="wide wplm-settle-addons">';
		echo '<label for="wplm_settle_note"><strong>' . esc_html__( 'Mark add-ons settled', 'wp-license-manager' ) . '</strong></label>';
		echo '<textarea id="wplm_settle_note" name="wplm_settle_note" rows="2" style="width:100%;" placeholder="' . esc_attr__( 'Note (optional), e.g. refunded by bank transfer', 'wp-license-manager' ) . '"></textarea>';
		echo '<p class="description">' . esc_html__( 'For add-on lines that could not be added and will not be: tick them in the items list, choose "Mark add-ons settled" above, then Update. Settling adds, removes, bills and refunds nothing; the lines stop holding their modules and are never added later.', 'wp-license-manager' ) . '</p>';
		wp_nonce_field( self::ACTION . '_' . $order->get_id(), 'wplm_settle_nonce', false );
		echo '</li>';
	}

	/**
	 * `woocommerce_after_order_itemmeta`: a settled line says so; a line that can be settled gets its tick.
	 *
	 * @param mixed $item_id Item id.
	 * @param mixed $item    The line.
	 * @param mixed $product Its product.
	 * @return void
	 */
	public function render_item( $item_id, $item, $product = null ): void {
		unset( $product );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		if ( AddonService::settled( $item ) ) {
			$stamp = self::stamp( $item );
			$text  = sprintf(
				/* translators: 1: user name, 2: date */
				__( 'Add-ons marked settled by %1$s on %2$s.', 'wp-license-manager' ),
				self::user_name( (int) ( $stamp['by'] ?? 0 ) ),
				'' !== (string) ( $stamp['at'] ?? '' ) ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $stamp['at'] ) ) : '—'
			);
			if ( '' !== (string) ( $stamp['note'] ?? '' ) ) {
				$text .= ' ' . (string) $stamp['note'];
			}
			echo '<div class="wplm-addon-settled"><small>' . esc_html( $text ) . '</small></div>';
			return;
		}
		$order = $item->get_order();
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) || ! $this->addons->settleable( $order, $item ) ) {
			return;
		}
		printf(
			'<div class="wplm-settle-item"><label><input type="checkbox" name="wplm_settle_items[]" value="%1$d" /> %2$s</label> <small>%3$s</small></div>',
			(int) $item_id,
			esc_html__( 'Mark add-ons settled', 'wp-license-manager' ),
			esc_html__( '(its add-ons could not be added: then choose "Mark add-ons settled" in Order actions)', 'wp-license-manager' )
		);
	}

	// -------------------------------------------------------------------------
	// The action
	// -------------------------------------------------------------------------

	/**
	 * `woocommerce_order_action_wplm_settle_addons`: run on the order screen's Update (posts and HPOS
	 * alike); WooCommerce redirects back to the order. A refusal is shown on the order screen.
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	public function handle_action( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_request().
		$result = $this->handle_request( $order, wp_unslash( $_POST ) );
		if ( ! $result['ok'] && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( $result['message'] );
		}
	}

	/**
	 * Check the request (capability and nonce), then settle the ticked lines.
	 *
	 * @param \WC_Order $order   The order.
	 * @param array     $request The posted form, unslashed: `wplm_settle_nonce`, `wplm_settle_items[]`,
	 *                           `wplm_settle_note`.
	 * @return array{ok: bool, message: string, settled: int[]}
	 */
	public function handle_request( \WC_Order $order, array $request ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return self::result( false, __( 'You are not allowed to mark add-ons settled.', 'wp-license-manager' ) );
		}
		$nonce = isset( $request['wplm_settle_nonce'] ) ? sanitize_text_field( (string) $request['wplm_settle_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order->get_id() ) ) {
			return self::result( false, __( 'The page had expired, so no add-ons were marked settled. Reload the order and try again.', 'wp-license-manager' ) );
		}
		$items = isset( $request['wplm_settle_items'] ) ? array_map( 'absint', (array) $request['wplm_settle_items'] ) : array();
		$note  = isset( $request['wplm_settle_note'] ) ? sanitize_textarea_field( (string) $request['wplm_settle_note'] ) : '';
		return $this->settle( $order->get_id(), $items, get_current_user_id(), $note );
	}

	/**
	 * Mark the add-ons of order lines settled. Each line must be the order's and settleable now
	 * ({@see AddonService::settleable()}); a line already settled is left as it is (no second note).
	 * Claims the order's fulfilment lock, so a completion running now can neither add a line being
	 * settled nor be overtaken. Writes or removes no line, changes no subscription, moves no money; add-on
	 * lines left on the licence unrecorded, and an add-ons subscription the line opened that never billed,
	 * are named in the note and the message.
	 *
	 * @param int    $order_id The order.
	 * @param int[]  $item_ids Its lines.
	 * @param int    $user_id  Who settles them.
	 * @param string $note     Why (optional).
	 * @param int    $now      Unix time, 0 for now (tests).
	 * @return array{ok: bool, message: string, settled: int[]}
	 */
	public function settle( int $order_id, array $item_ids, int $user_id, string $note = '', int $now = 0 ): array {
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( array() === $item_ids ) {
			return self::result( false, __( 'Tick the add-on lines to mark settled in the items list, then choose "Mark add-ons settled" again.', 'wp-license-manager' ) );
		}
		$lock = 'wplm_fulfil_lock_' . $order_id;
		if ( ! \WPLM\Support\Lock::claim( $lock, PlanCheckout::FULFIL_LOCK_TTL ) ) {
			return self::result( false, __( 'This order is being fulfilled right now, so no add-ons were marked settled. Try again in a few minutes.', 'wp-license-manager' ) );
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return self::result( false, __( 'That order no longer exists.', 'wp-license-manager' ) );
			}
			$items      = $order->get_items();
			$by         = self::user_name( $user_id );
			$note       = trim( $note );
			$settled    = array();
			$refused    = array();
			$recordable = array();
			$left_over  = array();
			foreach ( $item_ids as $id ) {
				$item = $items[ $id ] ?? null;
				if ( $item instanceof \WC_Order_Item_Product && AddonService::settled( $item ) ) {
					continue; // Settled before: nothing to do.
				}
				if ( ! $item instanceof \WC_Order_Item_Product || ! $this->addons->settleable( $order, $item ) ) {
					if ( $item instanceof \WC_Order_Item_Product && $this->addons->retry_records( $order, $item ) ) {
						$recordable[] = $item->get_name();
					} else {
						$refused[] = $item instanceof \WC_Order_Item_Product ? $item->get_name() : '#' . $id;
					}
					continue;
				}
				$left      = $this->addons->unrecorded_lines( $order, $item );
				$licence   = array() !== $left ? $this->addons->line_licence( $item ) : null;
				$unbilled  = $this->addons->unbilled_addons_subscription( (int) $item->get_meta( '_wplm_addons_subscription_id' ) );
				$left_note = '';
				if ( array() !== $left && null !== $licence ) {
					$left_note = sprintf(
						/* translators: 1: line ids, 2: licence label */
						__( 'Add-on lines #%1$s, which an earlier attempt of this order left on %2$s without recording them, are recorded on no line and stay on the licence: remove them on the licence screen if they should go.', 'wp-license-manager' ),
						implode( ', #', $left ),
						AddonService::licence_label( $licence )
					);
					$left_over[ $left_note ] = true;
				}
				$item->update_meta_data(
					self::META,
					wp_json_encode(
						array(
							'by'   => $user_id,
							'at'   => gmdate( 'Y-m-d H:i:s', $now > 0 ? $now : time() ),
							'note' => $note,
						)
					)
				);
				$item->save_meta_data();
				$text = '' !== $note
					? sprintf(
						/* translators: 1: order line name, 2: user name, 3: the note */
						__( 'WPLM: add-ons on line "%1$s" marked settled by %2$s: %3$s.', 'wp-license-manager' ),
						$item->get_name(),
						$by,
						$note
					)
					: sprintf(
						/* translators: 1: order line name, 2: user name */
						__( 'WPLM: add-ons on line "%1$s" marked settled by %2$s.', 'wp-license-manager' ),
						$item->get_name(),
						$by
					);
				$text .= ' ' . ( null === $unbilled
					? __( 'Settling added, removed, billed and refunded nothing; the line no longer holds its modules or add-ons cycle and is never added later.', 'wp-license-manager' )
					: sprintf(
						/* translators: 1: subscription id, 2: cycle (month or year) */
						__( 'Settling added, removed, billed and refunded nothing; the line no longer holds its modules and is never added later. Add-ons subscription #%1$d, opened for it, never billed and stays pending: until it is cancelled, the licence\'s monthly / yearly add-ons stay on its cycle (every %2$s).', 'wp-license-manager' ),
						$unbilled->id,
						$unbilled->billing_period
					) );
				if ( '' !== $left_note ) {
					$text .= ' ' . $left_note;
				}
				$order->add_order_note( $text );
				$settled[] = $id;
			}

			if ( 'completed' === $order->get_status() && '1' !== (string) $order->get_meta( '_wplm_plan_fulfilled' )
				&& $this->addons->order_lines_done( $order ) ) {
				$order->update_meta_data( '_wplm_plan_fulfilled', '1' );
				$order->save();
				$order->add_order_note( __( 'WPLM: every line of this order is now done.', 'wp-license-manager' ) );
			}
		} finally {
			\WPLM\Support\Lock::release( $lock );
		}

		$problems = array();
		if ( array() !== $recordable ) {
			/* translators: %s: order line names */
			$problems[] = sprintf( __( 'The add-ons of these lines are on the licence already, written by an earlier attempt that was cut off before recording them, so they were not marked settled: %s. Set the order to Completed again to record them.', 'wp-license-manager' ), implode( ', ', $recordable ) );
		}
		if ( array() !== $refused ) {
			/* translators: %s: order line names */
			$problems[] = sprintf( __( 'These lines have no add-ons waiting to be added, so they were not marked settled: %s.', 'wp-license-manager' ), implode( ', ', $refused ) );
		}
		$problems = array_merge( $problems, array_keys( $left_over ) );
		if ( array() !== $recordable || array() !== $refused ) {
			return self::result( false, implode( ' ', $problems ), $settled );
		}
		return self::result( true, trim( __( 'Add-ons marked settled.', 'wp-license-manager' ) . ' ' . implode( ' ', $problems ) ), $settled );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * A line's settled stamp.
	 *
	 * @param \WC_Order_Item $item The line.
	 * @return array{by?: int, at?: string, note?: string}
	 */
	public static function stamp( \WC_Order_Item $item ): array {
		$stamp = json_decode( (string) $item->get_meta( self::META ), true );
		return is_array( $stamp ) ? $stamp : array();
	}

	/**
	 * A user's display name, for a note.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		/* translators: %d: user id */
		return $user instanceof \WP_User ? $user->display_name : ( $user_id > 0 ? sprintf( __( 'user #%d', 'wp-license-manager' ), $user_id ) : __( 'an unknown user', 'wp-license-manager' ) );
	}

	/**
	 * @param bool   $ok      Whether it worked.
	 * @param string $message What happened.
	 * @param int[]  $settled Lines settled now.
	 * @return array{ok: bool, message: string, settled: int[]}
	 */
	private static function result( bool $ok, string $message, array $settled = array() ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
			'settled' => $settled,
		);
	}
}
