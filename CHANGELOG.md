# Changelog

All notable changes to WP License Manager are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). This project uses [Semantic Versioning](https://semver.org/).

---

## [1.3.0] — 2026-10-15

### Added
- **Package extras at purchase.** Beside a licence type the customer can tick **extras**: modules and
  extra quantities (users, seats, phones…). The result is one key and one bill. A recurring licence gets
  one subscription that bills the base and every extra, and each renewal bills base + extras and extends
  every line. A lifetime licence is one payment. WP License Manager stays product-agnostic: a plugin for
  the licence profile prices the extras through the hooks below.
- **`Package::billing_key()`** returns how a package is priced for extras:
  - `month`: recurring, every 1 month.
  - `year`: recurring, every 1 year.
  - `lifetime`.
  - `null` for anything else (one-time, other intervals).

  A package with a **trial is offered no extras** at purchase: the filter is not even called.
- **Hooks:**
  - `apply_filters( 'wplm_package_extras', array(), array $ctx )` returns the offers for a context.
    - `$ctx` always has `profile`, `plan`, `billing`, `flow` (`new` | `existing`), `package`, `license`
      and `held_modules`.
    - Each offer has `id`, `label`, `group`, `unit_price`, `max_qty`, `lines` (per unit), and optionally
      `requires` (any-of groups of module codes, every group to be met), `term_days` and `description`.
  - `apply_filters( 'wplm_package_extras_snapshot', array $snapshot, array $ctx )` adds keys to the
    order-line snapshot (for example a price-list version). Unknown keys are kept; WPLM's own
    (`billing`, `flow`, `items`) always win.
  - `do_action( 'wplm_package_extras_form', array $data )` prints the form after the licence-type radios.
    - `$data` holds `plan`, `flow`, `offers_by_package`, `offers_by_license`, `base_prices` (each
      package's first payment, sign-up fee included) and `currency`.
    - With nothing hooked, WPLM prints a plain, accessible fallback: a checkbox or number input per
      offer, shown for the selected licence type or licence. The other groups are `disabled`, so their
      same-named fields never post.
- **What WPLM holds an offer to, whatever the filter returns** (`PackageExtrasService::offers()`):
  - **Dropped:**
    - a bad or repeated id (`[a-z0-9:_-]`);
    - a price that is not a finite number of 0 or more;
    - `max_qty` below 1;
    - lines the profile does not allow;
    - a `term` offer without `term_days`;
    - a module offer for a module already held. A term offer is never "held", so packs stack.
  - **Rounded:** prices to the shop's decimals.
  - **Bought once:** a module-only or term offer (`max_qty` forced to 1).
  - **Refused choices** (`PackageExtrasService::choose()`), with a message the customer sees:
    - an id that is not offered (a held module says so);
    - a quantity below 0 or above `max_qty`;
    - a requirement that is not met. Requirements are the profile's `requires` plus the offer's own,
      checked against the held modules and the modules ticked now. On an existing licence, any module
    still live there (not read-only) also meets a requirement, as the add-on checks read it.
- **The browser's price is never used.**
  - The posted field is `wplm_extras[<offer id>] = <qty>`, and the cart keeps only that choice
    (`wplm_extras`).
  - The price is rebuilt from the filter on add to cart, on every cart total (`first payment of the
    base + extras`), in the cart display, in `woocommerce_check_cart_items` (stops the checkout with
    the reason) and when the order line is saved.
  - A line with extras is forced to quantity 1.
- **The order line keeps the sale:**
  - `_wplm_extras` (JSON: `billing`, `flow`, `items` with id, label, qty, unit_price, amount, lines
    already × qty, term_days). It also records what the sale was made against:
    - a new licence: `base_price` and `base_signup_fee`. The subscription is created at that price,
      so the renewal is exactly what the cart showed, even if the licence type's price changes before
      the order is completed;
    - an existing licence: `target_subscription_id` and `target_paid_through` (null for lifetime);
  - `_wplm_extras_total`;
  - a visible **"Add-ons"** entry ("Factory; 2 × Extra phone").
- **Fulfilment writes from the snapshot only:**
  - The snapshot is checked **before a key is generated**: its billing must still be the package's,
    and every line valid for the profile.
  - The licence is issued as before (`source = plan`). The extras are written with `source = addon`
    and the order id.
    - **Recurring:** extras are paid through the same day as the base and join the new subscription,
      one item per extra (`addon`, `extra_id`, `entitlement_ids`). The recurring total is the sum of
      the items.
    - **Lifetime:** extras are undated.
    - **Term** (`term_days`): a dated line that never joins a subscription.
  - **Idempotent:** `_wplm_license_ids` marks the licence done and `_wplm_entitlement_ids` the extras;
    `_wplm_plan_fulfilled` is set only when both are. A retry does only what is missing and never issues
    a second key. An extras step that fails part-way removes every line it wrote.
  - **One fulfilment at a time per order.** Two completions at once (a gateway callback and the return
    page) could both see an unfulfilled line. Fulfilment now first claims the order with an atomic
    insert of the option `wplm_fulfil_lock_{order id}`, reads the order again, and releases the claim
    when done. A claim older than 5 minutes (a request that died) is taken over. A completion that finds
    the order claimed does nothing, logs it, and leaves an order note saying to complete the order again
    after 5 minutes if nothing appears. This covers every plan line, not only extras.
- **Extras for an existing licence.** The add-ons product (plan kind `addon`) prices extras with the same
  filter (`flow = existing`, the licence's billing). It needs **no stored packages**:
  - The product is purchasable, and the licence picker and form render, when the filter offers something
    for one of the customer's licences.
  - The cart keeps `wplm_addon_license_id` + `wplm_extras`.
  - Fulfilment checks the extras as one in-memory add-on (id 0, the add-on plan, the licence's billing),
    so every add-on rule still applies (status, trial, overdue, base module, "already has",
    requirements, `wplm_addon_eligibility`).
  - **Checked again at completion, item by item.** An extra whose module the licence now holds for the
    same term is refused, for example when two orders for the same module were placed before either was
    completed. The line fails with an order note and nothing is added, so the module is never billed
    twice. Term items (packs) are exempt: they stack.
  - **The same module twice in one cart** for one licence is refused when added, and again at checkout.
  - **Aligned to the plan as it is at completion.** If the plan renewed between the sale and completion,
    the extras cover through the new day, and an order note tells the shop (no proration is invented).
    If the licence is billed on another subscription now, or is overdue, the line fails with an order
    note.
  - **Stored add-ons and extras on one plan:** the form adds a "None — only the add-ons ticked below"
    choice, checked by default for a licence that is offered extras. Posting a stored add-on together
    with extras is refused with a notice: one cart line is one or the other.
  - Stored add-on packages (1.2.6) work exactly as before.
  - **Plan editor:** an add-on plan may be saved with no add-on types. It then warns when no plugin
    prices add-ons (`wplm_package_extras`). The plans list shows "Add-ons priced by a plugin", and the
    product **Licensing** tab lists it as "Name (add-ons priced by a plugin)".

- **Recurring add-ons on a lifetime licence.** An add-on with no lifetime price can still be sold to a
  lifetime licence or licence type. The customer pays it monthly or yearly on a subscription that bills
  **only add-ons**, while the licence itself stays lifetime.
  - **Offer:** in a lifetime context only, the filter may return
    `'recurring' => array( 'month' => ?float, 'year' => ?float )` (at least one set) with
    `'unit_price' => null`. WPLM refuses such an offer in any other context, with a unit price as well,
    with a negative price, with no price for either cycle, or as a term. Every sanitised offer carries
    `recurring` (null for an ordinary one).
  - **Cycle:** `wplm_extras_cycle` (`month` | `year`) is posted and kept in the cart. There is one cycle
    per cart line. The default is monthly, unless a ticked recurring add-on has no monthly price; then
    it is yearly. A ticked add-on with no price for the chosen cycle is refused.
  - **Forced cycle:** a licence that already has an add-ons subscription is forced to that
    subscription's cycle, whatever is posted. New recurring add-ons join it, aligned with its
    paid-through day, and are paid in full now.
  - **Today's price** = lifetime base (new purchase only) + lifetime add-ons + one cycle of each
    recurring add-on.
  - **Form data** gains `cycle_by_license` (licence id → the forced `month`/`year`, or null when the
    customer chooses). The fallback form shows a Monthly / Yearly choice when a recurring offer is
    listed.
  - **Snapshot** gains `cycle` on the line and on each item. An item's cycle is null for lifetime and
    term items. Its `unit_price` is the price charged for that cycle. An existing-licence sale also
    records `addons_subscription_id` and `addons_paid_through`.
  - **Fulfilment** creates one add-ons-only subscription, or reuses the licence's open one:
    - billed every 1 cycle, one item per recurring add-on (`addon`, `addons_only`, `entitlement_ids`);
    - `recurring_total` is the sum of its items;
    - the first period is paid now, so its lines are paid through today + 1 cycle;
    - the lines have `source = addon` and the subscription's id.
    - It stays pending until its first line is written, and a retry reuses it
      (`_wplm_addons_subscription_id` on the order line). A changed, on-hold or overdue add-ons
      subscription fails the line with a note; a renewal since the sale is noted.
  - **Renewals** of the add-ons subscription charge only its items and extend only its lines.
  - **Safety:** an add-ons-only subscription is marked by the new column
    `wplm_subscriptions.addons_license_id` and is **never bound through `license_id`**. Every path that
    moves a licence because of a subscription checks for it explicitly and leaves the licence alone:
    - status sync (`SubscriptionService::update_status`, which also covers pause, resume, cancel, REST,
      My Account and the admin screen);
    - renewal extension (`RenewalProcessor::apply_payment`);
    - the seat change on a plan switch;
    - `bind_license()`, which refuses to bind it.
    On hold, cancelled, expired, failed or refunded: only its own lines lapse, after their paid-through
    day plus grace.
  - **Refunds:** a full refund of the order that bought the licence still suspends or revokes the
    licence, and now also cancels its add-ons subscription. A refund of an order that added to an
    existing licence removes only its own add-ons, even when that order opened the add-ons subscription
    and later orders joined it; the subscription is cancelled only when nothing is left on it. The
    licence is untouched either way.
  - **Emails:** the cancelled, expired and payment-failed emails for an add-ons subscription say the
    lifetime licence keeps working and only those add-ons stop. Webhooks for it are `subscription.*`
    only: no `license.*` event is ever derived from it.
  - **Labels:** "Add-ons for licence ending ABCD" appears in the admin subscription list and screen and
    in My Account. The licence's admin panel lists its add-ons subscriptions under its own lifetime
    status.
- **Tax.** Extras are part of the same order line as the licence type, so they follow the product's tax
  class and the shop's "prices entered with tax" setting exactly like the base, at checkout and on
  renewal invoices (which bill the subscription's recurring total on the same product).
- **Partial refunds** change no licence line: WPLM cannot tell which extra a part refund was for. An
  order with extras gets a note saying so. To take an extra back, remove its line on the licence screen;
  its billing goes with it. A full refund works as described under "Changed".

### Changed
- `AddonService::apply()` now writes through one private line writer, shared with the new
  `AddonService::apply_extras()` and `AddonService::apply_extras_to_licence()`. If it fails part-way,
  it removes what it wrote through the service, which also detaches any subscription items.
- **Plan switch with add-ons** (`SwitchManager::switch_plan()`). Add-on items (stored add-ons and
  extras) are carried forward:
  - **`$new_plan['recurring_total']` now means the new plan's OWN price, without the add-ons, on a
    subscription that has add-on items.** On a subscription without them it is still the whole
    recurring charge. The price is set on the plan's item, and adjustments are dropped.
  - An add-on the new plan already grants is not billed twice. Pass `package_id` (the licence type
    switched to) or `modules` in `$new_plan` (the REST switch endpoint accepts both in its body). An add-on item whose lines are all modules the new plan
    grants leaves the subscription. Its lines stay on the licence as plan lines (`source` plan, no
    order) and renew with the plan, so a later refund of the original order no longer removes them.
  - The recurring total is re-derived from the items.
  - The proration charge is the new plan plus the add-ons.
  - Switching to another period or interval while add-on items exist is refused
    (`wplm_switch_addons_billing`), because the add-ons are priced per period.
  - Without add-on items, a switch is unchanged.
- **Refund note.** For a licence issued with extras, a full refund still suspends (or revokes) the
  licence and removes its extras lines. The note now says so, instead of "the licence itself is
  unchanged". An order that did both (a new licence with extras, and extras for a licence the customer
  already had) gets one note for each. `revert()` removes only add-on lines.
- The completed-order email no longer says a brand-new licence's extras were "added to your licence".
- The classic checkout handler also skips lines with `_wplm_extras`.
- `AddonService::eligible_targets()` entries carry `extras` (the offers for that licence).
  `AddonService::blocked_targets()` accepts the eligible targets the caller already has, and the
  product page computes both once per request.

### Fixed (pre-release review)
- **A retried fulfilment never writes or bills add-ons twice.** An order line is stamped
  (`_wplm_entitlement_ids`) right after its add-ons are written, before the add-ons subscription is
  started or any hook runs; a listener that fails on that status change is logged and no longer fails
  the line. Add-on lines an interrupted attempt wrote but never stamped (a fatal or a timeout) are taken
  back before the retry writes them again (`AddonService::discard_unstamped()`), on every path: new
  licence with extras, extras for an existing licence, stored add-ons.
- **An add-ons subscription's first period starts when its add-ons are written**, not when it was
  opened: a pending one that bills nothing yet gets next payment = now + 1 cycle before its lines are
  dated (`AddonService::open_addons_period()`), so a late retry, or another order joining it, is never
  granted a period already over and billed again at once.
- **A cancelled, expired or ending add-ons subscription is never reused** on a retry: a new one is
  opened (`AddonService::reusable_addons_subscription()`). A write rolled back part-way no longer
  cancels the add-ons subscription it was about to bill.
- **A refund cancels an add-ons subscription the order opened but never billed** (pending, no items),
  whatever kind of line opened it.
- **Monthly / yearly add-ons for a lifetime licence are refused before payment** when its add-ons
  subscription could not take them: on hold (paused: "resume it"; unpaid: "pay the renewal first"), its
  paid period over, or set to cancel at period end (it would never renew them). The product page no
  longer offers them, add to cart and checkout refuse them, and fulfilment applies the same check
  (`AddonService::addons_join_problem()`). Lifetime-priced add-ons are still sold.
- An add-ons subscription set to cancel whose last period is over is no longer the licence's open
  add-ons subscription: its modules can be bought again and a new purchase chooses its own cycle.
- **One cycle per licence in a cart.** Two cart lines adding monthly and yearly add-ons to the same
  licence are refused at add to cart and at checkout: the second could be charged but never added.
- **One request at a time adds to a licence.** Adding add-ons or extras to an existing licence now
  checks and writes under a per-licence lock (option `wplm_addons_lock_{licence id}`, taken over after
  5 minutes), so two orders completed at once cannot both pass the "already has" check or both open
  an add-ons subscription. The one that waits fails with a note and is retried by completing it again.
  The per-order fulfilment claim (`wplm_fulfil_lock_{order id}`) uses the same `WPLM\Support\Lock`.
- **Security: a licence id is only recorded on an add-on line, for its owner.** A licence id posted
  with any other product (`wplm_target_license_id`) was stored on the order line as
  `_wplm_addon_license_id` with an "Added to: licence ending ABCD" label, for anyone's licence. It is
  now ignored unless the line is an add-on plan's, and the order line records it only when the licence
  belongs to the buyer. Orders placed before this release may still carry such ids: readers of
  `_wplm_addon_license_id` must check the line was applied (`_wplm_entitlement_ids`) and the licence is
  the order customer's.
- **The add-ons renewal invoice lists the add-ons** ("FBR — Add-ons for licence ending ABCD", one fee
  line per add-on at what it bills), not the lifetime product the licence was bought as.
- **A draft (or pending, or private) plan product is not for sale.** WPLM made every product with a
  plan purchasable, whatever its status, so a product created as a draft for review could be bought
  through `?add-to-cart=`. WooCommerce's own rule now holds: published, or a user who can edit it.

### Deploy
- **Database 1.3.0:** `wplm_subscriptions` gains `addons_license_id` (indexed). It is added
  automatically on the first admin page load after the update, and existing rows are unchanged. Until
  then, subscriptions are still created and the add-ons lookups return nothing instead of querying the
  missing column.
- Deploy this before Super Ledger Licensing 1.4.0, which requires WPLM 1.3.0. Plans built at purchase
  (1.2.7) and their orders keep working.

## [1.2.7] — 2026-10-14

### Added
- **Plans built at purchase (`dynamic`).** A plan can now let the customer build their package on the
  product page instead of choosing one of the plan's licence types. WP License Manager stays
  product-agnostic: a plugin for the licence profile supplies the form, the prices and the package
  through the hooks below. Without such a plugin the product cannot be bought (the cart refuses it).
  - **Plan editor:** "What the plan sells" is now a choice of three: a licence, add-ons to an existing
    licence, or **"The customer builds the package at purchase"**. A built-at-purchase plan needs a
    licence profile and has no licence types: the rows are hidden, and the save ignores them and leaves
    any stored ones untouched. The editor warns when nothing is hooked into `wplm_dynamic_package`.
    The old `plan_addon` checkbox value is still accepted.
  - **Plans list:** such a plan shows "(built at purchase)" and "Built at purchase" in place of its
    licence types. The product **Licensing** tab lists it as "Name (built at purchase)".
  - No database change: `wplm_plans.kind` stores `dynamic`.
- **Hooks for a plugin that builds packages:**
  - `do_action( 'wplm_dynamic_package_form', Plan $plan, WC_Product $product )` prints the form on the
    product page. Its fields post as `wplm_dynamic[...]`.
  - `apply_filters( 'wplm_dynamic_package', null, array $spec, Plan $plan, WC_Product $product )` returns
    the `Package` for that choice, or a `WP_Error` whose message the customer sees.
  - `apply_filters( 'wplm_dynamic_price_html', $html, Plan $plan, WC_Product $product )` is the price shown
    for the product (for example "From …").
  - `apply_filters( 'wplm_dynamic_package_snapshot', array $snapshot, Package $package, array $spec )`
    adds keys to the order-line snapshot (for example a price-list version). It cannot change the
    package's own fields.
- **What WPLM holds a built package to, whatever the filter returns** (`PlanCheckout::dynamic_package()`):
  - It must be a `Package` of the plan.
  - **Billing:** recurring every 1 month or every 1 year, or lifetime. One-time packages are refused.
  - **Price:** a finite price of 0 or more, rounded to the shop's decimals.
  - **Entitlements:** a template valid for the plan's profile, with at least one line.
  - **Forced:** id 0, no sign-up fee, trial, length or "valid for" days, status active.
  - **Computers per key:** the filter's value if it is 1 or more, otherwise 1.

  Anything else is refused with "This plan cannot be bought right now. Please contact us." The reason
  is logged.
- **The browser's price is never used.** The cart line stores only the customer's choice
  (`wplm_dynamic_spec`, sanitised). The package is rebuilt from it on add to cart, on every cart total
  (the line price), in the cart display, in `woocommerce_check_cart_items` (a choice that can no longer
  be built stops the checkout, with the reason) and when the order line is saved. Such a product is
  sold one per line (quantity 1, no quantity box).
- **The order line keeps the sale:** `_wplm_plan_id`, `_wplm_dynamic_spec` (the choice, JSON) and
  `_wplm_dynamic_package` (a JSON snapshot of the package), plus the visible "License Type" (the
  package name). It never stores `_wplm_package_id`.
- **Fulfilment issues from the snapshot** on Completed, so a price change after the order never alters
  the sale:
  - **Monthly or yearly:** one licence and a subscription that renews at the snapshot price. Its item
    meta carries `dynamic_spec`.
  - **Lifetime:** undated lines and no subscription.
  - Completing again issues nothing more (`_wplm_license_ids`).
  - Renewals, refunds, add-ons and My Account treat it like any other licence.
  - A snapshot that is unreadable or belongs to another plan issues nothing. It leaves an order note
    and stays retryable.
- The classic checkout handler skips built-at-purchase lines, as it skips package lines.

### Changed
- `Package::from_row()` also accepts entitlements as an array (a snapshot made with `to_array()`).

### Deploy
- Deploy this before any plugin that builds packages (Super Ledger Licensing 1.3.0). Nothing changes
  for existing plans.

## [1.2.6] — 2026-10-13

### Added
- **Add-ons for an existing licence.** A plan can now sell add-ons — a module, extra users/seats/phones,
  or a dated pack — that are **added to a licence the customer already has. No new key is issued.**
  - **Plan editor:** tick **"This plan sells add-ons to an existing licence"**. It needs a licence profile;
    a classic (no-profile) add-on plan is refused on save.
  - **Fields hidden on add-on types:** Generator, Computers per licence key, Grace, Sign-up fee, Trial days
    and Length. **The save resets them**, whether or not they were posted.
  - **What add-on plans offer and warn about:** a saved add-on plan offers every module code, including
    `template_excluded_codes`. It never shows the "no licence type grants Base" warning. It warns when an
    add-on type does grant the base module.
  - **Product page:** a logged-in customer picks one of their licences ("Add to licence: licence ending
    ABCD"). Only the add-on types that fit the chosen licence are shown. `?wplm_license=<id>` preselects a
    licence.
  - **Who can buy:** logged-out visitors see "Log in to add this to your licence." Customers with no
    licence that can take the add-on are told why, per licence (for example "Pay your plan's renewal
    first, then add this."). For both, the product is not purchasable. A customer with several licences
    sees the ones that cannot take it now listed, disabled, with the reason.
  - **Checks are repeated** on add to cart, in `woocommerce_check_cart_items` (an error stops the checkout),
    and at fulfilment. At fulfilment the licence must belong to the order's customer.
- **Which add-ons fit which licence.** An add-on type must match how the licence's base module is billed:
  - **Recurring licence:** recurring add-ons with the same period and interval. Monthly licences see
    monthly add-ons; yearly licences see yearly ones. The subscription must be active, in trial or on hold.
  - **Lifetime licence:** lifetime and one-time (fixed term) add-ons.
  - **Fixed-term licence** (dated, no subscription): no add-ons.
- **Other refusals:**
  - a licence of another profile;
  - a licence that is suspended, revoked, expired or terminated (active and inactive licences are
    accepted — a licence is *inactive* until its first computer);
  - no base module, or a read-only one;
  - **a recurring add-on while the plan is in trial** ("Add-ons can be bought after your trial ends.")
    **or overdue** — on hold, or paid through a day before today, i.e. in grace ("Pay your plan's
    renewal first, then add this."). An add-on aligned to an overdue plan would be paid for again at the
    very next renewal. Lifetime and one-time add-ons on a lifetime licence have no subscription and are
    unaffected;
  - an add-on whose modules the licence already has for the same term;
  - a module whose requirement is not met (`Profile::$requires`);
  - more than one of an add-on that grants only modules.
- **What fulfilment writes.** Each add-on line is written with `source = 'addon'` and the `order_id` that
  bought it. Template limit quantities are multiplied by the item quantity.
  - **Recurring:** the customer pays the full price now. The line's paid-through date is the plan's own
    paid-through date. The line joins the plan's subscription: it gets `subscription_id`, the subscription
    gets an item (`line_total` = price × quantity), and `recurring_total` is **re-derived as the sum of the
    items, never incremented**. Renewals extend the add-on with the plan
    (`EntitlementService::extend_subscription_lines()`), and the renewal invoice bills both.
  - **Lifetime:** the line has no date.
  - **One-time with days:** the line is paid through `today + days − 1`. **A second pack of the same
    module stacks**, by the renewal rule. It continues from the end of the latest dated line of that module
    that no subscription pays for (an earlier pack, or one added by hand) if that line is still inside
    grace; after grace it starts today. Limit lines do not stack.
- **How fulfilment marks the order.** The order item is marked `_wplm_entitlement_ids`, plus
  `_wplm_addon_subscription_item_id` when the add-on is recurring. It never gets `_wplm_license_ids` or
  `_wplm_subscription_id`: refunds, tunnel provisioning and the order screen read those as "a key was
  issued here". Fulfilment fires **`do_action( 'wplm_order_addons_applied', int $order_id, array $applied )`**
  (one `array{license, lines, item_id}` per add-on line), and **never `wplm_order_licenses_issued`**. A
  failure leaves an order note and the order retryable, as with a licence.
- **Subscriptions edited by hand stay correct.** Some subscriptions have no items, or an amount someone
  edited by hand. Before an add-on joins or leaves one, WPLM adds an item marked "adjustment" that covers
  the difference, so the edited amount is kept.
- **The completed-order email.** An add-on order says what was added: **"Added to your licence ending
  ABCD: FBR (paid through …)."** The lines are read by order id, because the order object WooCommerce
  hands the email predates fulfilment. An order with no new licence attaches no setup sheet. An order that
  issues a new licence and also adds to an existing one says both.
- **A full refund takes back exactly what the add-on order added:** its lines and its subscription item,
  and then the total is re-derived. **The licence is never suspended or revoked** for an add-on refund,
  and the plan's subscription is not cancelled. Running the refund twice removes nothing more.
- **Removing an add-on line in admin also removes its billing.** This works on any path, through
  `wplm_entitlement_deleted`. Once the last line an add-on purchase wrote is gone, its subscription item
  is removed and the total is re-derived. The licence screen shows each line's source, order and
  subscription.
- **My Account → Licenses** shows an **"Add modules or users"** button on each active or inactive
  entitlement licence when a published product sells add-ons for its profile. The button links to that
  product with `?wplm_license=<id>`.
- **`Profile::$requires`** (`array<string, string[]>`, default `array()`): module code ⇒ modules of which
  the licence (or the same add-on) must have at least one that is not read-only.
- **Filter `apply_filters( 'wplm_addon_eligibility', true|WP_Error $result, License $license, Package
  $package, Profile $profile, array $summary, ?Subscription $subscription )`.**
  - WPLM's own verdict is passed in, refusals included, so a product can reword them.
  - Return a `WP_Error` to refuse; its message is shown to the customer.
  - Returning `true` cannot lift WPLM's refusal.
  - Profile, licence-status and ownership refusals are decided before the filter runs.
- `EntitlementService::add_lines()` (all lines validated before any is written).
- `EntitlementRepository::get_by_order()`.
- `SubscriptionRepository::get_items()`, `add_item()`, `update_item()` and `delete_item()`.
- `Plan::$kind` / `Plan::is_addon()`, and `Entitlement::$order_id`.

### Known limits
- **Seats and phones add-ons add to the profile's limits only.** They do not raise "Computers per licence
  key" (`max_activations`), and they do not fire `wplm_order_licenses_issued`, so nothing listening to it
  (such as remote-access provisioning) runs.
- **An overwritten subscription amount drops add-on billing.** A plan switch (`SwitchManager`) or an admin
  or REST edit that sets `recurring_total` replaces the total the items add up to. An add-on that had
  joined the subscription keeps renewing with the plan, but is no longer in the amount charged, until an
  add-on joins or leaves (which itemises the difference again) or the amount is corrected by hand.
- **Re-completing a refunded add-on order does not add the add-on back.** The refund cleared the item's
  `_wplm_entitlement_ids`, but the order stays marked fulfilled. Add the lines on the licence screen, or
  place a new order.

### Changed
- **Database 1.2.2.** `wplm_plans.kind` (`standalone` | `addon`, default `standalone`) and
  `wplm_entitlements.order_id` (indexed) are added by dbDelta on the next admin, REST or cron request.
  Existing plans read as `standalone`; existing lines have no order.

## [1.2.5] — 2026-10-13

### Changed
- **The plan editor shows only the fields that apply to each licence type.** Recurring shows Every, Period,
  Sign-up fee, Trial days and Length (cycles); Lifetime shows none of them; One-time, now labelled
  **"One-time (fixed term)"**, shows "Valid for (days)" instead. Hidden fields are also disabled, so they are
  not posted. The script runs on load, when a row's type changes, and after "+ Add license type".
- **The save resets the fields a type cannot use, whether or not they were posted.** A lifetime or one-time
  package is stored with interval 1, period `month`, sign-up fee 0, trial 0 and length 0. Any type other than
  one-time is stored with no "Valid for (days)". A limit of 0 is stored blank.
- **Lifetime and one-time packages no longer charge a sign-up fee.** Before 1.2.5, checkout added a stored
  sign-up fee to every type, but the storefront showed it only for recurring types. So a lifetime or one-time
  package that had a fee charged price + fee without showing it. From 1.2.5 that package charges its price
  only, even if the fee was stored before the upgrade. **If you meant that fee, add it to the price.**
- **"Valid for (days)" is shown on plans that sell a licence profile too** (it used to be classic-only).
  Grace stays classic-only.
- **A profiled one-time package WITH days now expires.** Its licence's lines are paid through
  `today + days − 1` (the same `paid_through_for()` convention as a recurring period). Before 1.2.5 the
  editor hid the field on such plans, so in practice they granted for life. Once the date passes, those lines
  lapse like any dated line, and the read-only email is sent.
- **A one-time package with no days still grants for life**, exactly as before, so existing rows keep selling
  what they sold. Saving one shows a warning: "One-time needs 'Valid for (days)'; blank grants for life —
  choose Lifetime instead."
- **"Max seats" is now "Computers per licence key".** It is the number of computers that may activate one
  key, which is not the same as a product's own limits (users, phones…) set in the template box below it.
- **A package with a blank computer limit now issues the default (Settings → Default Max Activations, at
  least 1).** Before 1.2.5, licences issued from such a package allowed unlimited computers. The default now
  applies when the licence is issued, so packages already saved blank are covered without being re-saved.
  **Existing licences are unchanged — check the Licences list for blank limits.**

### Added
- **`Profile::$template_excluded_codes`** (`string[]`, default `array()`): module codes kept off the
  licence-type (package) checkboxes of the plan editor. They are still valid codes. A licence's own screen
  (EntitlementPanel) still offers them, `Profile::allows()` and template validation still accept them, and a
  package whose stored template already grants one still shows it, so a re-save does not drop it.
- **`Profile::template_module_codes(): string[]`**, which returns the module codes the editor offers:
  `module_codes` minus `template_excluded_codes`, then passed through the filter
  **`apply_filters( 'wplm_profile_template_module_codes', string[] $codes, Profile $profile )`**. Only codes
  in the profile's module list survive the filter, so it can hide codes but not add them.
- **Filter `apply_filters( 'wplm_lapse_notice_module_phrase', string $phrase, string $module_code, string
  $date, Profile $profile, License $license )`**, applied to each module a read-only notice names:
  - `$phrase` is `''` (WPLM's own wording). Return a non-empty plain-text sentence to use it instead.
  - `$date` is the first read-only day, formatted in the site's date format.
  - A module with its own sentence is left out of the "will become read-only" wording and out of the
    base-module (whole product) check. Its sentence is printed as its own paragraph (escaped).
  - When every module in a notice has its own sentence, the email omits the read-only paragraphs, and its
    subject is the first sentence.
  - Default output is unchanged.

## [1.2.4] — 2026-09-13

### Changed
- **The licence key now travels as a PDF attached to the completed-order email, not as text in the body.**
  A key in an email body is the thing mail filters inspect and forwarded threads leak, and the one message
  that carried setup details inline was the one message that never arrived. The body now says only that the
  attachment is there; the sheet holds the key and whatever else an add-on contributes.
- New filter `wplm_setup_sheet_sections` (label => value) replaces `wplm_email_order_licence_lines`, which
  printed into the body.

### Added
- `WPLM\Support\SetupPdf`, a dependency-free one-page PDF writer using only the fonts every reader has
  built in — the plugin ships without a `vendor/` directory, and a text sheet needs nothing more. The file is
  written to the system temp directory, never below `uploads/` where a web server could serve a key, and is
  deleted when the request that mailed it ends.

## [1.2.3] — 2026-09-13

### Changed
- **The licence keys are now printed in WooCommerce's completed-order email**, under the order table, instead
  of arriving as a separate plain-text message. That separate email is no longer sent. Customers open the
  order email; a bare text message beside it was easy to miss, easy to lose and easy to mistake for spam.
  Admin copies and the processing email never carry keys.
- **Issuance moved to priority 5 on `woocommerce_order_status_completed`.** WooCommerce sends its
  completed-order email on the same hook at priority 10, so the licences have to exist before it renders.
- New filter `wplm_email_order_licence_lines` lets an add-on print extra lines in that block — used by the
  Super Ledger add-on for the office address.

### Removed
- `CheckoutHandler::deliver_license_email()` and `::send_license_email()`, which existed only to send the
  separate message. The admin's Resend action on an order is unchanged and still sends a plain message.

## [1.2.2] — 2026-09-13

### Changed
- **Licences are issued when an order is Completed, and no longer when it reaches Processing.** Both the
  standard and the plan/package path used to fulfil on either status, so a customer paying through a gateway
  that lands orders in Processing received their key before the shop had confirmed the order. The shop now
  decides when a key goes out, by marking the order Completed. **A store that leaves paid orders in Processing
  and never completes them will stop delivering keys** — complete those orders and the keys are issued and
  emailed as usual.

### Fixed
- **A standard order that could not issue every key was still marked delivered**, so re-completing it never
  retried and the customer was left without a key. Issuance now records the failure as an order note, leaves
  the order retryable (as the plan path already did), and skips line items that already have their keys so a
  retry never issues a second one.

## [1.2.1] — 2026-09-12

### Fixed
- **The zombie cull deactivated machines that were never floating.** `MachineRepository::create()` bound a null
  `lease_expires_at` as `''`, which MySQL outside strict mode stores as `0000-00-00 00:00:00`. Every machine
  therefore looked leased, and every licence with a heartbeat — floating or not — lost its machine 20 minutes
  after the last one. Found on the live Super Ledger licence server: an office that checks in every 6 hours was
  refused its next check-in with `machine_inactive`.
  - `create()` now writes SQL `NULL` for every null value.
  - The database upgrade to 1.2.1 clears the zero-date leases already stored.
  - `Machine` reads a zero date as null, and the cull ignores one.
- **An entitlement licence is never floating, and never culled.** It checks in with its own deadline
  (`checkInBy`). Creating or updating a profile licence forces `is_floating` off, activation gives its machine
  no lease even if an older row says floating, the cull skips profile licences, and the admin form hides the
  Floating checkbox for them.

## [1.2.0] — Unreleased

Entitlement licences: per-module and per-limit lines with their own paid-through dates, and a signed, machine-bound v2 token. Classic licences, their v1 tokens and every other product are unchanged.

### Added
- **Licence profiles.** A plan can sell a licence profile. A licence bought from such a plan is an *entitlement licence*.
  - WPLM builds no profile in. A product's add-on plugin registers its own (`Licensing\Profile`: code, label, module codes, limit codes) through the `wplm_license_profiles` filter.
  - A profile code is lower-case letters, digits and hyphens (at most 32); a malformed registration is ignored.
  - A licence whose profile nobody registers gets no v2 token, so the add-on must stay active on the licence server.
- **Entitlement lines** (`wplm_entitlements`).
  - A `module` line grants one of the profile's modules; a `limit` line adds to one of its limits (for example `users`).
  - `paid_through` is the inclusive last paid day in the site's time zone; `NULL` means lifetime.
  - Codes are a closed list per profile, and an unknown code is refused on save.
  - Actions: `wplm_entitlement_saved`, `wplm_entitlement_deleted`.
- **Package entitlement templates.** Buying a package writes its template's lines: paid through the day before the next payment, or lifetime.
- **v2 token** (`Licensing\TokenV2Service`):
  - fields `{v, pid, lid, mid, fp, iat, srv, checkInBy, graceDays, status, modules, limits}`, signed with the existing key and format;
  - per-profile settings for the check-in window and grace (default 7 days each);
  - `status` is `suspended` only when the owner suspended the licence; revoked and terminated licences get no token.
- **Renewals extend entitlement lines.** A card charge or a renewal order marked Completed moves the paying subscription's dated lines (`EntitlementService::extend_subscription_lines()`).
  - It uses the same rule as classic licences: inside the profile's grace, the period continues from the old end; after grace, a full period starts today.
  - Month and year steps clamp to month end.
  - Lifetime lines and other subscriptions' lines never move.
  - `next_payment` becomes the start of the day after the new paid-through date, in the site's time zone.
  - The order note tells the owner the new date.
- **Check-in for entitlement licences** (`Licensing\CheckInService`), on the existing public routes:
  - `POST /activate` and `POST /heartbeat` also return `token_v2` (a fresh signed v2 token) and `server_time` (unix seconds).
  - They accept `usage: {users, seats, phones}` and `app_version`, and store them on the machine (`usage_json`), with the signed `fp` (`token_fp`).
  - A malformed fingerprint (anything but lowercase 64-hex) is refused before any machine is created.
  - A suspended licence still checks in, and its token says `suspended`. A revoked or terminated licence gets 403 and no token.
  - **Rate limit:** 30 check-ins per machine per rolling hour (`wplm_check_in_rate_limit`). Beyond that: HTTP 429 `wplm_rate_limited` with `retry_after`.
  - **Moves:** a self-service `POST /deactivate` of an active machine is a move, limited to 2 per rolling 30 days (`wplm_move_limit`). Beyond that: HTTP 429 `wplm_move_limit_reached` with `moves_reset_at`. The response carries `moves_left`.
  - `CheckInService::reset_moves()` lets the owner allow moves again. Re-deactivating an inactive machine is not a move.
  - Classic licences keep their responses, and have no move limit.
- Index `activation_log (machine_id, event, created_at)` for the rate limit.
- **Offline renewal codes** (`Licensing\OfflineCodeService`, `wp wplm offline-code <machine-id> --days=30`).
  - A code is an ordinary v2 token for that machine, with the check-in deadline the owner picks (1–365 days).
  - It is logged as `offline_code`, with who issued it and for how many days.
  - It is refused for a machine that has not checked in since the upgrade, because its signed `fp` is not yet known.
- **Fleet extension notice** (`Licensing\FleetNotice`): `{"v":1,"pid":"<profile code>","kind":"extend-check-in","until","iat"}`, signed from a keypair **file**.
  - `wp wplm fleet-notice --pid=<profile code> --until=YYYY-MM-DD --keypair-file=…` signs it. `--pid` is required: a notice for the wrong product is rejected by every client.
  - `bin/fleet-notice.php` does the same without WordPress: plain PHP with sodium.
  - It refuses more than 30 days. A bare date means the end of that day in Asia/Karachi (`--timezone`).
- **"Will become read-only" email** (`Licensing\LapseNoticeService`, cron `wplm_lapse_notices`, hourly; template `templates/emails/read-only-soon.php`).
  - Sent on a module's paid-through day, naming the last working day (`paid_through + grace`) and the first read-only day. A lapsing base module (`Profile::$base_module`) is worded as the whole product; other modules are named.
  - Sent once per module and date: each notice is logged as `lapse_notice`. A renewal that moves the date starts a new cycle.
  - A missed run catches up while the module is still in grace, never after read-only has started. A failed send is retried on the next run.
  - It goes to the purchase order's billing email, else the licence owner's account email (filter `wplm_lapse_notice_recipient`). A licence with neither is logged as `no_recipient`.
  - Suspended, revoked and terminated licences are not emailed. Action: `wplm_lapse_notice_sent`.
- Profiles carry human names for their codes (`Profile::code_label()`) and may name a **base module** (`Profile::$base_module`): the module without which the whole product is read-only. A profile without one treats every module alone.
- **"Licences are not being renewed" notice** (`Admin\UnregisteredProfileNotice`): every admin screen names any licence type that licences or plans use but no active plugin registers, since their computers get no new tokens and go read-only when the check-in window runs out.
- **Settings → "<Profile> licences"**: the check-in window and grace for each licence profile (blank = default 7; 1–60 and 0–60 days). A change reaches each computer at its next check-in.
- **Settings → Subscriptions → Default grace (days)**, the 1.1.0 `wplm_default_grace_days` setting, which had no field.
- **The entitlement licence screen** (Licences → Edit, for a licence with a profile):
  - **What this licence grants today:** each module's paid-through date and state (active, due soon, grace, read-only, lifetime), each limit against the usage last reported, and a warning when the profile's base module has no line.
  - **Entitlement lines:** add, change the date or quantity, extend by months (the renewal rule), remove. A line of another licence cannot be reached through a tampered id.
  - **Computers:** last check-in, app version, usage, and **Offline renewal code** with a days field (default 30). The code is shown once, with a copy button, and as a QR when a QR renderer is installed. Also the moves left, and **Reset moves**.
  - **Status:** suspend and revoke need a note; reinstate takes an optional one. Each change is logged as `status_note` with the note and the admin.
  - **Licence log:** status notes, offline codes, read-only notices, moves and activations.
  - Logic in `Admin\LicenceAdminActions`; every panel form posts to `admin-post.php?action=wplm_licence_action`.
- **The plan editor sells modules and limits.** A plan chooses its licence type (classic, or a registered profile).
  - Each licence type (package) of a profile plan gets a checkbox per module and a quantity per limit of the profile: the template a purchase writes onto the licence.
  - Every template is checked before the plan or its packages change, so a bad one leaves the plan as it was.
  - Saving warns when no licence type grants the profile's base module: right for an add-on plan, wrong for a main one.
  - Grace and Valid-for fields are hidden for a profile plan: its dates come from the lines and the profile's grace.
  - Logic in `Admin\PlanAdminActions`; save results and warnings are shown through `Admin\Flash`.
- **My Account → My Licenses → View Devices** now opens (the link went nowhere):
  - the licence's active computers;
  - for an entitlement licence, each module's paid-through date and state, and the moves left;
  - a **Move to another computer** button per computer. This is a self-service move that counts against the limit like one made from the app. A third move within 30 days is refused, naming the day it frees up.
  - A customer sees and moves only their own licences. The list shows an entitlement licence's modules instead of "Expires: Never". (`Integrations\WooCommerce\MyAccountLicences`.)
- The Add Licence form chooses the licence type (classic, or a registered profile). An entitlement licence opens on its lines after it is created. Its screen has no expiry, grace or valid-for fields.
- Bulk Suspend and Revoke leave entitlement licences unchanged and say so: those are locked from their own screen, with a note. Their list rows link there instead of offering Revoke or Delete.
- `EntitlementService::extend_line()`, `renewed_paid_through()` (the renewal rule, now shared with renewals) and `module_state()`.
- A warning on the Settings page when licence tokens are signed with a keypair from the database on a `production` site. Define `WPLM_SIGNING_KEYPAIR` in `wp-config.php` instead.
- `TokenV2Service::compose()` and `wire()` are public and static, so a product's add-on can build contract fixtures with the same composition code as real tokens.
- `Crypto\CompactToken`: the token format in one place, usable without WordPress. `Signer` delegates to it, and its output is byte-identical to before.

### Changed
- **An entitlement licence never stores `expires_at`.** Its lines carry the dates, so no path can make classic validation mark it expired or refuse its activation.
  - Covered paths: checkout, renewal payment, subscription cancellation, first activation with `valid_for_days`, admin, REST.
  - Enforced once, in `LicenseRepository`. Turning a licence into an entitlement licence clears its expiry.
- `LicenseService::extend_term()` returns `null` for an entitlement licence.
- `ActivationLogRepository::count_events_since()` compared a UTC cutoff with site-time `created_at` values; it now compares in site time.

### Fixed
- **Saving the Settings page failed with a fatal error.** `options.php` passes `null` for the group option the form never posts, and `validate_settings()` accepted only an array.
- **The dunning retry schedule entered in Settings was ignored.** The page saves a comma list ("2,4,9") but dunning read only a JSON array, so it always used 1, 3, 5. Both forms are read now. The field also showed "3,7,14" as the default, which was never the default.
- **My Account → My Licenses was always empty.** Listing licences without an explicit sort built `ORDER BY  DESC`, so the query failed and customers were told they had no licences.
- **Add New Licence always failed.** The form had no key field and ignored the chosen generator, so creation threw "key_string is required". A blank key is now generated with the chosen generator, else the default one. Errors come back to the form as a notice instead of a fatal.

### Requirements
- DB version 1.2.0 (adds `wplm_entitlements`, `licenses.profile`, `plans.profile`, `packages.entitlements`, `machines.token_fp`, `machines.usage_json`).

---

## [1.1.0] — Unreleased

This release fixes billing and licensing correctness. The problems were found by running the real purchase → activate → renew paths on a live install ([docs/audit/2026-09-11-subscription-walk.md](docs/audit/2026-09-11-subscription-walk.md)). Every fix below has an integration test (`tests/`) that failed before it.

### Changed — how access ends when a customer does not pay
- **Billing never locks a licence by changing its status.**
  - Access ends because the paid term runs out: a licence stays valid until `expires_at` + `grace_days`, then validates as `expired`.
  - Suspending, revoking and terminating are owner actions only.
- **A recurring plan licence now expires at the end of the paid period** (the trial end or the first `next_payment`), instead of never. Each renewal extends it (F1).
- **Grace:** a new `wplm_default_grace_days` setting (default **7**) and a per-package **Grace (days)** field. New licences carry it.
- **Dunning (F3):** the retry schedule (`wplm_dunning_schedule`, default 1, 3, 5 days = three retries) now actually runs.
  - A declined charge keeps the subscription `active`, with the retry date as `next_payment`.
  - After the last retry, the subscription goes `on-hold` and the customer is sent a renewal invoice.
  - The licence is never suspended or revoked for a failed charge.
- **Subscription → licence sync:**
  - `active`/`trial` brings a pending, inactive or expired licence to active, but never lifts a suspension or revocation.
  - `on-hold` changes nothing.
  - `cancelled`/`expired` no longer revokes. The licence runs to the end of the paid period; a legacy perpetual licence is given that end date.
- **Renewal payments use one rule** (`LicenseService::extend_term()`, `RenewalProcessor::apply_payment()`) for cron card charges and paid renewal orders alike:
  - Paid before the end or inside grace → the new period continues from the old end.
  - Paid after grace → a full period starts from the payment.
  - `next_payment` follows the licence.

### Added
- **Manual renewal invoices (F2).**
  - When a subscription without a stored card falls due, one pending renewal order per cycle is created and emailed to the customer as a WooCommerce customer invoice. Previously `wplm_subscription_manual_renewal_due` had no listener, and nothing happened.
  - Invoices are tracked in `wplm_subscription_renewals` (`type = invoice`), so this works with and without HPOS.
  - New action: `wplm_subscription_renewal_invoiced`.
- New action: `wplm_license_term_extended`.
- An admin notice when a signing, encryption or fingerprint secret is missing.
- **An integration test suite** on real WordPress + WooCommerce (`wp-phpunit`); see `tests/README.md`.

### Fixed
- An expired licence stayed `expired` after a successful payment, so a paying customer was refused (F4).
- Late cron renewals were counted from the old expiry, so the customer lost the late days (F5).
- Self-service renewal did not re-sign the offline token, so offline clients kept the old expiry (F6).
- **Dates (F7).**
  - Due checks for subscriptions, heartbeats, API-key access and "expiring soon" used MySQL `NOW()` (the database server's time zone) against UTC values.
  - Purchase and scheduler dates used site-local time as if it were UTC.
  - On a server whose MySQL is not on UTC (for example Pakistan Standard Time), renewals came due hours early and floating-licence heartbeats were judged against the wrong clock.
- A missing secret fataled every request, including wp-admin, so the Tools page that fixes it was unreachable (F9). Secrets now load on first use.
- A fresh install had no key generator. A plan purchase issued no licence, only logged the failure, and still marked the order fulfilled (F10). Now:
  - activation seeds a default generator;
  - a failed fulfilment adds an order note and stays retryable (set the order to Processing or Completed again);
  - a retry never issues a second licence for an item already issued.
- The package editor had no "Valid for (days)" field, so saving a plan erased a one-time package's validity (F12).
- A licence that ran out was marked `expired` without firing `wplm_license_status_changed`, so the `license.expired` webhook and add-ons listening for it never heard. It matters more now that expiry, not suspension, is how an unpaid licence ends. It fires once.
- Admin deactivation by machine id and zombie culling decremented the seat count instead of recomputing it. Deactivating an already inactive device is now a no-op.
- The schema upgrade ran only on `admin_init`. It now also runs on REST and cron requests, so a licence server updated without anyone opening wp-admin still migrates.

### Security
- **Public `POST /wplm/v1/deactivate` accepted a bare `machine_id`**, letting anyone deactivate any customer's device by counting upward (F11). The route now requires `license_key`, and a `machine_id` must belong to that licence.

### Requirements
- **PHP 8.0+.** It was stated as 7.4, but the code already required 8.0.
- DB version 1.1.0 (adds `packages.grace_days`).

### Upgrade notes
- **Subscriptions left `on-hold` by the old dunning, with a suspended licence, are not changed automatically.** Such a suspension can't be told apart from a manual one. After upgrading, review on-hold subscriptions: resume the subscription and reinstate the licence where appropriate.
- **Existing recurring licences with no expiry stay perpetual** until either their next renewal payment (which starts timing them) or cancellation (which gives them the paid-through end date). An admin tool to backfill `expires_at` from `next_payment` can follow. It was left out deliberately: it would lock overdue customers the moment it runs.
- **To lock a customer now, suspend the licence.** Pausing a subscription no longer does it.


## [1.0.8] — 2026-06-28

### Fixed
- Device deactivation failed with `Unknown column 'deactivated_at'` — the `wplm_machines` schema was missing the `deactivated_at` column referenced by `MachineRepository::deactivate()`. The column is now defined in the installer and added to existing tables via the `1.0.6` DB migration (DB version bumped). Devices now deactivate cleanly.

### Security / Hardening
- `RestServer` now suppresses error display **and** `$wpdb` error output for `wplm/v1` routes, so a database error can never corrupt a JSON response (failures are still logged).

## [1.0.7] — 2026-06-28

### Added
- **Product binding.** The Ed25519 signed license payload now carries a `pid` (product id) field. `LicenseService::build_signing_payload()` is the single source of truth used by `create()`, the `update()` re-sign path (now also triggered by a `product_id` change), and a new `resign_all()` migration. A **Settings → Tools → Re-sign licenses** tool re-signs every key and can backfill a product id onto keys that have none. All four client SDKs reject a key whose signed `pid` does not match the configured product id, online and offline (`product_mismatch` / `WplmProductMismatch`).

### Fixed
- `validate()` did not hash the device fingerprint before the machine lookup (activate/deactivate/heartbeat did), so an activated device was always reported `needs_activation: true`. `LicenseService` now receives `Crypto\Fingerprint` and hashes before lookup.
- Re-activating a previously-deactivated device (idempotent path) left the license inactive; the license is now reconciled to active whenever an active device is bound.

### Changed
- Seat counts are now recomputed from the actual active machines (`LicenseRepository::sync_activation_count()`) on activate, re-activate, and deactivate, replacing increment/decrement — the count can no longer drift after an error, retry, or race.

## [1.0.0] — 2026-06-15

### Added

#### Core / Architecture
- PSR-4 autoloader (`WPLM\` → `src/`) with Composer integration and fallback `spl_autoload_register`
- Lightweight DI container (`src/Container.php`) with bind / make / has / instance
- Plugin bootstrap (`src/Plugin.php`) with single-instance `get_instance()`, service container wiring, and conditional WooCommerce boot
- Database installer (`src/Install/Installer.php`) — 15 tables created via `dbDelta()` with schema version tracking
- Database seeder (`src/Install/Seeder.php`) — generates Ed25519 keypair, AES-256-GCM key, HMAC secret, and webhook secret on first activation
- Uninstall handler (`uninstall.php`) — drops all tables and options; respects `wplm_keep_data_on_uninstall` toggle

#### Cryptography
- `src/Crypto/Signer.php` — Ed25519 sign/verify via libsodium; `base64url(json).base64url(sig)` token format
- `src/Crypto/KeyVault.php` — symmetric encryption with AES-256-GCM (hardware) / XChaCha20-Poly1305 (fallback); nonce-prepended ciphertext
- `src/Crypto/Fingerprint.php` — HMAC-SHA256 device fingerprint hashing and SHA-256 static helper

#### Models
- `src/Models/License.php` — `from_row()`, `to_array()`, status helpers, `is_within_expiry()`
- `src/Models/Machine.php`
- `src/Models/Subscription.php`
- `src/Models/Renewal.php`
- `src/Models/ApiKey.php`
- `src/Models/Generator.php`

#### Repositories (15 tables)
- `LicenseRepository` — CRUD + status queries with INET6_ATON/NTOA for IPv4/IPv6 storage
- `MachineRepository` — device seat management, floating lease timestamps
- `ActivationLogRepository`
- `BlacklistRepository`
- `SubscriptionRepository`
- `RenewalRepository`
- `ApiKeyRepository`
- `GeneratorRepository`
- `WebhookRepository`
- `ReleaseRepository`
- `EnvironmentRepository`
- `MetaRepository`
- `ProductMappingRepository`
- `AuditLogRepository`
- `NotificationRepository`

#### Services
- `src/Services/LicenseService.php` — 7-step validation algorithm, encrypted key creation, Ed25519 signing, `wplm_license_created` action
- `src/Services/ActivationService.php` — idempotent activation, overage ceilings (deny / 1.25× / 2×), floating leases
- `src/Services/RevocationService.php` — revoke/suspend/terminate per license or device, signed CRL (1-hour transient cache)
- `src/Services/HeartbeatService.php` — lease renewal, zombie cull via `wplm_zombie_window` filter (default 1200 s)
- `src/Services/GeneratorService.php` — configurable key format, sequential/random generation, batch creation
- `src/Services/AnalyticsService.php` — anomaly detection: fingerprint churn, validation spikes, impossible travel; 10-min cache
- `src/Services/WebhookService.php` — signed webhook dispatch with HMAC-SHA256 signature header
- `src/Services/ReleaseService.php` — software release and update management

#### Subscriptions Engine
- `src/Services/Subscriptions/RenewalProcessor.php` — charges via GatewayBridge, writes renewal record, advances `next_payment`, falls through to dunning on failure
- `src/Services/Subscriptions/DunningManager.php` — configurable retry schedule, sends email notifications, suspends subscription after max failures
- `src/Services/Subscriptions/BillingScheduler.php` — WP-Cron–based next-payment scheduling
- `src/Services/Subscriptions/GatewayBridge.php` — dispatches `wplm_gateway_charge` filter; returns `WP_Error('no_gateway')` when no gateway hooked
- `src/Services/Subscriptions/Proration.php` — upgrade/downgrade prorated credit calculation

#### REST API
- `src/Rest/Auth/BasicAuth.php` — HTTP Basic with SHA-256 consumer key, `hash_equals()` secret check, `determine_current_user` hook
- `src/Rest/Controllers/BaseController.php` — abstract; `require_read/write/public_route()` permission callbacks
- Controllers: `LicensesController`, `ActivationsController`, `ValidateController`, `HeartbeatController`, `RevocationController`, `GeneratorsController`, `ApiKeysController`, `WebhooksController`, `ReleasesController`, `SubscriptionsController`, `AnalyticsController`

#### Admin UI
- `src/Admin/Menu.php` — 5 top-level menu pages, Settings API init, nonce-guarded save handlers
- `src/Admin/Screens/DashboardScreen.php` — summary widgets (active/inactive/expiring/revenue)
- `src/Admin/Screens/LicenseListTable.php` — extends `WP_List_Table`; bulk revoke, suspend, export
- `src/Admin/Settings/SettingsPage.php` — Settings API, 6 sections: General, Security, Activation, Monitoring, Subscriptions, Dunning

#### WooCommerce Integration
- `src/Integrations/WooCommerce/ProductPanel.php` — "License Data" product tab with 7 meta fields
- `src/Integrations/WooCommerce/CheckoutHandler.php` — idempotent key delivery on `woocommerce_order_status_completed`; refund → suspend
- `src/Integrations/WooCommerce/MyAccountSubscriptions.php` — My Account endpoints `wplm-licenses` and `wplm-subscriptions` with pause/resume/cancel POST forms

#### Global Helper Functions
- `src/functions.php` — 30 globally namespaced helper functions (`wplm_get_license()`, `wplm_validate()`, `wplm_activate()`, etc.), all guarded with `function_exists()`

#### Frontend & Templates
- `assets/css/admin.css` — dashboard grid, 7 status badge classes, list-table extras, settings styles
- `assets/js/admin.js` — product panel field toggle, dangerous-action confirm, copy-to-clipboard, keypair re-roll warning
- `templates/myaccount/wplm-licenses.php` — WooCommerce My Account licenses table with truncated key display
- `templates/myaccount/wplm-subscriptions.php` — My Account subscriptions table with action forms

#### Developer Tools
- `composer.json` — PSR-4 autoload, dev deps: PHPUnit ^9, WPCS ^3, phpcs ^3.7, Brain\Monkey ^2.6
- `phpcs.xml` — WordPress coding standards, text-domain enforcement, PHP 7.4+ / WP 5.8+ targets
- Documentation site (Astro Starlight 0.32.6) in `docs-site/`

---

[1.0.0]: https://github.com/wplm/wp-license-manager/releases/tag/v1.0.0
