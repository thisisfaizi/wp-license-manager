<?php
/**
 * Storefront + checkout integration for plan-assigned WooCommerce products.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\License;
use WPLM\Models\Package;
use WPLM\Models\Plan;
use WPLM\Services\AddonService;
use WPLM\Services\EntitlementService;
use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Services\PackageExtrasService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\SubscriptionService;

/**
 * Drives the full lifecycle for any native WooCommerce product that has a WPLM
 * subscription plan assigned (_wplm_plan_id):
 *
 *   1. Renders the plan's packages as selectable options on the product page.
 *   2. Validates a package is chosen and stores it in the cart item.
 *   3. Overrides the cart line price to the chosen package's price (+ sign-up fee).
 *   4. Persists the package on the order line item.
 *   5. On payment, creates the subscription (recurring) and/or issues the license
 *      using the package's billing + licensing rules.
 *
 * A plan whose kind is `addon` sells add-ons for a licence the customer already has: the product
 * page asks which of their licences to add it to, nothing is sold to a logged-out visitor, and
 * fulfilment adds lines to that licence instead of issuing a key ({@see AddonService}).
 *
 * A plan whose kind is `dynamic` has no stored packages: the customer builds one on the product page
 * (a form a product add-on prints on `wplm_dynamic_package_form`), and the package is made by the
 * `wplm_dynamic_package` filter from what they chose. The server rebuilds it from the choice on add to
 * cart, on every cart total, at checkout and when the order line is saved; a price the browser sends
 * is never used. The order line keeps a snapshot of the package, and fulfilment issues from that
 * snapshot, so a later price change never alters a sale ({@see self::dynamic_package()}).
 *
 * Package extras (1.3.0): beside a licence type the customer may tick extras — modules and extra
 * quantities — priced by the `wplm_package_extras` filter ({@see PackageExtrasService}). The form is a
 * product add-on's (`wplm_package_extras_form`), or WPLM's plain fallback. The cart keeps only the
 * choice (`wplm_extras`); the price is rebuilt from the filter on every pass, the order line keeps a
 * snapshot (`_wplm_extras`), and fulfilment issues one key with the extras on it — one subscription
 * billing the base and every extra when recurring. On an add-on plan the same extras are added to a
 * licence the customer already has.
 *
 * Products stay 100% native WooCommerce — no custom product types — so none of
 * the WC variable-product internals are involved.
 */
class PlanCheckout {

	/** @var PlanService */
	private PlanService $plans;

	/** @var GeneratorService */
	private GeneratorService $generators;

	/** @var LicenseService */
	private LicenseService $licenses;

	/** @var SubscriptionService */
	private SubscriptionService $subscriptions;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var AddonService|null */
	private ?AddonService $addons;

	/** @var PackageExtrasService|null */
	private ?PackageExtrasService $extras;

	/** @var array<int,\WPLM\Models\Plan|null> Per-request plan cache. */
	private array $plan_cache = array();

	/** @var array<string, array> Per-request eligible licences, by user and plan. */
	private array $target_cache = array();

	/**
	 * @param PlanService         $plans
	 * @param GeneratorService    $generators
	 * @param LicenseService      $licenses
	 * @param SubscriptionService $subscriptions
	 * @param EntitlementService  $entitlements
	 * @param ProfileRegistry     $profiles
	 * @param AddonService|null   $addons   Add-ons for existing licences (resolved from the container when null).
	 * @param PackageExtrasService|null $extras Package extras (resolved from the container when null).
	 */
	public function __construct(
		PlanService $plans,
		GeneratorService $generators,
		LicenseService $licenses,
		SubscriptionService $subscriptions,
		EntitlementService $entitlements,
		ProfileRegistry $profiles,
		?AddonService $addons = null,
		?PackageExtrasService $extras = null
	) {
		$this->addons        = $addons;
		$this->extras        = $extras;
		$this->plans         = $plans;
		$this->generators    = $generators;
		$this->licenses      = $licenses;
		$this->subscriptions = $subscriptions;
		$this->entitlements  = $entitlements;
		$this->profiles      = $profiles;
	}

