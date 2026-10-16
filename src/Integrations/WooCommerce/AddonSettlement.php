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
 *   on the order. No line is written or removed and no money moved. The line then holds no module or
 *   cycle and is never added ({@see AddonService::released()}), and once every line of the order is done
 *   the order is marked fulfilled (`_wplm_plan_fulfilled`). The add-ons subscription the line opened or
 *   joined (`_wplm_addons_subscription_id`) is cancelled — never deleted — when it never billed and holds
 *   nothing ({@see AddonService::end_unbilled_addons_subscription()}): left pending it would keep the
 *   licence's add-ons on its cycle for good. One that billed, or holds another order's lines, is left as
 *   it is. The note names what settling leaves: add-on lines an attempt cut off before recording them
 *   left on the licence ({@see AddonService::unrecorded_lines()});
 * - a line whose add-ons are on the licence already, which completing the order again records
 *   ({@see AddonService::retry_records()}), is never settled;
 * - "Undo settled add-ons" (a tick on each settled line, and its own order action and note) reopens a
 *   settled line: its stamp moves to `_wplm_addon_settled_history`, the order's fulfilled mark goes when
 *   a line is left to do, and completing the order again adds the line through the usual checks. Undo
 *   writes no line and brings back no cancelled subscription.
 *
 * Needs `manage_woocommerce` and that form's nonce. Settling, or undoing, a line twice changes nothing.
 */
class AddonSettlement {

	/** The order action, and the nonce action's prefix. */
	public const ACTION = 'wplm_settle_addons';

	/** The order line meta that records it. */
	public const META = '_wplm_addon_settled';

	/** The "Undo settled add-ons" order action, and its nonce action's prefix. */
	public const UNDO_ACTION = 'wplm_unsettle_addons';

	/** The order line meta that keeps every undone settlement (JSON list). */
	public const HISTORY_META = '_wplm_addon_settled_history';

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
		add_action( 'woocommerce_order_action_' . self::UNDO_ACTION, array( $this, 'handle_undo_action' ) );
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

