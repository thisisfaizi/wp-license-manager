<?php
/**
 * An add-on line refused at fulfilment for good.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Why an order line's add-ons could not be added, when setting the order to Completed again can never
 * cure it: the licence already has a module they add (another order added it since), the subscription
 * they were sold against no longer bills them, they were priced on another cycle than the licence's
 * add-ons are now billed, or an earlier attempt left lines on the licence for the owner to sort out.
 * Fulfilment then tells the shop to refund the line or to mark its add-ons settled
 * ({@see \WPLM\Integrations\WooCommerce\AddonSettlement}), never to retry.
 */
class AddonRefusal extends \RuntimeException {
}