	/** Register all storefront + checkout hooks. */
	public function register(): void {
		// Storefront: package selector on the product page.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_package_options' ) );

		// Price display + purchasability driven by the plan's packages, not the
		// (now redundant) WooCommerce product price.
		add_filter( 'woocommerce_get_price_html', array( $this, 'plan_price_html' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'plan_is_purchasable' ), 20, 2 );

		// Cart capture + pricing + display.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_cart_item' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_package_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

		// Add-ons: why a product cannot be bought here, and a re-check of every add-on line in the cart.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_addon_notice' ), 31 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_addons' ) );

		// Packages built at purchase: one per cart line (one licence), re-checked before checkout.
		add_filter( 'woocommerce_is_sold_individually', array( $this, 'dynamic_sold_individually' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_quantity', array( $this, 'dynamic_quantity' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_dynamic' ) );

		// Package extras: one licence per line, re-checked (and re-priced) before checkout.
		add_filter( 'woocommerce_add_to_cart_quantity', array( $this, 'extras_quantity' ), 21, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_extras' ) );

		// Persist the package on the order line item.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item' ), 10, 4 );

		// Fulfilment: Completed only. Payment alone (Processing) does not issue a
		// licence — the shop confirms the order first. Priority 5, because WooCommerce
		// sends the completed-order email on this hook at 10 and that email carries the
		// keys: they have to exist before it renders.
		add_action( 'woocommerce_order_status_completed', array( $this, 'fulfill_order' ), 5, 1 );

		// A refunded add-on order takes back exactly what it added; the licence is never suspended.
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revert_addons' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Storefront
	// -------------------------------------------------------------------------

	/** Render the package radio options on the single-product page. */
	public function render_package_options(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$plan = $this->plan_for_product( $product );
		if ( null === $plan ) {
			return;
		}
		if ( $plan->is_dynamic() ) {
			/**
			 * Print the form a customer builds their package with. Its fields must post as
			 * `wplm_dynamic[...]`; that array is passed to `wplm_dynamic_package` as the spec.
			 *
			 * @param Plan        $plan    The dynamic plan.
			 * @param \WC_Product $product The product being viewed.
			 */
			do_action( 'wplm_dynamic_package_form', $plan, $product );
			return;
		}

		$packages = array_filter( $plan->packages, static fn( Package $p ) => 1 === (int) $p->status );
		if ( $plan->is_addon() ) {
			// An add-on plan may sell only package extras, with no stored packages at all.
			$this->render_addon_options( $plan, $packages );
			return;
		}
		if ( empty( $packages ) ) {
			return;
		}

		echo '<div class="wplm-packages" style="margin:16px 0;">';
		echo '<p style="font-weight:600;margin-bottom:8px;">' . esc_html__( 'Choose a license type', 'wp-license-manager' ) . '</p>';

		$first = true;
		foreach ( $packages as $pkg ) {
			$input_id = 'wplm-pkg-' . (int) $pkg->id;
			echo '<label for="' . esc_attr( $input_id ) . '" style="display:block;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:8px;cursor:pointer;">';
			printf(
				'<input type="radio" id="%1$s" name="wplm_package_id" value="%2$d" %3$s style="margin-right:8px;"> <strong>%4$s</strong> — %5$s',
				esc_attr( $input_id ),
				(int) $pkg->id,
				checked( $first, true, false ),
				esc_html( $pkg->name ),
				wp_kses_post( $this->price_label( $pkg ) )
			);

			if ( ! empty( $pkg->benefits ) ) {
				echo '<ul style="margin:8px 0 0 28px;">';
				foreach ( $pkg->benefits as $benefit ) {
					echo '<li>' . esc_html( $benefit ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</label>';
			$first = false;
		}
		echo '</div>';

		if ( null !== $plan->profile ) {
			$offers = array();
			$prices = array();
			foreach ( $packages as $pkg ) {
				$prices[ (int) $pkg->id ] = $this->first_payment_amount( $pkg );
				$ctx                      = $this->extras()->context_for_package( $plan, $pkg );
				$found                    = null !== $ctx ? $this->extras()->offers( $ctx ) : array();
				if ( array() !== $found ) {
					$offers[ (int) $pkg->id ] = $found;
				}
			}
			$this->render_extras_form( $plan, PackageExtrasService::FLOW_NEW, $offers, array(), $prices, (int) reset( $packages )->id );
		}
	}

	/**
	 * The extras form, after the licence-type radios (new purchase) or the licence picker (add-ons):
	 * a product add-on prints it on `wplm_package_extras_form`; with nothing hooked WPLM prints a plain
	 * one ({@see render_extras_fallback()}).
	 *
	 * @param Plan                 $plan        The plan.
	 * @param string               $flow        `new` or `existing`.
	 * @param array<int, array[]>  $by_package  New purchase: offers per package id.
	 * @param array<int, array[]>  $by_license  Existing licence: offers per licence id.
	 * @param array<int, float>    $base_prices New purchase: what each package costs now (fee included).
	 * @param int                  $selected    The package or licence selected when the page opens.
	 * @return void
	 */
	private function render_extras_form( Plan $plan, string $flow, array $by_package, array $by_license, array $base_prices, int $selected ): void {
		$data = array(
			'plan'              => $plan,
			'flow'              => $flow,
			'offers_by_package' => $by_package,
			'offers_by_license' => $by_license,
			'base_prices'       => $base_prices,
			'currency'          => PackageExtrasService::currency(),
		);
		if ( has_action( 'wplm_package_extras_form' ) ) {
			/**
			 * Print the extras form inside the add-to-cart form. Its fields must post as
			 * `wplm_extras[<offer id>] = <quantity>`; prices are rebuilt on the server whatever it posts.
			 *
			 * @param array $data plan, flow (new|existing), offers_by_package, offers_by_license,
			 *                    base_prices (package id => first payment), currency.
			 */
			do_action( 'wplm_package_extras_form', $data );
			return;
		}
		$this->render_extras_fallback( $data, $selected );
	}

	/**
	 * WPLM's own extras form: a fieldset of checkboxes (bought once) and number inputs (quantities) per
	 * package or licence. Only the selected one is shown and enabled — the others are disabled, so
	 * their same-named fields never post.
	 *
	 * @param array $data     The form data ({@see render_extras_form()}).
	 * @param int   $selected The package or licence selected when the page opens.
	 * @return void
	 */
	private function render_extras_fallback( array $data, int $selected ): void {
		$existing = PackageExtrasService::FLOW_EXISTING === $data['flow'];
		$groups   = $existing ? $data['offers_by_license'] : $data['offers_by_package'];
		if ( array() === $groups ) {
			return;
		}
		echo '<div class="wplm-extras-fallback" data-wplm-flow="' . esc_attr( $data['flow'] ) . '">';
		foreach ( $groups as $key => $offers ) {
			$on = (int) $key === $selected;
			printf(
				'<fieldset class="wplm-extras" data-wplm-for="%1$d"%2$s%3$s style="margin:0 0 16px;">',
				(int) $key,
				$on ? '' : ' hidden',
				$on ? '' : ' disabled'
			);
			echo '<legend style="font-weight:600;">' . esc_html__( 'Add-ons', 'wp-license-manager' ) . '</legend>';
			foreach ( $offers as $offer ) {
				$input = 'wplm-extra-' . (int) $key . '-' . sanitize_html_class( str_replace( ':', '-', $offer['id'] ) );
				$name  = 'wplm_extras[' . $offer['id'] . ']';
				$price = function_exists( 'wc_price' ) ? wc_price( $offer['unit_price'] ) : number_format_i18n( $offer['unit_price'], 2 );
				echo '<p style="margin:0 0 8px;">';
				if ( 1 === (int) $offer['max_qty'] ) {
					printf(
						'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"> %3$s — %4$s</label>',
						esc_attr( $input ),
						esc_attr( $name ),
						esc_html( $offer['label'] ),
						wp_kses_post( $price )
					);
				} else {
					printf(
						'<label for="%1$s">%3$s — %4$s %5$s</label> <input type="number" id="%1$s" name="%2$s" value="0" min="0" max="%6$d" step="1" inputmode="numeric" style="width:5em;">',
						esc_attr( $input ),
						esc_attr( $name ),
						esc_html( $offer['label'] ),
						wp_kses_post( $price ),
						esc_html__( 'each', 'wp-license-manager' ),
						(int) $offer['max_qty']
					);
				}
				if ( '' !== $offer['description'] ) {
					echo '<br><small>' . esc_html( $offer['description'] ) . '</small>';
				}
				echo '</p>';
			}
			echo '</fieldset>';
		}
		echo '</div>';
		?>
		<script>
		(function(){
			var existing = <?php echo $existing ? 'true' : 'false'; ?>;
			function show(key){
				document.querySelectorAll('.wplm-extras').forEach(function(set){
					var on = set.getAttribute('data-wplm-for') === String(key);
					set.hidden = !on;
					set.disabled = !on;
				});
			}
			if ( existing ) {
				var sel = document.getElementById('wplm-target-license');
				if ( sel ) { sel.addEventListener('change', function(){ show(sel.value); }); }
			} else {
				document.querySelectorAll('input[name="wplm_package_id"]').forEach(function(radio){
					radio.addEventListener('change', function(){ if ( radio.checked ) { show(radio.value); } });
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * The add-on form: which of the customer's licences to add to, then the add-ons that fit it.
	 * Each add-on lists the licences it fits; picking another licence shows only those. The server
	 * checks again when it is added to the cart, at checkout, and when the order is fulfilled.
	 *
	 * @param \WPLM\Models\Plan $plan     The add-on plan.
	 * @param Package[]         $packages Its active packages.
	 * @return void
	 */
	private function render_addon_options( $plan, array $packages ): void {
		$targets = $this->targets( get_current_user_id(), $plan );
		if ( array() === $targets ) {
			return; // render_addon_notice() says why.
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preselection only.
		$wanted   = isset( $_GET['wplm_license'] ) ? absint( wp_unslash( $_GET['wplm_license'] ) ) : 0;
		$selected = isset( $targets[ $wanted ] ) ? $wanted : (int) array_key_first( $targets );

		echo '<div class="wplm-packages wplm-addon" style="margin:16px 0;">';
		echo '<p style="margin-bottom:8px;"><label for="wplm-target-license" style="font-weight:600;">' . esc_html__( 'Add to licence', 'wp-license-manager' ) . '</label><br>';
		echo '<select id="wplm-target-license" name="wplm_target_license_id">';
		foreach ( $targets as $id => $target ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $id,
				selected( $selected, $id, false ),
				esc_html( ucfirst( AddonService::licence_label( $target['license'] ) ) )
			);
		}
		// Licences that cannot take an add-on now are listed, disabled, with the reason.
		foreach ( $this->addons()->blocked_targets( get_current_user_id(), $plan ) as $id => $blocked ) {
			printf(
				'<option value="%1$d" disabled>%2$s</option>',
				(int) $id,
				esc_html( ucfirst( AddonService::licence_label( $blocked['license'] ) ) . ' — ' . $blocked['reason'] )
			);
		}
		echo '</select></p>';
		$fitting = array_filter( $targets, static fn( $t ) => array() !== $t['packages'] );
		if ( array() !== $fitting ) {
			echo '<p style="font-weight:600;margin-bottom:8px;">' . esc_html__( 'Choose an add-on', 'wp-license-manager' ) . '</p>';
		}

		$first = true;
		foreach ( $packages as $pkg ) {
			$fits = array();
			foreach ( $targets as $id => $target ) {
				if ( in_array( $pkg->id, $target['packages'], true ) ) {
					$fits[] = (int) $id;
				}
			}
			if ( array() === $fits ) {
				continue; // Fits none of this customer's licences.
			}
			$shown   = in_array( $selected, $fits, true );
			$checked = $shown && $first;
			if ( $shown ) {
				$first = false;
			}
			$input_id = 'wplm-pkg-' . (int) $pkg->id;
			printf(
				'<label for="%1$s" class="wplm-addon-package" data-wplm-licences="%2$s" style="display:block;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:8px;cursor:pointer;"%3$s>',
				esc_attr( $input_id ),
				esc_attr( implode( ' ', $fits ) ),
				$shown ? '' : ' hidden'
			);
			printf(
				'<input type="radio" id="%1$s" name="wplm_package_id" value="%2$d" %3$s%4$s style="margin-right:8px;"> <strong>%5$s</strong> — %6$s',
				esc_attr( $input_id ),
				(int) $pkg->id,
				checked( $checked, true, false ),
				$shown ? '' : ' disabled',
				esc_html( $pkg->name ),
				wp_kses_post( $this->price_label( $pkg, true ) )
			);
			if ( ! empty( $pkg->benefits ) ) {
				echo '<ul style="margin:8px 0 0 28px;">';
				foreach ( $pkg->benefits as $benefit ) {
					echo '<li>' . esc_html( $benefit ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</label>';
		}
		echo '</div>';

		$by_license = array();
		foreach ( $targets as $id => $target ) {
			if ( ! empty( $target['extras'] ) ) {
				$by_license[ (int) $id ] = $target['extras'];
			}
		}
		$this->render_extras_form( $plan, PackageExtrasService::FLOW_EXISTING, array(), $by_license, array(), $selected );
		?>
		<script>
		(function(){
			var sel = document.getElementById('wplm-target-license');
			if ( ! sel ) { return; }
			sel.addEventListener('change', function(){
				var first = null;
				document.querySelectorAll('.wplm-addon-package').forEach(function(label){
					var on = (' ' + label.getAttribute('data-wplm-licences') + ' ').indexOf(' ' + sel.value + ' ') !== -1;
					var input = label.querySelector('input');
					label.hidden = !on;
					input.disabled = !on;
					if ( !on ) { input.checked = false; } else if ( !first ) { first = input; }
				});
				if ( first && !document.querySelector('.wplm-addon-package input:checked') ) { first.checked = true; }
			});
		})();
		</script>
		<?php
	}

	/**
	 * Why an add-on product has no add-to-cart button for this visitor: logged out, or none of their
	 * licences can take it. Printed under the price, where the button would be.
	 *
	 * @return void
	 */
	public function render_addon_notice(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$plan = $this->plan_for_product( $product );
		if ( null === $plan || ! $plan->is_addon() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$login = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
			echo '<p class="wplm-addon-login woocommerce-info">' . esc_html__( 'Log in to add this to your licence.', 'wp-license-manager' ) . ' <a href="' . esc_url( $login ) . '">' . esc_html__( 'Log in', 'wp-license-manager' ) . '</a></p>';
			return;
		}
		if ( array() === $this->targets( $user_id, $plan ) ) {
			$blocked = $this->addons()->blocked_targets( $user_id, $plan );
			if ( array() === $blocked ) {
				echo '<p class="wplm-addon-none woocommerce-info">' . esc_html__( 'None of your licences can take this add-on. Add-ons are added to an active licence billed the same way (monthly, yearly or lifetime).', 'wp-license-manager' ) . '</p>';
				return;
			}
			echo '<div class="wplm-addon-none woocommerce-info">';
			foreach ( $blocked as $entry ) {
				echo '<p style="margin:0 0 4px;">' . esc_html( ucfirst( AddonService::licence_label( $entry['license'] ) ) . ': ' . $entry['reason'] ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Human-readable price + billing label for a package.
	 *
	 * @param Package $pkg   Package.
	 * @param bool    $addon Whether it is an add-on (paid in full now, then billed with the plan).
	 * @return string
	 */
	private function price_label( Package $pkg, bool $addon = false ): string {
		$price = function_exists( 'wc_price' ) ? wc_price( $pkg->price ) : number_format_i18n( $pkg->price, 2 );

		if ( $addon ) {
			if ( $pkg->is_recurring() ) {
				return $price . ' <small>' . esc_html( AddonService::schedule( $pkg->billing_interval, $pkg->billing_period ) . ' · ' . __( 'paid now in full, then billed with your plan', 'wp-license-manager' ) ) . '</small>';
			}
			if ( Package::TYPE_ONETIME === $pkg->billing_type && (int) $pkg->valid_for_days > 0 ) {
				/* translators: %d: days */
				return $price . ' <small>(' . esc_html( sprintf( __( 'one-time, %d days', 'wp-license-manager' ), (int) $pkg->valid_for_days ) ) . ')</small>';
			}
			return $price . ' <small>(' . esc_html__( 'one-time, lifetime', 'wp-license-manager' ) . ')</small>';
		}

		if ( ! $pkg->is_recurring() ) {
			$suffix = Package::TYPE_LIFETIME === $pkg->billing_type
				? __( 'one-time, lifetime', 'wp-license-manager' )
				: __( 'one-time', 'wp-license-manager' );
			return $price . ' <small>(' . esc_html( $suffix ) . ')</small>';
		}

		$every = $pkg->billing_interval > 1
			? sprintf(
				/* translators: 1: interval number, 2: period */
				__( 'every %1$d %2$s', 'wp-license-manager' ),
				$pkg->billing_interval,
				$pkg->billing_period
			)
			: sprintf(
				/* translators: %s: period */
				__( 'per %s', 'wp-license-manager' ),
				$pkg->billing_period
			);

		$out = $price . ' <small>' . esc_html( $every ) . '</small>';
		if ( $pkg->trial_days > 0 ) {
			/* translators: %d: trial days */
			$out .= ' <small>' . esc_html( sprintf( __( '· %d-day free trial', 'wp-license-manager' ), $pkg->trial_days ) ) . '</small>';
		}
		if ( $pkg->signup_fee > 0 ) {
			$fee  = function_exists( 'wc_price' ) ? wc_price( $pkg->signup_fee ) : (string) $pkg->signup_fee;
			$out .= ' <small>· ' . wp_kses_post( $fee ) . ' ' . esc_html__( 'sign-up fee', 'wp-license-manager' ) . '</small>';
		}
		return $out;
	}

	/**
	 * Replace the product price display with "From {min package price}" for
	 * plan products (the per-package prices are shown in the selector below).
	 *
	 * @param string      $html    Existing price HTML.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function plan_price_html( $html, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}
		$plan = $this->plan_for_product( $product );
		if ( null !== $plan && $plan->is_dynamic() ) {
			/**
			 * The price shown for a product whose package is built at purchase (e.g. "From …").
			 *
			 * @param string      $html    WooCommerce's price HTML (the product's own price).
			 * @param Plan        $plan    The dynamic plan.
			 * @param \WC_Product $product The product.
			 */
			return (string) apply_filters( 'wplm_dynamic_price_html', $html, $plan, $product );
		}
		$packages = $this->active_packages( $product );
		if ( empty( $packages ) ) {
			return $html;
		}

		$min   = min( array_map( static fn( Package $p ) => (float) $p->price, $packages ) );
		$price = function_exists( 'wc_price' ) ? wc_price( $min ) : (string) $min;

		return '<span class="wplm-from-price">' . esc_html__( 'From', 'wp-license-manager' ) . ' ' . $price . '</span>';
	}

	/**
	 * Make plan products purchasable even when no WooCommerce price is set —
	 * the package defines the price.
	 *
	 * @param bool        $purchasable Current state.
	 * @param \WC_Product $product     Product.
	 * @return bool
	 */
	public function plan_is_purchasable( $purchasable, $product ) {
		if ( $product instanceof \WC_Product && $this->is_dynamic_product( $product ) ) {
			return true; // The package (and its price) is built when it is added to the cart.
		}
		$plan = $product instanceof \WC_Product ? $this->plan_for_product( $product ) : null;
		if ( null !== $plan && $plan->is_addon() ) {
			// An add-on is added to one of the buyer's licences: nobody else can buy it. It may sell
			// stored packages, package extras, or both.
			return array() !== $this->targets( get_current_user_id(), $plan );
		}
		if ( $product instanceof \WC_Product && ! empty( $this->active_packages( $product ) ) ) {
			return true;
		}
		return $purchasable;
	}

	/**
	 * The current request's eligible licences for an add-on plan.
	 *
	 * @param int               $user_id The customer.
	 * @param \WPLM\Models\Plan $plan    The add-on plan.
	 * @return array<int, array{license: License, packages: int[]}>
	 */
	private function targets( int $user_id, $plan ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$key = $user_id . ':' . $plan->id;
		if ( ! isset( $this->target_cache[ $key ] ) ) {
			$this->target_cache[ $key ] = $this->addons()->eligible_targets( $user_id, $plan );
		}
		return $this->target_cache[ $key ];
	}

	/** The add-on service (lazily, so the older six-argument constructor keeps working). */
	private function addons(): AddonService {
		if ( null === $this->addons ) {
			$this->addons = \WPLM\Plugin::get_instance()->container()->make( AddonService::class );
		}
		return $this->addons;
	}

	/** The package extras service (lazily, so older constructors keep working). */
	private function extras(): PackageExtrasService {
		if ( null === $this->extras ) {
			$this->extras = \WPLM\Plugin::get_instance()->container()->make( PackageExtrasService::class );
		}
		return $this->extras;
	}

	/**
	 * Active packages for a product's assigned plan (empty when none).
	 *
	 * @param \WC_Product $product Product.
	 * @return Package[]
	 */
	private function active_packages( \WC_Product $product ): array {
		$plan = $this->plan_for_product( $product );
		if ( null === $plan ) {
			return array();
		}
		return array_values( array_filter( $plan->packages, static fn( Package $p ) => 1 === (int) $p->status ) );
	}

	// -------------------------------------------------------------------------
	// Cart
	// -------------------------------------------------------------------------

	/**
	 * Require a valid package selection for plan products.
	 *
	 * @param bool $passed     Current validation state.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity being added.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity = 1 ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $passed;
		}
		$plan = $this->plan_for_product( $product );
		if ( null === $plan ) {
			return $passed;
		}
		if ( $plan->is_dynamic() ) {
			$built = $this->dynamic_package( self::posted_dynamic_spec(), $plan, $product );
			if ( is_wp_error( $built ) ) {
				wc_add_notice( $built->get_error_message(), 'error' );
				return false;
			}
			return $passed;
		}

		$choice = self::posted_extras();
		if ( $plan->is_addon() && array() !== $choice ) {
			// Package extras for an existing licence (they take the line; a package radio is ignored).
			$built = $this->extras_for_cart_item(
				array(
					'data'                  => $product,
					'wplm_extras'           => $choice,
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
					'wplm_addon_license_id' => isset( $_POST['wplm_target_license_id'] ) ? absint( wp_unslash( $_POST['wplm_target_license_id'] ) ) : 0,
				)
			);
			if ( is_wp_error( $built ) ) {
				wc_add_notice( $built->get_error_message(), 'error' );
				return false;
			}
			return $passed;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
		$package_id = isset( $_POST['wplm_package_id'] ) ? absint( wp_unslash( $_POST['wplm_package_id'] ) ) : 0;
		if ( ! $package_id || null === $this->find_package( $plan, $package_id ) ) {
			wc_add_notice( __( 'Please choose a license type before adding to cart.', 'wp-license-manager' ), 'error' );
			return false;
		}
		if ( array() !== $choice ) {
			$built = $this->extras_for_cart_item(
				array(
					'data'            => $product,
					'wplm_package_id' => $package_id,
					'wplm_extras'     => $choice,
				)
			);
			if ( is_wp_error( $built ) ) {
				wc_add_notice( $built->get_error_message(), 'error' );
				return false;
			}
		}
		if ( $plan->is_addon() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
			$license_id = isset( $_POST['wplm_target_license_id'] ) ? absint( wp_unslash( $_POST['wplm_target_license_id'] ) ) : 0;
			$ok         = $this->addon_problem( get_current_user_id(), $license_id, $this->find_package( $plan, $package_id ), (int) $quantity );
			if ( is_wp_error( $ok ) ) {
				wc_add_notice( $ok->get_error_message(), 'error' );
				return false;
			}
		}
		return $passed;
	}

	/**
	 * Whether a customer may add a package to one of their licences (true) or why not.
	 *
	 * @param int          $user_id    The customer.
	 * @param int          $license_id The licence picked.
	 * @param Package|null $pkg        The add-on.
	 * @param int          $qty        Quantity.
	 * @return true|\WP_Error
	 */
	private function addon_problem( int $user_id, int $license_id, ?Package $pkg, int $qty ) {
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'wplm_addon_logged_out', __( 'Log in to add this to your licence.', 'wp-license-manager' ) );
		}
		$license = $license_id > 0 ? $this->licenses->get_by_id( $license_id ) : null;
		if ( null === $license || null === $pkg ) {
			return new \WP_Error( 'wplm_addon_no_licence', __( 'Choose which of your licences to add this to.', 'wp-license-manager' ) );
		}
		return $this->addons()->check_for_user( $user_id, $license, $pkg, $qty );
	}

	/**
	 * `woocommerce_check_cart_items`: check every add-on line again before checkout — the customer,
	 * the licence or the plan may have changed since it was added. A WooCommerce error notice here
	 * stops the checkout.
	 *
	 * @return void
	 */
	public function check_cart_addons(): void {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['wplm_package_id'] ) ) {
				continue;
			}
			$pkg  = $this->lookup_package( (int) $cart_item['wplm_package_id'] );
			$plan = null !== $pkg ? $this->plans->get( $pkg->plan_id ) : null;
			if ( null === $plan || ! $plan->is_addon() ) {
				continue;
			}
			$ok = $this->addon_problem( get_current_user_id(), (int) ( $cart_item['wplm_addon_license_id'] ?? 0 ), $pkg, (int) ( $cart_item['quantity'] ?? 1 ) );
			if ( is_wp_error( $ok ) ) {
				/* translators: 1: add-on name, 2: reason */
				wc_add_notice( sprintf( __( '%1$s: %2$s', 'wp-license-manager' ), $pkg->name, $ok->get_error_message() ), 'error' );
			}
		}
	}

	/**
	 * Store the chosen package id in the cart item data.
	 *
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product id.
	 * @return array
	 */
	public function add_cart_item_data( $data, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product && $this->is_dynamic_product( $product ) ) {
			// The customer's choice only: the package and its price are rebuilt from it on every pass.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
			$posted                    = isset( $_POST['wplm_dynamic'] );
			$data['wplm_dynamic_spec'] = $posted || ! is_array( $data['wplm_dynamic_spec'] ?? null )
				? self::posted_dynamic_spec()
				: map_deep( $data['wplm_dynamic_spec'], 'sanitize_text_field' );
			$data['wplm_unique']       = 'dynamic-' . microtime();
			return $data;
		}
		// The extras choice only (id => quantity): the price is rebuilt from it on every pass.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
		$extras = isset( $_POST['wplm_extras'] ) ? self::posted_extras() : PackageExtrasService::parse_choice( $data['wplm_extras'] ?? array() );
		unset( $data['wplm_extras'] );
		$plan = $product instanceof \WC_Product ? $this->plan_for_product( $product ) : null;
		if ( null === $plan ) {
			$extras = array(); // Only a plan's licence takes extras.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
		$package_id = isset( $_POST['wplm_package_id'] ) ? absint( wp_unslash( $_POST['wplm_package_id'] ) ) : 0;
		if ( array() !== $extras && null !== $plan && $plan->is_addon() ) {
			$package_id = 0; // Extras for an existing licence take the line.
			unset( $data['wplm_package_id'] );
		}
		if ( $package_id ) {
			$data['wplm_package_id'] = $package_id;
			// Ensure each package selection is a distinct cart line.
			$data['wplm_unique'] = $package_id . '-' . microtime();
		}
		if ( array() !== $extras ) {
			$data['wplm_extras'] = $extras;
			$data['wplm_unique'] = 'extras-' . microtime();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
		$license_id = isset( $_POST['wplm_target_license_id'] ) ? absint( wp_unslash( $_POST['wplm_target_license_id'] ) ) : 0;
		if ( ( $package_id || array() !== $extras ) && $license_id ) {
			$data['wplm_addon_license_id'] = $license_id;
		}
		return $data;
	}

	/**
	 * Restore the package id when the cart is loaded from the session.
	 *
	 * @param array $item    Cart item.
	 * @param array $session Session values.
	 * @return array
	 */
	public function restore_cart_item( $item, $session ) {
		if ( isset( $session['wplm_package_id'] ) ) {
			$item['wplm_package_id'] = $session['wplm_package_id'];
		}
		if ( isset( $session['wplm_addon_license_id'] ) ) {
			$item['wplm_addon_license_id'] = $session['wplm_addon_license_id'];
		}
		if ( isset( $session['wplm_dynamic_spec'] ) && is_array( $session['wplm_dynamic_spec'] ) ) {
			$item['wplm_dynamic_spec'] = $session['wplm_dynamic_spec'];
		}
		if ( isset( $session['wplm_extras'] ) && is_array( $session['wplm_extras'] ) ) {
			$item['wplm_extras'] = PackageExtrasService::parse_choice( $session['wplm_extras'] );
		}
		return $item;
	}

	/**
	 * Override the cart line price with the chosen package's first-payment amount.
	 *
	 * @param \WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function apply_package_price( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			if ( isset( $cart_item['wplm_dynamic_spec'] ) && ! empty( $cart_item['data'] ) ) {
				// One configuration is one licence: never more than one of it on a line.
				if ( 1 !== (int) ( $cart_item['quantity'] ?? 1 ) ) {
					$cart->set_quantity( $cart_key, 1, false );
				}
				$built = $this->dynamic_package_for_cart_item( $cart_item );
				if ( $built instanceof Package ) {
					$cart_item['data']->set_price( $built->price );
				}
				continue; // An invalid one is refused by check_cart_dynamic() before checkout.
			}
			if ( ! empty( $cart_item['wplm_extras'] ) && ! empty( $cart_item['data'] ) ) {
				// A licence with extras is one licence: never more than one of it on a line.
				if ( 1 !== (int) ( $cart_item['quantity'] ?? 1 ) ) {
					$cart->set_quantity( $cart_key, 1, false );
				}
				$built = $this->extras_for_cart_item( $cart_item );
				if ( ! is_wp_error( $built ) ) {
					$cart_item['data']->set_price( $built['price'] );
				}
				continue; // An invalid one is refused by check_cart_extras() before checkout.
			}
			if ( empty( $cart_item['wplm_package_id'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			$pkg = $this->lookup_package( (int) $cart_item['wplm_package_id'] );
			if ( null === $pkg ) {
				continue;
			}
			$cart_item['data']->set_price( $this->first_payment_amount( $pkg ) );
		}
	}

	/**
	 * Show the chosen package in cart/checkout line item meta.
	 *
	 * @param array $items     Existing item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $items, $cart_item ) {
		if ( isset( $cart_item['wplm_dynamic_spec'] ) ) {
			$built = $this->dynamic_package_for_cart_item( $cart_item );
			if ( $built instanceof Package ) {
				$items[] = array(
					'key'   => __( 'License Type', 'wp-license-manager' ),
					'value' => wp_strip_all_tags( $built->name . ' — ' . $this->price_label( $built ) ),
				);
			}
			return $items;
		}
		if ( ! empty( $cart_item['wplm_extras'] ) ) {
			$built = $this->extras_for_cart_item( $cart_item );
			if ( is_wp_error( $built ) ) {
				return $items;
			}
			if ( null !== $built['package'] ) {
				$items[] = array(
					'key'   => __( 'License Type', 'wp-license-manager' ),
					'value' => wp_strip_all_tags( $built['package']->name . ' — ' . $this->price_label( $built['package'] ) ),
				);
			}
			$items[] = array(
				'key'   => __( 'Add-ons', 'wp-license-manager' ),
				'value' => PackageExtrasService::describe( $built['items'] ),
			);
			if ( null !== $built['license'] ) {
				$items[] = array(
					'key'   => __( 'Added to', 'wp-license-manager' ),
					'value' => ucfirst( AddonService::licence_label( $built['license'] ) ),
				);
			}
			return $items;
		}
		if ( ! empty( $cart_item['wplm_package_id'] ) ) {
			$pkg = $this->lookup_package( (int) $cart_item['wplm_package_id'] );
			if ( $pkg ) {
				$addon   = ! empty( $cart_item['wplm_addon_license_id'] );
				$items[] = array(
					'key'   => $addon ? __( 'Add-on', 'wp-license-manager' ) : __( 'License Type', 'wp-license-manager' ),
					'value' => wp_strip_all_tags( $pkg->name . ' — ' . $this->price_label( $pkg, $addon ) ),
				);
				$license = $addon ? $this->licenses->get_by_id( (int) $cart_item['wplm_addon_license_id'] ) : null;
				if ( null !== $license ) {
					$items[] = array(
						'key'   => __( 'Added to', 'wp-license-manager' ),
						'value' => ucfirst( AddonService::licence_label( $license ) ),
					);
				}
			}
		}
		return $items;
	}

	/**
	 * Persist the package id (+ plan) on the order line item.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item values.
	 * @param \WC_Order              $order         Order.
	 * @return void
	 */
	public function save_order_line_item( $item, $cart_item_key, $values, $order ): void {
		if ( isset( $values['wplm_dynamic_spec'] ) ) {
			$this->save_dynamic_line_item( $item, $values );
			return;
		}
		if ( ! empty( $values['wplm_extras'] ) ) {
			$this->save_extras_line_item( $item, $values );
			return;
		}
		if ( ! empty( $values['wplm_package_id'] ) ) {
			$item->add_meta_data( '_wplm_package_id', (int) $values['wplm_package_id'], true );
			$pkg = $this->lookup_package( (int) $values['wplm_package_id'] );
			if ( $pkg ) {
				$item->add_meta_data( '_wplm_plan_id', (int) $pkg->plan_id, true );
				$item->add_meta_data( __( 'License Type', 'wp-license-manager' ), $pkg->name, true );
			}
		}
		if ( ! empty( $values['wplm_addon_license_id'] ) ) {
			$item->add_meta_data( '_wplm_addon_license_id', (int) $values['wplm_addon_license_id'], true );
			$license = $this->licenses->get_by_id( (int) $values['wplm_addon_license_id'] );
			if ( null !== $license ) {
				$item->add_meta_data( __( 'Added to', 'wp-license-manager' ), ucfirst( AddonService::licence_label( $license ) ), true );
			}
		}
	}

	/**
	 * Persist a line with package extras: the package (new purchase) or the licence (existing), and a
	 * snapshot of the extras sold — rebuilt now, never taken from the cart — which fulfilment writes from.
	 *
	 * @param \WC_Order_Item_Product $item   Order line item.
	 * @param array                  $values Cart item values.
	 * @return void
	 * @throws \Exception When the extras can no longer be sold as chosen, which stops the checkout.
	 */
	private function save_extras_line_item( $item, array $values ): void {
		$built = $this->extras_for_cart_item( $values );
		if ( is_wp_error( $built ) ) {
			throw new \Exception( esc_html( $built->get_error_message() ) );
		}
		$snapshot = $this->extras()->snapshot( $built['ctx'], $built['items'] );

		$item->set_quantity( 1 );
		$item->add_meta_data( '_wplm_plan_id', (int) $built['ctx']['plan']->id, true );
		if ( null !== $built['package'] ) {
			$item->add_meta_data( '_wplm_package_id', (int) $built['package']->id, true );
			$item->add_meta_data( __( 'License Type', 'wp-license-manager' ), $built['package']->name, true );
		}
		if ( null !== $built['license'] ) {
			$item->add_meta_data( '_wplm_addon_license_id', (int) $built['license']->id, true );
			$item->add_meta_data( __( 'Added to', 'wp-license-manager' ), ucfirst( AddonService::licence_label( $built['license'] ) ), true );
		}
		$item->add_meta_data( '_wplm_extras', wp_json_encode( $snapshot ), true );
		$item->add_meta_data( '_wplm_extras_total', (float) $built['total'], true );
		$item->add_meta_data( __( 'Add-ons', 'wp-license-manager' ), PackageExtrasService::describe( $built['items'] ), true );
	}

	/**
	 * A cart line's extras, priced and checked now from the filter (never from what the browser sent):
	 * with a package id, extras beside a new licence; with a licence id on an add-on plan, extras for
	 * that licence (the add-on checks included, for the current customer).
	 *
	 * @param array $cart_item Cart item (data, wplm_extras, and wplm_package_id or wplm_addon_license_id).
	 * @return array{ctx: array, items: array[], total: float, price: float, package: Package|null, license: License|null}|\WP_Error
	 */
	private function extras_for_cart_item( array $cart_item ) {
		$unavailable = new \WP_Error( 'wplm_extras_unavailable', __( 'These add-ons cannot be bought right now. Please contact us.', 'wp-license-manager' ) );
		$product     = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( (int) ( $cart_item['variation_id'] ?? 0 ) ?: (int) ( $cart_item['product_id'] ?? 0 ) );
		}
		$plan   = $product instanceof \WC_Product ? $this->plan_for_product( $product ) : null;
		$choice = PackageExtrasService::parse_choice( $cart_item['wplm_extras'] ?? array() );
		if ( null === $plan || array() === $choice ) {
			return $unavailable;
		}

		$package = null;
		$license = null;
		if ( $plan->is_addon() ) {
			$user_id    = get_current_user_id();
			$license_id = (int) ( $cart_item['wplm_addon_license_id'] ?? 0 );
			if ( $user_id <= 0 ) {
				return new \WP_Error( 'wplm_addon_logged_out', __( 'Log in to add this to your licence.', 'wp-license-manager' ) );
			}
			$license = $license_id > 0 ? $this->licenses->get_by_id( $license_id ) : null;
			if ( null === $license ) {
				return new \WP_Error( 'wplm_addon_no_licence', __( 'Choose which of your licences to add this to.', 'wp-license-manager' ) );
			}
			$ctx = $this->addons()->extras_context( $plan, $license );
		} else {
			$package = $this->find_package( $plan, (int) ( $cart_item['wplm_package_id'] ?? 0 ) );
			if ( null === $package || 1 !== (int) $package->status ) {
				return new \WP_Error( 'wplm_extras_no_package', __( 'Please choose a license type before adding to cart.', 'wp-license-manager' ) );
			}
			$ctx = $this->extras()->context_for_package( $plan, $package );
		}
		if ( null === $ctx ) {
			return new \WP_Error( 'wplm_extras_none', __( 'Add-ons cannot be added to this licence type.', 'wp-license-manager' ) );
		}

		$chosen = $this->extras()->choose( $ctx, $choice );
		if ( is_wp_error( $chosen ) ) {
			return $chosen;
		}
		if ( null !== $license ) {
			$ok = $this->addons()->check_for_user( get_current_user_id(), $license, $this->addons()->extras_package( $plan, $ctx['billing'], $chosen['items'] ) );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$base = null !== $package ? $this->first_payment_amount( $package ) : 0.0;
		return array(
			'ctx'     => $ctx,
			'items'   => $chosen['items'],
			'total'   => $chosen['total'],
			'price'   => round( $base + $chosen['total'], PackageExtrasService::decimals() ),
			'package' => $package,
			'license' => $license,
		);
	}

	/**
	 * `woocommerce_check_cart_items`: price and check every line with extras again. One that can no
	 * longer be sold as chosen (a price list change, the licence changed) stops the checkout.
	 *
	 * @return void
	 */
	public function check_cart_extras(): void {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['wplm_extras'] ) ) {
				continue;
			}
			$built = $this->extras_for_cart_item( $cart_item );
			if ( is_wp_error( $built ) ) {
				$name = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data']->get_name() : __( 'Add-ons', 'wp-license-manager' );
				/* translators: 1: product name, 2: reason */
				wc_add_notice( sprintf( __( '%1$s: %2$s', 'wp-license-manager' ), $name, $built->get_error_message() ), 'error' );
			}
		}
	}

	/**
	 * `woocommerce_add_to_cart_quantity`: a licence with extras is added one per line.
	 *
	 * @param int $quantity   Requested quantity.
	 * @param int $product_id Product id.
	 * @return int
	 */
	public function extras_quantity( $quantity, $product_id = 0 ) {
		if ( array() === self::posted_extras() ) {
			return $quantity;
		}
		$product = $product_id ? wc_get_product( $product_id ) : null;
		return $product instanceof \WC_Product && null !== $this->plan_for_product( $product ) ? 1 : $quantity;
	}

	/**
	 * The posted `wplm_extras[<offer id>] = <quantity>` choice ({@see PackageExtrasService::parse_choice()}).
	 *
	 * @return array<string, int>
	 */
	private static function posted_extras(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC add-to-cart form, validated by WC; sanitised by parse_choice().
		$raw = isset( $_POST['wplm_extras'] ) ? wp_unslash( $_POST['wplm_extras'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return PackageExtrasService::parse_choice( $raw );
	}

	// -------------------------------------------------------------------------
	// Fulfilment
	// -------------------------------------------------------------------------

	/**
	 * Create subscriptions / issue licenses for every package line item.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function fulfill_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '1' === $order->get_meta( '_wplm_plan_fulfilled' ) ) {
			return;
		}

		$issued  = array();
		$applied = array();
		$failed  = 0;

		/** @var \WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item ) {
			$snapshot = (string) $item->get_meta( '_wplm_dynamic_package' );
			if ( '' !== $snapshot ) {
				// Already issued on an earlier attempt: never issue a second licence.
				if ( '' !== (string) $item->get_meta( '_wplm_license_ids' ) ) {
					continue;
				}
				try {
					$pkg     = $this->package_from_snapshot( $snapshot, (int) $item->get_meta( '_wplm_plan_id' ) );
					$license = $this->fulfill_item( $order, $item, $pkg, $this->stored_dynamic_spec( $item ) );
					if ( $license ) {
						$issued[] = $license;
						$item->update_meta_data( '_wplm_license_ids', wp_json_encode( array( $license->id ) ) );
						$item->save_meta_data();
					}
				} catch ( \Throwable $e ) {
					++$failed;
					\WPLM\Support\Logger::error( 'PlanCheckout: fulfilment failed for a package built at purchase (order item ' . $item->get_id() . ') — ' . $e->getMessage() );
					$order->add_order_note(
						sprintf(
							/* translators: 1: product name, 2: error message */
							__( 'WPLM: a licence could not be issued for "%1$s" — %2$s. Fix the cause, then set the order to Completed again to retry.', 'wp-license-manager' ),
							$item->get_name(),
							$e->getMessage()
						)
					);
				}
				continue;
			}

			$extras     = (string) $item->get_meta( '_wplm_extras' );
			$package_id = (int) $item->get_meta( '_wplm_package_id' );
			if ( ! $package_id ) {
				// Package extras added to a licence the customer already has.
				if ( '' !== $extras && (int) $item->get_meta( '_wplm_addon_license_id' ) > 0 ) {
					$entry = $this->fulfill_existing_extras( $order, $item, $failed );
					if ( null !== $entry ) {
						$applied[] = $entry;
					}
				}
				continue;
			}
			$licence_done = '' !== (string) $item->get_meta( '_wplm_license_ids' );
			$extras_done  = '' !== (string) $item->get_meta( '_wplm_entitlement_ids' ) || '1' === (string) $item->get_meta( '_wplm_addon_reverted' );
			if ( '' === $extras ) {
				// Already issued (or added) on an earlier attempt: never issue a second licence.
				if ( $licence_done || '' !== (string) $item->get_meta( '_wplm_entitlement_ids' ) ) {
					continue;
				}
			} elseif ( $licence_done && $extras_done ) {
				continue; // The licence and its extras: both done on an earlier attempt.
			}
			$pkg = $this->lookup_package( $package_id );
			if ( null === $pkg ) {
				++$failed;
				$order->add_order_note(
					/* translators: %d: package id */
					sprintf( __( 'WPLM: a licence could not be issued — package %d no longer exists.', 'wp-license-manager' ), $package_id )
				);
				continue;
			}

			// An add-on for an existing licence: lines on that licence, never a new key.
			$plan = $this->plans->get( $pkg->plan_id );
			if ( null !== $plan && $plan->is_addon() ) {
				try {
					$target = $this->licenses->get_by_id( (int) $item->get_meta( '_wplm_addon_license_id' ) );
					if ( null === $target ) {
						throw new \RuntimeException( 'the licence it was bought for no longer exists' );
					}
					$applied[] = array(
						'license' => $target,
						'lines'   => $this->addons()->apply( $order, $item, $pkg, $target ),
						'item_id' => $item->get_id(),
					);
				} catch ( \Throwable $e ) {
					++$failed;
					\WPLM\Support\Logger::error( 'PlanCheckout: add-on failed for package ' . $package_id . ' — ' . $e->getMessage() );
					$order->add_order_note(
						sprintf(
							/* translators: 1: add-on name, 2: error message */
							__( 'WPLM: the add-on "%1$s" could not be added — %2$s. Fix the cause, then set the order to Completed again to retry.', 'wp-license-manager' ),
							$pkg->name,
							$e->getMessage()
						)
					);
				}
				continue;
			}

			if ( '' !== $extras ) {
				$this->fulfill_with_extras( $order, $item, $pkg, $extras, $issued, $failed );
				continue;
			}

			try {
				$license = $this->fulfill_item( $order, $item, $pkg );
				if ( $license ) {
					$issued[] = $license;
					$item->update_meta_data( '_wplm_license_ids', wp_json_encode( array( $license->id ) ) );
					$item->save_meta_data();
				}
			} catch ( \Throwable $e ) {
				++$failed;
				\WPLM\Support\Logger::error( 'PlanCheckout: fulfilment failed for package ' . $package_id . ' — ' . $e->getMessage() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: package name, 2: error message */
						__( 'WPLM: a licence could not be issued for "%1$s" — %2$s. Fix the cause, then set the order to Completed again to retry.', 'wp-license-manager' ),
						$pkg->name,
						$e->getMessage()
					)
				);
			}
		}

		// Mark done only when every package line was issued, so a failure stays retryable
		// instead of silently leaving a paying customer without a licence (audit F10).
		if ( 0 === $failed ) {
			$order->update_meta_data( '_wplm_plan_fulfilled', '1' );
		}
		$order->save();

		if ( ! empty( $issued ) ) {
			do_action( 'wplm_order_licenses_issued', $order_id, $issued );
		}

		if ( ! empty( $applied ) ) {
			/**
			 * Fires after an order's add-ons were added to existing licences (no key was issued).
			 *
			 * @param int   $order_id The order.
			 * @param array $applied  One entry per add-on line: array{license: License, lines: Entitlement[], item_id: int}.
			 */
			do_action( 'wplm_order_addons_applied', $order_id, $applied );
		}
	}

	/**
	 * Fulfil a licence bought with package extras: the licence first (as any package line), then the
	 * extras on it. The extras snapshot is checked before a key is generated. Each step is stamped as
	 * it completes (`_wplm_license_ids`, then `_wplm_entitlement_ids`), so a retry does only what is
	 * missing and never issues a second key.
	 *
	 * @param \WC_Order              $order  The order.
	 * @param \WC_Order_Item_Product $item   Its line.
	 * @param Package                $pkg    The licence type.
	 * @param string                 $json   The `_wplm_extras` snapshot.
	 * @param License[]              $issued Licences issued by this order (appended to).
	 * @param int                    $failed Failures (incremented).
	 * @return void
	 */
	private function fulfill_with_extras( \WC_Order $order, $item, Package $pkg, string $json, array &$issued, int &$failed ): void {
		$ids     = json_decode( (string) $item->get_meta( '_wplm_license_ids' ), true );
		$license = is_array( $ids ) && ! empty( $ids ) ? $this->licenses->get_by_id( (int) $ids[0] ) : null;

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			try {
				$this->extras_snapshot_for( $pkg, $json ); // Checked before a key is generated.
				$license  = $this->fulfill_item( $order, $item, $pkg );
				$issued[] = $license;
				$item->update_meta_data( '_wplm_license_ids', wp_json_encode( array( $license->id ) ) );
				$item->save_meta_data();
			} catch ( \Throwable $e ) {
				++$failed;
				\WPLM\Support\Logger::error( 'PlanCheckout: fulfilment failed for package ' . $pkg->id . ' with add-ons — ' . $e->getMessage() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: package name, 2: error message */
						__( 'WPLM: a licence could not be issued for "%1$s" — %2$s. Fix the cause, then set the order to Completed again to retry.', 'wp-license-manager' ),
						$pkg->name,
						$e->getMessage()
					)
				);
				return;
			}
		}
		if ( '' !== (string) $item->get_meta( '_wplm_entitlement_ids' ) || '1' === (string) $item->get_meta( '_wplm_addon_reverted' ) ) {
			return;
		}

		try {
			if ( null === $license ) {
				throw new \RuntimeException( 'the licence issued for this line no longer exists' );
			}
			$snapshot = $this->extras_snapshot_for( $pkg, $json );
			$sub_id   = (int) $item->get_meta( '_wplm_subscription_id' );
			$sub      = $sub_id > 0 ? $this->subscriptions->get( $sub_id ) : null;
			$through  = null;
			if ( $sub_id > 0 ) {
				if ( null === $sub ) {
					throw new \RuntimeException( 'the subscription issued for this line no longer exists' );
				}
				// The extras are paid through the same day as the licence type's lines.
				foreach ( $this->entitlements->lines( $license->id ) as $line ) {
					if ( 'plan' === $line->source && $sub->id === $line->subscription_id && null !== $line->paid_through
						&& ( null === $through || strcmp( $line->paid_through, $through ) > 0 ) ) {
						$through = $line->paid_through;
					}
				}
				if ( null === $through ) {
					throw new \RuntimeException( 'the licence has no paid-through day to align the add-ons with' );
				}
			}
			$this->addons()->apply_extras( $order, $item, $license, $snapshot['items'], $sub, $through );
		} catch ( \Throwable $e ) {
			++$failed;
			\WPLM\Support\Logger::error( 'PlanCheckout: add-ons failed for package ' . $pkg->id . ' (order item ' . $item->get_id() . ') — ' . $e->getMessage() );
			$order->add_order_note(
				sprintf(
					/* translators: 1: package name, 2: error message */
					__( 'WPLM: the licence for "%1$s" was issued, but its add-ons could not be added — %2$s. Fix the cause, then set the order to Completed again: only the add-ons are added, no second key is issued.', 'wp-license-manager' ),
					$pkg->name,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Add the package extras of an add-on line to the licence it was bought for.
	 *
	 * @param \WC_Order              $order  The order.
	 * @param \WC_Order_Item_Product $item   Its line.
	 * @param int                    $failed Failures (incremented).
	 * @return array{license: License, lines: \WPLM\Models\Entitlement[], item_id: int}|null Null when done earlier or failed.
	 */
	private function fulfill_existing_extras( \WC_Order $order, $item, int &$failed ): ?array {
		if ( '' !== (string) $item->get_meta( '_wplm_entitlement_ids' ) || '1' === (string) $item->get_meta( '_wplm_addon_reverted' ) ) {
			return null; // Added on an earlier attempt (or refunded since).
		}
		try {
			$plan = $this->plans->get( (int) $item->get_meta( '_wplm_plan_id' ) );
			if ( null === $plan || ! $plan->is_addon() ) {
				throw new \RuntimeException( 'the add-on plan it was bought from no longer exists' );
			}
			$target = $this->licenses->get_by_id( (int) $item->get_meta( '_wplm_addon_license_id' ) );
			if ( null === $target ) {
				throw new \RuntimeException( 'the licence it was bought for no longer exists' );
			}
			return array(
				'license' => $target,
				'lines'   => $this->addons()->apply_extras_to_licence( $order, $item, $target, $plan, (string) $item->get_meta( '_wplm_extras' ) ),
				'item_id' => $item->get_id(),
			);
		} catch ( \Throwable $e ) {
			++$failed;
			\WPLM\Support\Logger::error( 'PlanCheckout: add-ons failed for order item ' . $item->get_id() . ' — ' . $e->getMessage() );
			$order->add_order_note(
				sprintf(
					/* translators: 1: what was bought, 2: error message */
					__( 'WPLM: the add-ons "%1$s" could not be added — %2$s. Fix the cause, then set the order to Completed again to retry.', 'wp-license-manager' ),
					(string) $item->get_meta( __( 'Add-ons', 'wp-license-manager' ) ),
					$e->getMessage()
				)
			);
			return null;
		}
	}

	/**
	 * A new-purchase line's extras snapshot, checked against its licence type: the plan's profile, the
	 * package billed the way the extras were priced (and with no trial), every line valid.
	 *
	 * @param Package $pkg  The licence type.
	 * @param string  $json The `_wplm_extras` snapshot.
	 * @return array The snapshot.
	 * @throws \RuntimeException When it cannot be fulfilled as it is.
	 */
	private function extras_snapshot_for( Package $pkg, string $json ): array {
		$plan    = $this->plans->get( $pkg->plan_id );
		$profile = null !== $plan && null !== $plan->profile ? $this->profiles->get( $plan->profile ) : null;
		if ( null === $profile ) {
			throw new \RuntimeException( 'the plan sells no licence profile, so it takes no add-ons' );
		}
		$snapshot = $this->extras()->read_snapshot( $json, $pkg->trial_days > 0 ? null : $pkg->billing_key(), $profile );
		if ( PackageExtrasService::FLOW_NEW !== ( $snapshot['flow'] ?? '' ) ) {
			throw new \RuntimeException( 'the add-ons on this line were not sold with a new licence' );
		}
		return $snapshot;
	}

	/**
	 * `woocommerce_order_status_refunded`: take back what the order's add-ons added.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function revert_addons( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->addons()->revert( $order );
		}
	}

	/**
	 * Fulfil a single package line item: create the subscription (recurring) and
	 * issue the bound license using the package's rules.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line item.
	 * @param Package                $pkg   Chosen package.
	 * @param array|null             $dynamic_spec What the customer chose, for a package built at purchase.
	 * @return \WPLM\Models\License|null
	 */
	private function fulfill_item( \WC_Order $order, $item, Package $pkg, ?array $dynamic_spec = null ) {
		$generator_id = $pkg->generator_id ?: (int) get_option( 'wplm_default_generator_id', 0 );
		if ( $generator_id <= 0 ) {
			throw new \RuntimeException( 'no key generator is configured (WPLM → Generators)' );
		}
		// A plan that sells a licence profile issues an entitlement licence. Check
		// its template before anything is created, so a bad template leaves nothing half-issued.
		$plan         = $this->plans->get( $pkg->plan_id );
		$profile_code = null !== $plan ? $plan->profile : null;
		if ( null !== $profile_code ) {
			$profile = $this->profiles->get( $profile_code );
			if ( null === $profile ) {
				throw new \RuntimeException( sprintf( 'the plan sells an unknown licence profile "%s"', $profile_code ) );
			}
			$this->entitlements->normalize_template( $profile, $pkg->entitlements );
		}

		$keys = $this->generators->generate_batch( $generator_id, 1 );
		if ( empty( $keys ) ) {
			throw new \RuntimeException( 'No key generated for package ' . $pkg->id );
		}

		// All subscription/licence dates are UTC; current_time( 'mysql' ) is site-local (audit F7).
		$now = gmdate( 'Y-m-d H:i:s' );

		// A package with no limit issues the default number of computers (Settings, at least 1). Before
		// 1.2.5 a blank limit issued an unlimited licence; licences already issued keep what they have.
		$max_act = null !== $pkg->max_activations && $pkg->max_activations > 0
			? $pkg->max_activations
			: max( 1, (int) get_option( 'wplm_default_max_activations', 1 ) );
		$sub_id  = 0;
		$expires = null;
		$status  = 'active';

		if ( $pkg->is_recurring() ) {
			$trial_end    = $pkg->trial_days > 0 ? $this->add_period( $now, $pkg->trial_days, 'day' ) : null;
			$next_payment = $trial_end ?: $this->add_period( $now, $pkg->billing_interval, $pkg->billing_period );
			$end_date     = null;
			if ( $pkg->length_cycles > 0 ) {
				$end = $now;
				for ( $i = 0; $i < $pkg->length_cycles; $i++ ) {
					$end = $this->add_period( $end, $pkg->billing_interval, $pkg->billing_period );
				}
				$end_date = $end;
			}

			$token_id = null;
			if ( class_exists( '\WC_Payment_Tokens' ) ) {
				$token    = \WC_Payment_Tokens::get_customer_default_token( $order->get_customer_id() );
				$token_id = $token ? $token->get_id() : null;
			}

			$sub    = $this->subscriptions->create(
				array(
					'user_id'          => $order->get_customer_id(),
					'billing_interval' => $pkg->billing_interval,
					'billing_period'   => $pkg->billing_period,
					'recurring_total'  => $pkg->price,
					'currency'         => $order->get_currency(),
					'signup_fee'       => $pkg->signup_fee,
					'trial_end'        => $trial_end,
					'next_payment'     => $next_payment,
					'parent_order_id'  => $order->get_id(),
					'payment_method'   => $order->get_payment_method(),
					'payment_token_id' => $token_id,
					'end_date'         => $end_date,
					'items'            => array(
						array(
							'product_id'   => $item->get_product_id(),
							'variation_id' => $item->get_variation_id() ?: null,
							'quantity'     => null !== $dynamic_spec ? 1 : (int) $item->get_quantity(),
							'line_total'   => $pkg->price,
							'meta'         => array_merge(
								array(
									'package_id'    => $pkg->id,
									'order_item_id' => $item->get_id(),
								),
								null !== $dynamic_spec ? array( 'dynamic_spec' => $dynamic_spec ) : array()
							),
						),
					),
				)
			);
			$sub_id = $sub->id;
			$status = ( null !== $trial_end ) ? 'trial' : 'active';
			$item->update_meta_data( '_wplm_subscription_id', $sub_id );
			$item->save_meta_data();

			// The licence covers exactly what has been paid for (or the trial). Renewals extend
			// it; an unpaid renewal lets it lapse after grace. Previously a recurring licence was
			// perpetual and only a subscription status change could end it (audit F1/F2).
			$expires = $next_payment;
		} elseif ( Package::TYPE_ONETIME === $pkg->billing_type && $pkg->valid_for_days ) {
			$expires = $this->add_period( $now, $pkg->valid_for_days, 'day' );
		}
		// Lifetime: $expires stays null (perpetual).

		$license = $this->licenses->create(
			array(
				'key_string'       => $keys[0],
				'product_id'       => $item->get_product_id(),
				'order_id'         => $order->get_id(),
				'user_id'          => $order->get_customer_id(),
				'max_activations'  => $max_act,
				'overage_strategy' => $pkg->overage_strategy,
				'expires_at'       => $expires,
				'grace_days'       => $pkg->effective_grace_days(),
				'source'           => 3,
				'profile'          => $profile_code,
			)
		);

		// The entitlement licence itself never expires (LicenseRepository drops the expiry); its lines
		// are paid through the last day of the paid term, or lifetime.
		if ( null !== $profile_code ) {
			$this->entitlements->grant_package(
				$license,
				$pkg,
				$sub_id > 0 ? $sub_id : null,
				null !== $expires ? EntitlementService::paid_through_for( $expires ) : null
			);
		}

		if ( $sub_id > 0 ) {
			$this->subscriptions->bind_license( $sub_id, $license->id );
			$this->subscriptions->update_status( $sub_id, $status );
		}

		return $license;
	}

	// -------------------------------------------------------------------------
	// Packages built at purchase (dynamic plans)
	// -------------------------------------------------------------------------

	/**
	 * Build and check the package for a customer's choice on a dynamic plan.
	 *
	 * The package comes from the filter `wplm_dynamic_package( null|Package|\WP_Error $package,
	 * array $spec, Plan $plan, \WC_Product $product )`: return a Package (its `plan_id` the plan's),
	 * or a WP_Error whose message the customer sees. WPLM then holds it to what a dynamic package may
	 * be, whatever the filter returned:
	 *
	 * - billed `recurring` (every 1 month or every 1 year) or `lifetime`;
	 * - a finite price of 0 or more, rounded to the shop's decimals;
	 * - an entitlement template valid for the plan's licence profile, with at least one line;
	 * - no sign-up fee, trial, length or "valid for" days; id 0; status active;
	 * - computers per licence key: the filter's if it is 1 or more, else 1.
	 *
	 * @param array       $spec    What the customer chose (sanitised `wplm_dynamic[...]`).
	 * @param Plan        $plan    The dynamic plan.
	 * @param \WC_Product $product The product it is bought through.
	 * @return Package|\WP_Error
	 */
	public function dynamic_package( array $spec, Plan $plan, \WC_Product $product ) {
		$unavailable = new \WP_Error( 'wplm_dynamic_unavailable', __( 'This plan cannot be bought right now. Please contact us.', 'wp-license-manager' ) );
		if ( ! $plan->is_dynamic() || null === $plan->profile ) {
			return $unavailable;
		}
		$profile = $this->profiles->get( $plan->profile );
		if ( null === $profile ) {
			return $unavailable;
		}

		/**
		 * Build the package a customer chose on a dynamic plan (see PlanCheckout::dynamic_package()).
		 *
		 * @param null|Package|\WP_Error $package Null until a plugin builds it.
		 * @param array                  $spec    What the customer chose.
		 * @param Plan                   $plan    The dynamic plan.
		 * @param \WC_Product            $product The product.
		 */
		$built = apply_filters( 'wplm_dynamic_package', null, $spec, $plan, $product );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		if ( ! $built instanceof Package || (int) $built->plan_id !== (int) $plan->id ) {
			\WPLM\Support\Logger::error( 'PlanCheckout: wplm_dynamic_package returned no package for plan ' . $plan->id . '.' );
			return $unavailable;
		}

		$pkg = clone $built;
		if ( Package::TYPE_RECURRING === $pkg->billing_type ) {
			if ( ! in_array( $pkg->billing_period, array( 'month', 'year' ), true ) || 1 !== (int) $pkg->billing_interval ) {
				\WPLM\Support\Logger::error( 'PlanCheckout: a dynamic package must bill every 1 month or every 1 year (plan ' . $plan->id . ').' );
				return $unavailable;
			}
		} elseif ( Package::TYPE_LIFETIME !== $pkg->billing_type ) {
			\WPLM\Support\Logger::error( 'PlanCheckout: a dynamic package must be recurring or lifetime (plan ' . $plan->id . ').' );
			return $unavailable;
		}
		if ( ! is_finite( $pkg->price ) || $pkg->price < 0 ) {
			\WPLM\Support\Logger::error( 'PlanCheckout: a dynamic package needs a price of 0 or more (plan ' . $plan->id . ').' );
			return $unavailable;
		}
		$decimals   = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$pkg->price = round( $pkg->price, max( 0, $decimals ) );

		try {
			$pkg->entitlements = $this->entitlements->normalize_template( $profile, (array) $pkg->entitlements );
		} catch ( \InvalidArgumentException $e ) {
			\WPLM\Support\Logger::error( 'PlanCheckout: a dynamic package has an invalid template (plan ' . $plan->id . ') — ' . $e->getMessage() );
			return $unavailable;
		}
		if ( array() === $pkg->entitlements ) {
			return $unavailable;
		}

		$pkg->id               = 0;
		$pkg->plan_id          = (int) $plan->id;
		$pkg->name             = '' !== trim( $pkg->name ) ? sanitize_text_field( $pkg->name ) : $plan->name;
		$pkg->billing_interval = 1;
		if ( ! $pkg->is_recurring() ) {
			$pkg->billing_period = 'month';
		}
		$pkg->signup_fee      = 0.0;
		$pkg->trial_days      = 0;
		$pkg->length_cycles   = 0;
		$pkg->valid_for_days  = null;
		$pkg->max_activations = null !== $pkg->max_activations && $pkg->max_activations >= 1 ? (int) $pkg->max_activations : 1;
		$pkg->generator_id    = null !== $pkg->generator_id && $pkg->generator_id > 0 ? (int) $pkg->generator_id : null;
		$pkg->benefits        = array_values( array_filter( array_map( 'strval', (array) $pkg->benefits ) ) );
		$pkg->status          = 1;
		return $pkg;
	}

	/**
	 * `woocommerce_is_sold_individually`: a product whose package is built at purchase is bought one
	 * per line (each line is one licence), so WooCommerce shows no quantity box for it.
	 *
	 * @param bool        $individually Current value.
	 * @param \WC_Product $product      Product.
	 * @return bool
	 */
	public function dynamic_sold_individually( $individually, $product = null ) {
		return $product instanceof \WC_Product && $this->is_dynamic_product( $product ) ? true : $individually;
	}

	/**
	 * `woocommerce_add_to_cart_quantity`: one of a built package per cart line.
	 *
	 * @param int $quantity   Requested quantity.
	 * @param int $product_id Product id.
	 * @return int
	 */
	public function dynamic_quantity( $quantity, $product_id = 0 ) {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		return $product instanceof \WC_Product && $this->is_dynamic_product( $product ) ? 1 : $quantity;
	}

	/**
	 * `woocommerce_check_cart_items`: rebuild every built package in the cart. One that can no longer
	 * be built (the price list changed, a rule now refuses it) stops the checkout with the reason.
	 *
	 * @return void
	 */
	public function check_cart_dynamic(): void {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['wplm_dynamic_spec'] ) ) {
				continue;
			}
			$built = $this->dynamic_package_for_cart_item( $cart_item );
			if ( is_wp_error( $built ) ) {
				$name = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data']->get_name() : __( 'Your plan', 'wp-license-manager' );
				/* translators: 1: product name, 2: reason */
				wc_add_notice( sprintf( __( '%1$s: %2$s', 'wp-license-manager' ), $name, $built->get_error_message() ), 'error' );
			}
		}
	}

	/**
	 * The package for a cart line holding a customer's choice, rebuilt now.
	 *
	 * @param array $cart_item Cart item.
	 * @return Package|\WP_Error
	 */
	private function dynamic_package_for_cart_item( array $cart_item ) {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( (int) ( $cart_item['variation_id'] ?? 0 ) ?: (int) ( $cart_item['product_id'] ?? 0 ) );
		}
		$plan = $product instanceof \WC_Product ? $this->plan_for_product( $product ) : null;
		if ( null === $plan || ! $plan->is_dynamic() ) {
			return new \WP_Error( 'wplm_dynamic_unavailable', __( 'This plan cannot be bought right now. Please contact us.', 'wp-license-manager' ) );
		}
		$spec = is_array( $cart_item['wplm_dynamic_spec'] ?? null ) ? $cart_item['wplm_dynamic_spec'] : array();
		return $this->dynamic_package( $spec, $plan, $product );
	}

	/**
	 * Persist a built package on its order line: the plan, the customer's choice, and a snapshot of
	 * the package (which fulfilment issues from). Never a package id: there is no stored package.
	 *
	 * @param \WC_Order_Item_Product $item   Order line item.
	 * @param array                  $values Cart item values.
	 * @return void
	 * @throws \Exception When the package can no longer be built, which stops the checkout.
	 */
	private function save_dynamic_line_item( $item, array $values ): void {
		$built = $this->dynamic_package_for_cart_item( $values );
		if ( is_wp_error( $built ) ) {
			throw new \Exception( esc_html( $built->get_error_message() ) );
		}
		$spec = is_array( $values['wplm_dynamic_spec'] ?? null ) ? $values['wplm_dynamic_spec'] : array();

		$snapshot = $built->to_array();
		/**
		 * Extra keys for the snapshot of a built package kept on the order line (e.g. which version
		 * of a price list priced it). The package's own fields always win over a returned key.
		 *
		 * @param array   $snapshot The package as an array.
		 * @param Package $package  The package.
		 * @param array   $spec     What the customer chose.
		 */
		$extra    = apply_filters( 'wplm_dynamic_package_snapshot', $snapshot, $built, $spec );
		$snapshot = array_merge( is_array( $extra ) ? $extra : array(), $snapshot );

		$item->set_quantity( 1 );
		$item->add_meta_data( '_wplm_plan_id', (int) $built->plan_id, true );
		$item->add_meta_data( '_wplm_dynamic_spec', wp_json_encode( $spec ), true );
		$item->add_meta_data( '_wplm_dynamic_package', wp_json_encode( $snapshot ), true );
		$item->add_meta_data( __( 'License Type', 'wp-license-manager' ), $built->name, true );
	}

	/**
	 * The package an order line was sold, from its snapshot.
	 *
	 * @param string $json    The `_wplm_dynamic_package` meta.
	 * @param int    $plan_id The line's `_wplm_plan_id`.
	 * @return Package
	 * @throws \RuntimeException When the snapshot is unreadable or belongs to another plan.
	 */
	private function package_from_snapshot( string $json, int $plan_id ): Package {
		$row = json_decode( $json, true );
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'the package bought on this line is unreadable' );
		}
		$pkg = Package::from_row( $row );
		if ( $plan_id <= 0 || $pkg->plan_id !== $plan_id ) {
			throw new \RuntimeException( 'the package bought on this line does not belong to its plan' );
		}
		if ( ! in_array( $pkg->billing_type, array( Package::TYPE_RECURRING, Package::TYPE_LIFETIME ), true ) ) {
			throw new \RuntimeException( 'the package bought on this line has an unknown billing type' );
		}
		$pkg->id = 0;
		return $pkg;
	}