	/**
	 * The lines of an order whose add-ons are marked settled (which "Undo settled add-ons" reopens).
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, \WC_Order_Item_Product> By item id.
	 */
	public function settled_items( \WC_Order $order ): array {
		$found = array();
		foreach ( $order->get_items() as $id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && AddonService::settled( $item ) ) {
				$found[ (int) $id ] = $item;
			}
		}
		return $found;
	}

	// -------------------------------------------------------------------------
	// The order screen
	// -------------------------------------------------------------------------

	/**
	 * `woocommerce_order_actions`: offer "Mark add-ons settled" on an order with a line to settle, and
	 * "Undo settled add-ons" on an order with a settled line.
	 *
	 * @param mixed $actions Action key => label.
	 * @param mixed $order   The order (WooCommerce 7.x+), else the screen's.
	 * @return mixed
	 */
	public function order_actions( $actions, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : ( $GLOBALS['theorder'] ?? null );
		if ( ! is_array( $actions ) || ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		if ( array() !== $this->settleable_items( $order ) ) {
			$actions[ self::ACTION ] = __( 'Mark add-ons settled', 'wp-license-manager' );
		}
		if ( array() !== $this->settled_items( $order ) ) {
			$actions[ self::UNDO_ACTION ] = __( 'Undo settled add-ons', 'wp-license-manager' );
		}
		return $actions;
	}

	/**
	 * `woocommerce_order_actions_end`: the note and the nonce for "Mark add-ons settled", and for "Undo
	 * settled add-ons" — each only where it applies.
	 *
	 * @param mixed $order_id The order id.
	 * @return void
	 */
	public function render_note_field( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( array() !== $this->settled_items( $order ) ) {
			echo '<li class="wide wplm-unsettle-addons">';
			echo '<label for="wplm_unsettle_note"><strong>' . esc_html__( 'Undo settled add-ons', 'wp-license-manager' ) . '</strong></label>';
			echo '<textarea id="wplm_unsettle_note" name="wplm_unsettle_note" rows="2" style="width:100%;" placeholder="' . esc_attr__( 'Note (optional), e.g. the customer paid after all', 'wp-license-manager' ) . '"></textarea>';
			echo '<p class="description">' . esc_html__( 'To reopen settled add-on lines: tick them in the items list, choose "Undo settled add-ons" above, then Update. Undo adds, removes, bills and refunds nothing; the lines hold their modules again, and setting the order to Completed again adds them, with the usual checks.', 'wp-license-manager' ) . '</p>';
			wp_nonce_field( self::UNDO_ACTION . '_' . $order->get_id(), 'wplm_unsettle_nonce', false );
			echo '</li>';
		}
		if ( array() === $this->settleable_items( $order ) ) {
			return;
		}
		echo '<li class="wide wplm-settle-addons">';
		echo '<label for="wplm_settle_note"><strong>' . esc_html__( 'Mark add-ons settled', 'wp-license-manager' ) . '</strong></label>';
		echo '<textarea id="wplm_settle_note" name="wplm_settle_note" rows="2" style="width:100%;" placeholder="' . esc_attr__( 'Note (optional), e.g. refunded by bank transfer', 'wp-license-manager' ) . '"></textarea>';
		echo '<p class="description">' . esc_html__( 'For add-on lines that could not be added and will not be: tick them in the items list, choose "Mark add-ons settled" above, then Update. Settling adds, removes, bills and refunds nothing; the lines stop holding their modules and are never added later, and an add-ons subscription opened for them that never billed is cancelled.', 'wp-license-manager' ) . '</p>';
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
			if ( current_user_can( 'manage_woocommerce' ) ) {
				printf(
					'<div class="wplm-unsettle-item"><label><input type="checkbox" name="wplm_unsettle_items[]" value="%1$d" /> %2$s</label> <small>%3$s</small></div>',
					(int) $item_id,
					esc_html__( 'Undo settled add-ons', 'wp-license-manager' ),
					esc_html__( '(then choose "Undo settled add-ons" in Order actions)', 'wp-license-manager' )
				);
			}
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
	 * settled nor be overtaken. Writes or removes no line and moves no money; an add-ons subscription the
	 * line opened or joined that never billed and holds nothing is cancelled (never deleted), and the note
	 * says so. Add-on lines left on the licence unrecorded are named in the note and the message.
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
			$cancelled  = array(); // Add-ons subscriptions ended here, by id.
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
				$sub_id    = (int) $item->get_meta( '_wplm_addons_subscription_id' );
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
				// An add-ons subscription the line opened (or joined) that never billed and holds nothing
				// would keep the licence's add-ons on its cycle for good: it ends with the line. Cancelled,
				// never deleted; one holding lines, or billing, is never touched.
				if ( $sub_id > 0 && ! isset( $cancelled[ $sub_id ] ) && $this->addons->end_unbilled_addons_subscription( $sub_id ) ) {
					$cancelled[ $sub_id ] = true;
				}
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
				$text .= ' ' . ( isset( $cancelled[ $sub_id ] )
					? sprintf(
						/* translators: %d: subscription id */
						__( 'Settling added, removed, billed and refunded nothing; the line no longer holds its modules or add-ons cycle and is never added later. Its add-ons subscription #%d never billed and was cancelled.', 'wp-license-manager' ),
						$sub_id
					)
					: __( 'Settling added, removed, billed and refunded nothing; the line no longer holds its modules or add-ons cycle and is never added later.', 'wp-license-manager' ) );
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
	// Undo
	// -------------------------------------------------------------------------

	/**
	 * `woocommerce_order_action_wplm_unsettle_addons`: run on the order screen's Update (posts and HPOS
	 * alike). A refusal is shown on the order screen.
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	public function handle_undo_action( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_undo_request().
		$result = $this->handle_undo_request( $order, wp_unslash( $_POST ) );
		if ( ! $result['ok'] && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( $result['message'] );
		}
	}

	/**
	 * Check the request (capability and nonce), then reopen the ticked settled lines.
	 *
	 * @param \WC_Order $order   The order.
	 * @param array     $request The posted form, unslashed: `wplm_unsettle_nonce`, `wplm_unsettle_items[]`,
	 *                           `wplm_unsettle_note`.
	 * @return array{ok: bool, message: string, settled: int[]} `settled`: the lines reopened.
	 */
	public function handle_undo_request( \WC_Order $order, array $request ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return self::result( false, __( 'You are not allowed to undo settled add-ons.', 'wp-license-manager' ) );
		}
		$nonce = isset( $request['wplm_unsettle_nonce'] ) ? sanitize_text_field( (string) $request['wplm_unsettle_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::UNDO_ACTION . '_' . $order->get_id() ) ) {
			return self::result( false, __( 'The page had expired, so no settled add-ons were reopened. Reload the order and try again.', 'wp-license-manager' ) );
		}
		$items = isset( $request['wplm_unsettle_items'] ) ? array_map( 'absint', (array) $request['wplm_unsettle_items'] ) : array();
		$note  = isset( $request['wplm_unsettle_note'] ) ? sanitize_textarea_field( (string) $request['wplm_unsettle_note'] ) : '';
		return $this->unsettle( $order->get_id(), $items, get_current_user_id(), $note );
	}

	/**
	 * Reopen settled order lines: the mirror of {@see settle()}. Each line's stamp moves into
	 * `_wplm_addon_settled_history` (appended: `settled` — the stamp —, `undone_by`, `undone_at` UTC,
	 * `note`) and `_wplm_addon_settled` is deleted, so the line holds its modules and add-ons cycle again;
	 * the order's fulfilled mark goes when a line of it is left to do ({@see AddonService::order_lines_done()}),
	 * so completing the order again adds the line through the usual checks — it may join or open an
	 * add-ons subscription, or be refused again with the usual note. Writes no line and brings back no
	 * subscription settling cancelled. A line of the order that is not settled is left as it is (no note).
	 * Claims the order's fulfilment lock, like settling.
	 *
	 * @param int    $order_id The order.
	 * @param int[]  $item_ids Its lines.
	 * @param int    $user_id  Who reopens them.
	 * @param string $note     Why (optional).
	 * @param int    $now      Unix time, 0 for now (tests).
	 * @return array{ok: bool, message: string, settled: int[]} `settled`: the lines reopened.
	 */
	public function unsettle( int $order_id, array $item_ids, int $user_id, string $note = '', int $now = 0 ): array {
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( array() === $item_ids ) {
			return self::result( false, __( 'Tick the settled add-on lines to reopen in the items list, then choose "Undo settled add-ons" again.', 'wp-license-manager' ) );
		}
		$lock = 'wplm_fulfil_lock_' . $order_id;
		if ( ! \WPLM\Support\Lock::claim( $lock, PlanCheckout::FULFIL_LOCK_TTL ) ) {
			return self::result( false, __( 'This order is being fulfilled right now, so no settled add-ons were reopened. Try again in a few minutes.', 'wp-license-manager' ) );
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return self::result( false, __( 'That order no longer exists.', 'wp-license-manager' ) );
			}
			$items    = $order->get_items();
			$by       = self::user_name( $user_id );
			$note     = trim( $note );
			$reopened = array();
			$foreign  = array();
			foreach ( $item_ids as $id ) {
				$item = $items[ $id ] ?? null;
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					$foreign[] = '#' . $id;
					continue;
				}
				if ( ! AddonService::settled( $item ) ) {
					continue; // Not settled (or reopened before): nothing to do.
				}
				$history   = json_decode( (string) $item->get_meta( self::HISTORY_META ), true );
				$history   = is_array( $history ) ? $history : array();
				$history[] = array(
					'settled'   => self::stamp( $item ),
					'undone_by' => $user_id,
					'undone_at' => gmdate( 'Y-m-d H:i:s', $now > 0 ? $now : time() ),
					'note'      => $note,
				);
				$item->update_meta_data( self::HISTORY_META, wp_json_encode( $history ) );
				$item->delete_meta_data( self::META );
				$item->save_meta_data();
				$order->add_order_note(
					'' !== $note
						? sprintf(
							/* translators: 1: order line name, 2: user name, 3: the note */
							__( 'WPLM: settled add-ons on line "%1$s" reopened by %2$s: %3$s — set the order to Completed again to add them.', 'wp-license-manager' ),
							$item->get_name(),
							$by,
							$note
						)
						: sprintf(
							/* translators: 1: order line name, 2: user name */
							__( 'WPLM: settled add-ons on line "%1$s" reopened by %2$s — set the order to Completed again to add them.', 'wp-license-manager' ),
							$item->get_name(),
							$by
						)
				);
				$reopened[] = $id;
			}

			if ( array() !== $reopened && '1' === (string) $order->get_meta( '_wplm_plan_fulfilled' ) && ! $this->addons->order_lines_done( $order ) ) {
				$order->delete_meta_data( '_wplm_plan_fulfilled' );
				$order->save();
			}
		} finally {
			\WPLM\Support\Lock::release( $lock );
		}

		if ( array() !== $foreign ) {
			/* translators: %s: order line ids */
			return self::result( false, sprintf( __( 'These are not lines of this order, so they were not reopened: %s.', 'wp-license-manager' ), implode( ', ', $foreign ) ), $reopened );
		}
		return self::result(
			true,
			array() !== $reopened
				? __( 'Settled add-ons reopened. Set the order to Completed again to add them.', 'wp-license-manager' )
				: __( 'Those lines were not settled: nothing to undo.', 'wp-license-manager' ),
			$reopened
		);
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