	/**
	 * What the customer chose on an order line (empty when it was not stored).
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @return array
	 */
	private function stored_dynamic_spec( $item ): array {
		$spec = json_decode( (string) $item->get_meta( '_wplm_dynamic_spec' ), true );
		return is_array( $spec ) ? $spec : array();
	}

	/**
	 * The posted `wplm_dynamic[...]` choice, sanitised (text fields only).
	 *
	 * @return array
	 */
	private static function posted_dynamic_spec(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC add-to-cart form, validated by WC; sanitised below.
		$raw = isset( $_POST['wplm_dynamic'] ) ? wp_unslash( $_POST['wplm_dynamic'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return is_array( $raw ) ? map_deep( $raw, 'sanitize_text_field' ) : array();
	}

	/**
	 * Whether a product sells a plan whose package is built at purchase.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private function is_dynamic_product( \WC_Product $product ): bool {
		$plan = $this->plan_for_product( $product );
		return null !== $plan && $plan->is_dynamic();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the plan assigned to a product (parent for variations).
	 *
	 * @param \WC_Product $product Product.
	 * @return \WPLM\Models\Plan|null
	 */
	private function plan_for_product( \WC_Product $product ) {
		$plan_id = (int) $product->get_meta( '_wplm_plan_id' );
		if ( ! $plan_id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$plan_id = (int) $parent->get_meta( '_wplm_plan_id' );
			}
		}
		if ( ! $plan_id ) {
			return null;
		}

		// Per-request memo: the price filter fires for every product on archives.
		if ( ! isset( $this->plan_cache[ $plan_id ] ) ) {
			$this->plan_cache[ $plan_id ] = $this->plans->get( $plan_id, true );
		}
		return $this->plan_cache[ $plan_id ];
	}

	/**
	 * Find a package within a plan by id.
	 *
	 * @param \WPLM\Models\Plan $plan       Plan.
	 * @param int               $package_id Package id.
	 * @return Package|null
	 */
	private function find_package( $plan, int $package_id ) {
		foreach ( $plan->packages as $pkg ) {
			if ( (int) $pkg->id === $package_id ) {
				return $pkg;
			}
		}
		return null;
	}

	/**
	 * Look up a package by id directly (loads its plan for context).
	 *
	 * @param int $package_id Package id.
	 * @return Package|null
	 */
	private function lookup_package( int $package_id ) {
		try {
			$repo = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\PackageRepository::class );
			return $repo->find_by_id( $package_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * First-order amount: sign-up fee + (trial ? 0 : price) for a recurring package. Lifetime and
	 * one-time packages charge their price only: they have no sign-up fee (the plan editor hides it and
	 * the save resets it), and a fee stored on an older row is ignored rather than charged unseen.
	 *
	 * @param Package $pkg Package.
	 * @return float
	 */
	private function first_payment_amount( Package $pkg ): float {
		if ( ! $pkg->is_recurring() ) {
			return $pkg->price;
		}
		$plan = $this->plans->get( $pkg->plan_id );
		if ( null !== $plan && $plan->is_addon() ) {
			return $pkg->price; // An add-on is paid in full now, with no fee or trial.
		}
		$amount = $pkg->signup_fee;
		if ( $pkg->trial_days > 0 ) {
			return $amount; // Trial: only the sign-up fee now.
		}
		return $amount + $pkg->price;
	}

	/**
	 * Add a period to a UTC datetime string.
	 *
	 * @param string $from   Base datetime (Y-m-d H:i:s).
	 * @param int    $n      Number of units.
	 * @param string $period day|week|month|year.
	 * @return string
	 */
	private function add_period( string $from, int $n, string $period ): string {
		$spec = array(
			'day'   => "P{$n}D",
			'week'  => "P{$n}W",
			'month' => "P{$n}M",
			'year'  => "P{$n}Y",
		);
		$dt   = new \DateTime( $from, new \DateTimeZone( 'UTC' ) );
		$dt->add( new \DateInterval( $spec[ $period ] ?? "P{$n}M" ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}
}
