<?php
/**
 * Plugin Name: Subscriptions for WooCommerce Extra
 * Description: Extra tools and views for Subscriptions for WooCommerce (WP Swings), including support for coupon-based discounts on recurring renewals.
 * Version: 2.0.2
 * Author: Christefano Reyes
 * Plugin URI: https://github.com/christefano/wps-subscriptions-extra
 * Text Domain: wps-subscriptions-extra
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: subscriptions-for-woocommerce
 * Tested up to: 6.9
 *
 * Strategy (snapshot / Option B): the coupon code, discount type, and amount are
 * recorded on the subscription when it is first created. On each renewal the
 * discount is recomputed from that snapshot and applied to the renewal order as a
 * fixed reduction, independent of the coupon's current validity (expiry, usage
 * limits, or deletion do not affect existing subscriptions). This mirrors the
 * "recurring discount" behaviour of the Pro plugin without using Pro.
 *
 * Dependency: Subscriptions for WooCommerce (free, slug subscriptions-for-woocommerce),
 * which in turn requires WooCommerce. This plugin hooks the base plugin's
 * wps_sfw_after_created_subscription and wps_sfw_renewal_order_creation actions
 * and uses its HPOS-aware meta helpers (wps_sfw_get_meta_data /
 * wps_sfw_update_meta_data).
 *
 * Version policy: this plugin's major.minor mirrors the Subscriptions for
 * WooCommerce release it is built and verified against. It is known to work ONLY
 * with Subscriptions for WooCommerce 2.0, so the major.minor is pinned at 2.0 and
 * must not be advanced beyond it; the patch component (2.0.x) is used for add-on-side
 * fixes that do not change base-plugin compatibility. Tested with WooCommerce 10.9
 * and HPOS enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription meta key holding the recorded coupon snapshot (array of arrays).
 */
define( 'WPS_SRC_SNAPSHOT_META', '_wps_src_recurring_coupons' );

/**
 * Order meta flag marking a renewal as already processed (re-entrancy guard).
 */
define( 'WPS_SRC_APPLIED_META', '_wps_src_applied' );

/**
 * Order meta flag marking a renewal order as a suppressed duplicate: its line
 * totals were zeroed so the base plugin's gateway charge (which skips orders
 * whose total is <= 0) does not charge the customer a second time.
 */
define( 'WPS_SRC_DUP_META', '_wps_src_dup_suppressed' );

/**
 * Subscription order type registered by Subscriptions for WooCommerce.
 */
define( 'WPS_SRC_SUBSCRIPTION_TYPE', 'wps_subscriptions' );

/**
 * Page size used when walking subscriptions in bulk (backfill, uninstall).
 */
define( 'WPS_SRC_BATCH_SIZE', 200 );

/**
 * Per-subscription discount-mode override meta key. Value: '', 'lock', or 'live'.
 */
define( 'WPS_SRC_MODE_META', '_wps_src_discount_mode' );

/**
 * Store-wide discount-mode option ('lock' = price-lock snapshot, 'live' =
 * re-validate the coupon each renewal) and the last-run summary option.
 */
define( 'WPS_SRC_MODE_OPTION', 'wps_src_discount_mode' );
define( 'WPS_SRC_LASTRUN_OPTION', 'wps_src_last_run' );

/**
 * Option holding the "hide the duplicate WP Swings top-level menu" checkbox
 * state ('yes' or unset/anything else). The base plugin (Subscriptions for
 * WooCommerce) registers its own settings under WooCommerce -> Wps
 * Subscriptions already, so its separate top-level "WP Swings" menu
 * (add_menu_page slug 'wps-plugins') duplicates that entry point.
 */
define( 'WPS_SRC_HIDE_WPSWINGS_MENU_OPTION', 'wps_src_hide_wpswings_menu' );

/**
 * Base-plugin (Subscriptions for WooCommerce) meta keys this plugin reads.
 */
define( 'WPS_SRC_PARENT_META', 'wps_parent_order' );
define( 'WPS_SRC_STATUS_META', 'wps_subscription_status' );
define( 'WPS_SRC_RENEWAL_FLAG_META', 'wps_sfw_renewal_order' );
define( 'WPS_SRC_SUBSCRIPTION_REF_META', 'wps_sfw_subscription' );

/**
 * Transient caching the dashboard statistics.
 */
define( 'WPS_SRC_STATS_TRANSIENT', 'wps_src_stats' );

/**
 * Pro coupon discount types that the base plugin already persists into the stored
 * recurring line total. These must be skipped to avoid discounting twice.
 *
 * @param string $type Coupon discount type.
 * @return bool
 */
if ( ! function_exists( 'wps_src_is_native_recurring_type' ) ) {
	function wps_src_is_native_recurring_type( $type ) {
		$native = array( 'recurring_product_discount', 'recurring_product_percent_discount' );
		return in_array( $type, $native, true );
	}
}

/**
 * Read a meta value from a subscription, using the parent plugin's helper when
 * available so storage stays consistent with Subscriptions for WooCommerce.
 *
 * @param int    $subscription_id Subscription ID (post ID on legacy installs, order ID under HPOS).
 * @param string $key             Meta key.
 * @return mixed
 */
if ( ! function_exists( 'wps_src_get_subscription_meta' ) ) {
	function wps_src_get_subscription_meta( $subscription_id, $key ) {
		if ( function_exists( 'wps_sfw_get_meta_data' ) ) {
			return wps_sfw_get_meta_data( $subscription_id, $key, true );
		}
		return get_post_meta( $subscription_id, $key, true );
	}
}

/**
 * Write a meta value to a subscription, using the parent plugin's helper when
 * available.
 *
 * @param int    $subscription_id Subscription ID (post ID on legacy installs, order ID under HPOS).
 * @param string $key             Meta key.
 * @param mixed  $value           Value to store.
 */
if ( ! function_exists( 'wps_src_update_subscription_meta' ) ) {
	function wps_src_update_subscription_meta( $subscription_id, $key, $value ) {
		if ( function_exists( 'wps_sfw_update_meta_data' ) ) {
			wps_sfw_update_meta_data( $subscription_id, $key, $value );
			return;
		}
		update_post_meta( $subscription_id, $key, $value );
	}
}

/**
 * Walk every subscription ID in batches, invoking a callback for each.
 *
 * Subscriptions are a WooCommerce order type, so under HPOS they live in order
 * storage rather than wp_posts; wc_get_orders() reads either. Paging keeps memory
 * bounded on large stores.
 *
 * @param callable $callback Receives one subscription ID per call.
 * @return int Number of subscriptions visited.
 */
if ( ! function_exists( 'wps_src_walk_subscriptions' ) ) {
	function wps_src_walk_subscriptions( $callback ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$paged = 1;
		$count = 0;

		do {
			$ids = wc_get_orders(
				array(
					'type'    => WPS_SRC_SUBSCRIPTION_TYPE,
					'status'  => 'any',
					'limit'   => WPS_SRC_BATCH_SIZE,
					'paged'   => $paged,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);

			foreach ( $ids as $id ) {
				$callback( $id );
				$count++;
			}

			$paged++;
		} while ( count( $ids ) === WPS_SRC_BATCH_SIZE );

		return $count;
	}
}

/**
 * Build a coupon snapshot from an order.
 *
 * Pro-native recurring coupon types are skipped because the base plugin already
 * bakes those into the stored recurring price; re-applying them would double the
 * discount.
 *
 * The `discount` key records the actual dollar amount the order's line items were
 * reduced by, apportioned across coupons by each WC_Order_Item_Coupon's own
 * recorded discount. This is what the customer actually paid less, independent of
 * the coupon's type or amount, so later renewals reproduce the real discount even
 * if the coupon's rules change. When a subscription ID is given, only the parent
 * order's line item(s) matching that subscription's product are counted, so a
 * mixed cart (subscription plus one-off products) does not inflate the recorded
 * discount with savings the renewal's single line never received.
 *
 * @param int $order_id        Order ID to read coupons from.
 * @param int $subscription_id Optional subscription ID; restricts the line-discount
 *                             sum to that subscription's product line(s).
 * @return array List of { code, type, amount, discount }; empty when none apply.
 */
if ( ! function_exists( 'wps_src_build_snapshot' ) ) {
	function wps_src_build_snapshot( $order_id, $subscription_id = 0 ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		$codes = $order->get_coupon_codes();
		if ( empty( $codes ) ) {
			return array();
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		// The base plugin stores the subscription's product ID (the variation ID
		// for variable products) on the subscription itself.
		$sub_product_id = $subscription_id ? (int) wps_src_get_subscription_meta( $subscription_id, 'product_id' ) : 0;

		// Line discount: what the customer actually saved, pre- vs. post-coupon,
		// on the subscription's own line(s) when the product is known, falling
		// back to all lines when no line matches (product changed or deleted, or
		// meta absent).
		$line_discount_total = 0.0;
		$matched_total       = 0.0;
		$matched_any         = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$line_discount = ( (float) $item->get_subtotal() - (float) $item->get_total() );
			$line_discount_total += $line_discount;

			if ( $sub_product_id ) {
				$item_product_id = (int) $item->get_variation_id();
				if ( ! $item_product_id ) {
					$item_product_id = (int) $item->get_product_id();
				}
				if ( $item_product_id === $sub_product_id ) {
					$matched_total += $line_discount;
					$matched_any    = true;
				}
			}
		}
		if ( $matched_any ) {
			$line_discount_total = $matched_total;
		}

		// Coupon items carry WooCommerce's own per-coupon discount split, used to
		// apportion the order-level line discount across multiple coupons.
		$coupon_items        = $order->get_items( 'coupon' );
		$coupon_discounts    = array();
		$coupon_discount_sum = 0.0;
		foreach ( $coupon_items as $coupon_item ) {
			$code                      = $coupon_item->get_code();
			$amt                       = (float) $coupon_item->get_discount();
			$coupon_discounts[ $code ] = $amt;
			$coupon_discount_sum      += $amt;
		}

		// Only codes eligible for the snapshot (native recurring types excluded)
		// count toward the "single coupon" shortcut below.
		$eligible = array();
		foreach ( $codes as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( ! wps_src_is_native_recurring_type( $coupon->get_discount_type() ) ) {
				$eligible[] = $code;
			}
		}

		$snapshot = array();
		foreach ( $eligible as $code ) {
			$coupon = new WC_Coupon( $code );
			$type   = $coupon->get_discount_type();

			if ( $coupon_discount_sum <= 0 ) {
				$discount = 0.0;
			} elseif ( 1 === count( $eligible ) ) {
				$discount = $line_discount_total;
			} else {
				$share    = isset( $coupon_discounts[ $code ] ) ? $coupon_discounts[ $code ] : 0.0;
				$discount = $line_discount_total * ( $share / $coupon_discount_sum );
			}

			$snapshot[] = array(
				'code'     => $code,
				'type'     => $type,
				'amount'   => (float) $coupon->get_amount(),
				'discount' => round( $discount, $decimals ),
			);
		}

		return $snapshot;
	}
}

/**
 * Record the original order's coupons on the subscription at creation time.
 *
 * @param int $subscription_id Subscription ID.
 * @param int $order_id        Parent (first) order ID.
 */
if ( ! function_exists( 'wps_src_snapshot_coupons' ) ) {
	function wps_src_snapshot_coupons( $subscription_id, $order_id ) {
		$snapshot = wps_src_build_snapshot( $order_id, $subscription_id );
		if ( ! empty( $snapshot ) ) {
			wps_src_update_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META, $snapshot );
		}
	}
}
add_action( 'wps_sfw_after_created_subscription', 'wps_src_snapshot_coupons', 10, 2 );

/**
 * Compute one coupon's discount against a single line total.
 *
 * Percent types scale the base; product types multiply by quantity; flat types
 * (fixed_cart and anything else) draw from a shared budget so they are not
 * applied once per line. The result is capped at the base, and the flat budget is
 * decremented in place.
 *
 * @param string     $type           Coupon discount type.
 * @param float      $amount         Coupon amount.
 * @param float      $base           Current line total to discount from.
 * @param int        $qty            Line quantity.
 * @param float|null $flat_remaining Shared flat budget (by reference); null for percent/product.
 * @return float Discount to subtract from this line (>= 0).
 */
if ( ! function_exists( 'wps_src_coupon_line_discount' ) ) {
	function wps_src_coupon_line_discount( $type, $amount, $base, $qty, &$flat_remaining ) {
		$amount = (float) $amount;
		$base   = (float) $base;

		if ( $amount <= 0 || $base <= 0 ) {
			return 0.0;
		}

		if ( false !== strpos( $type, 'percent' ) ) {
			$discount = $base * ( $amount / 100 );
		} elseif ( false !== strpos( $type, 'product' ) ) {
			$discount = $amount * max( 1, (int) $qty );
		} else {
			$discount = ( null !== $flat_remaining ) ? $flat_remaining : $amount;
		}

		$discount = min( $discount, $base );

		if ( null !== $flat_remaining ) {
			$flat_remaining = max( 0, $flat_remaining - $discount );
		}

		return $discount;
	}
}

/**
 * Compute price-lock discounts from a snapshot against a set of line totals.
 *
 * Shared by wps_src_apply_recurring_coupons() (lock mode) and
 * wps_src_preview_amounts() so both stay in agreement. When a snapshot entry
 * carries a `discount` key it is spent as a flat amount across lines, the same
 * way the old flat (fixed_cart) budget was spent, with no coupon-type sniffing;
 * this is the actual dollar discount the customer received at signup. Entries
 * without a `discount` key (snapshots recorded before this key existed) fall
 * back to wps_src_coupon_line_discount()'s type-based math. An entry whose
 * effective amount is zero or less is skipped entirely: a recorded `discount`
 * of 0 means the customer received no discount on this line, so nothing should
 * be applied now either.
 *
 * @param array $snapshot List of { code, type, amount, discount? }.
 * @param array $lines    List of { total: float, qty: int }, one per order line.
 * @return array {
 *     'coupons' => array<string, float> Per-coupon-code total discount applied.
 *     'lines'   => float[] Per-line reduction, indexed the same as $lines.
 * }
 */
if ( ! function_exists( 'wps_src_compute_lock_discounts' ) ) {
	function wps_src_compute_lock_discounts( $snapshot, $lines ) {
		$coupons = array();
		$running = array();
		foreach ( $lines as $i => $line ) {
			$running[ $i ] = (float) $line['total'];
		}
		$reductions = array_fill_keys( array_keys( $running ), 0.0 );

		foreach ( $snapshot as $cpn ) {
			$code = isset( $cpn['code'] ) ? $cpn['code'] : '';
			if ( '' === $code ) {
				continue;
			}

			$has_discount = array_key_exists( 'discount', $cpn );
			$type         = isset( $cpn['type'] ) ? $cpn['type'] : '';
			$amount       = isset( $cpn['amount'] ) ? (float) $cpn['amount'] : 0;

			if ( $has_discount ) {
				$flat_remaining = (float) $cpn['discount'];
			} else {
				$is_percent     = ( false !== strpos( $type, 'percent' ) );
				$is_product     = ( ! $is_percent && false !== strpos( $type, 'product' ) );
				$flat_remaining = ( ! $is_percent && ! $is_product ) ? $amount : null;
			}

			if ( $has_discount && $flat_remaining <= 0 ) {
				continue;
			}
			if ( ! $has_discount && $amount <= 0 ) {
				continue;
			}

			$coupon_discount = 0.0;

			foreach ( $running as $i => $base ) {
				if ( $base <= 0 ) {
					continue;
				}

				if ( $has_discount ) {
					// Flat amount, spent across lines like the old fixed_cart
					// budget, capped per line, no type sniffing.
					$discount = min( $flat_remaining, $base );
					$flat_remaining = max( 0, $flat_remaining - $discount );
				} else {
					$discount = wps_src_coupon_line_discount( $type, $amount, $base, $lines[ $i ]['qty'], $flat_remaining );
				}

				if ( $discount <= 0 ) {
					continue;
				}

				$running[ $i ]     -= $discount;
				$reductions[ $i ]  += $discount;
				$coupon_discount   += $discount;

				if ( null !== $flat_remaining && $flat_remaining <= 0 ) {
					break;
				}
			}

			if ( $coupon_discount > 0 ) {
				$coupons[ $code ] = ( isset( $coupons[ $code ] ) ? $coupons[ $code ] : 0.0 ) + $coupon_discount;
			}
		}

		return array(
			'coupons' => $coupons,
			'lines'   => $reductions,
		);
	}
}

/**
 * Resolve the effective discount mode for a subscription: per-subscription
 * override when set, otherwise the store-wide option, defaulting to price-lock.
 *
 * @param int $subscription_id Subscription ID.
 * @return string 'lock' or 'live'.
 */
if ( ! function_exists( 'wps_src_get_discount_mode' ) ) {
	function wps_src_get_discount_mode( $subscription_id ) {
		$override = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_MODE_META );
		if ( 'lock' === $override || 'live' === $override ) {
			return $override;
		}
		return ( 'live' === get_option( WPS_SRC_MODE_OPTION, 'lock' ) ) ? 'live' : 'lock';
	}
}

/**
 * The subscription's billing interval in seconds, from its stored period unit and
 * count. Used to size the duplicate-renewal cooldown. Unknown units fall back to a
 * month.
 *
 * @param int $subscription_id Subscription ID.
 * @return int Interval length in seconds (never below one day).
 */
if ( ! function_exists( 'wps_src_renewal_interval_seconds' ) ) {
	function wps_src_renewal_interval_seconds( $subscription_id ) {
		$number = (int) wps_src_get_subscription_meta( $subscription_id, 'wps_sfw_subscription_number' );
		if ( $number < 1 ) {
			$number = 1;
		}
		$period = (string) wps_src_get_subscription_meta( $subscription_id, 'wps_sfw_subscription_interval' );
		$units  = array(
			'day'   => DAY_IN_SECONDS,
			'week'  => WEEK_IN_SECONDS,
			'month' => 30 * DAY_IN_SECONDS,
			'year'  => 365 * DAY_IN_SECONDS,
		);
		$unit = isset( $units[ $period ] ) ? $units[ $period ] : 30 * DAY_IN_SECONDS;
		return max( DAY_IN_SECONDS, $number * $unit );
	}
}

/**
 * Prevent a subscription from being charged twice for the same billing period.
 *
 * The base plugin (Subscriptions for WooCommerce) re-registers its renewal
 * Action Scheduler job on every `init`, guarded only by as_next_scheduled_action(),
 * which returns false while the recurring job is mid-run. Under that race the
 * store ends up with two live recurring schedules, so two renewal workers run per
 * tick. The base renewal loop selects due subscriptions, advances the next-payment
 * date and charges Stripe non-atomically, with no subscription-level lock and its
 * own Stripe idempotency guard (lock_order_payment) commented out. When the two
 * workers overlap, the same due subscription is charged twice; the renewal-tracking
 * meta is lost-updated, so the duplicate order is orphaned and the store's records
 * look clean while the customer paid twice.
 *
 * This guard closes that hole from the add-on side. It runs before the discount
 * application (priority 5 vs 20) on the same hook the base fires after building the
 * renewal order but before charging it. It claims one renewal per subscription per
 * billing cycle atomically, then zeroes and cancels any second order for the same
 * cycle so the base gateway charge (which returns early when the order total is
 * <= 0) never charges the customer again.
 *
 * The claim is keyed on the subscription alone (not the due date), because the base
 * plugin advances the next-payment date mid-run: two overlapping workers can read
 * different dates for the same cycle, so a date-based key would miss the overlap.
 * A per-subscription timestamp is claimed by an atomic INSERT (first ever) or an
 * atomic conditional UPDATE that only flips the stored timestamp when it is older
 * than the cooldown. InnoDB re-evaluates the UPDATE's WHERE under the row lock, so
 * exactly one of any number of concurrent runs matches a row and proceeds; the rest
 * are duplicates. The cooldown is half the billing interval: longer than any
 * plausible skew between duplicate workers, shorter than the gap to the next
 * legitimate renewal, so real next-cycle renewals always pass while every extra
 * renewal inside the current cycle is caught.
 *
 * @param WC_Order|int $order           Renewal order (object or ID).
 * @param int          $subscription_id Subscription ID.
 */
if ( ! function_exists( 'wps_src_guard_duplicate_renewal' ) ) {
	function wps_src_guard_duplicate_renewal( $order, $subscription_id ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		global $wpdb;
		$now      = time();
		$cooldown = max( 10 * MINUTE_IN_SECONDS, (int) ( wps_src_renewal_interval_seconds( $subscription_id ) / 2 ) );
		$key      = 'wps_src_renclaim_' . (int) $subscription_id;

		$proceed = false;
		// First renewal ever for this subscription: atomic INSERT against the UNIQUE
		// option_name column. autoload 'no' keeps the row out of the alloptions cache.
		if ( add_option( $key, (string) $now, '', 'no' ) ) {
			$proceed = true;
		} else {
			// Otherwise flip the timestamp only if the last claim is older than the
			// cooldown. Exactly one concurrent run's UPDATE matches; the rest see 0
			// affected rows and are duplicates.
			$rows    = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) <= %d",
					(string) $now,
					$key,
					$now - $cooldown
				)
			);
			$proceed = ( $rows >= 1 );
		}

		if ( $proceed ) {
			return;
		}

		// Duplicate renewal within the current cycle. Zero the lines so the base
		// gateway charge skips it, and mark it so the discount application below
		// leaves it alone.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item->set_subtotal( 0 );
			$item->set_total( 0 );
		}
		$order->update_meta_data( WPS_SRC_DUP_META, 'yes' );
		$order->calculate_totals( false );
		$order->set_status( 'cancelled' );
		$order->add_order_note(
			sprintf(
				/* translators: %d: subscription ID */
				__( 'Duplicate renewal suppressed by Subscriptions for WooCommerce Extra: subscription #%d was already renewed for this billing period. This order was zeroed and cancelled to prevent a double charge.', 'wps-subscriptions-extra' ),
				(int) $subscription_id
			)
		);
		$order->save();
	}
}
add_action( 'wps_sfw_renewal_order_creation', 'wps_src_guard_duplicate_renewal', 5, 2 );

/**
 * Collapse duplicate recurring renewal/expiry schedules down to one each.
 *
 * The base plugin re-registers these recurring Action Scheduler jobs on every
 * `init`, guarded only by as_next_scheduled_action(), which returns false while
 * the recurring job is mid-run; that race can leave two (or more) live recurring
 * series, doubling how often the renewal loop runs and thereby the double-charge
 * window. This keeps the earliest-scheduled pending occurrence of each hook and
 * cancels the extras. A cancelled recurring occurrence never runs, so it never
 * reschedules itself and the surplus series dies; the kept series keeps recurring.
 *
 * Runs on admin_init so it self-heals whenever an admin loads wp-admin, without a
 * dedicated cron of its own.
 */
if ( ! function_exists( 'wps_src_dedupe_renewal_schedule' ) ) {
	function wps_src_dedupe_renewal_schedule() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		$hooks = array( 'wps_sfw_create_renewal_order_schedule', 'wps_sfw_expired_renewal_subscription' );
		foreach ( $hooks as $hook ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => 'pending',
					'per_page' => 100,
					'orderby'  => 'date',
					'order'    => 'ASC',
				),
				'ids'
			);

			if ( ! is_array( $ids ) || count( $ids ) <= 1 ) {
				continue;
			}

			array_shift( $ids ); // Keep the earliest-scheduled occurrence.
			foreach ( $ids as $extra ) {
				ActionScheduler::store()->cancel_action( (int) $extra );
			}
		}
	}
}
add_action( 'admin_init', 'wps_src_dedupe_renewal_schedule' );

/**
 * Option prefix under which a pre-deactivation backup of the WooCommerce Stripe
 * gateway settings is stored, so "Deactivate live keys" is fully reversible.
 */
define( 'WPS_SRC_STRIPE_BACKUP_PREFIX', 'wps_src_stripe_settings_backup_' );

/**
 * Whether this install looks like something other than production.
 *
 * Trusts an explicit wp_get_environment_type() first (local/development/staging
 * are all non-production), then falls back to host heuristics: a development TLD
 * (.local/.test/.dev/.localhost/.example/.invalid) or a staging/dev/test/qa/uat
 * host label. A plain unrecognised host is treated as production so a real store
 * is never nagged.
 *
 * @return bool
 */
if ( ! function_exists( 'wps_src_is_nonproduction_env' ) ) {
	function wps_src_is_nonproduction_env() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$type = wp_get_environment_type();
			if ( in_array( $type, array( 'local', 'development', 'staging' ), true ) ) {
				return true;
			}
			if ( 'production' === $type && ! defined( 'WP_ENVIRONMENT_TYPE' ) && ! getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
				// 'production' is also the default when nothing is set, so fall
				// through to the host heuristics rather than trusting it blindly.
				$type = '';
			}
			if ( 'production' === $type ) {
				return false;
			}
		}

		$host = '';
		if ( function_exists( 'home_url' ) ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$host = strtolower( $host );
		if ( '' === $host ) {
			return false;
		}

		foreach ( array( '.local', '.test', '.dev', '.localhost', '.example', '.invalid' ) as $tld ) {
			if ( substr( $host, -strlen( $tld ) ) === $tld ) {
				return true;
			}
		}
		if ( 'localhost' === $host || '127.0.0.1' === $host ) {
			return true;
		}
		if ( preg_match( '/(^|[.-])(staging|stg|dev|test|qa|uat|sandbox|local)([.-]|$)/', $host ) ) {
			return true;
		}

		return false;
	}
}

/**
 * Whether a live Stripe key is present in the WooCommerce Stripe gateway
 * settings, and whether it is currently armed (test mode off, so a charge would
 * hit the live key).
 *
 * The base plugin's renewal charge class extends WC_Gateway_Stripe and reads its
 * keys from this same option, so this one option governs the renewal charge path.
 *
 * @return array|false { armed:bool, enabled:bool, has_live:bool, fields:string[] }
 *                     or false when no live key is present at all.
 */
if ( ! function_exists( 'wps_src_active_live_stripe' ) ) {
	function wps_src_active_live_stripe() {
		$settings = get_option( 'woocommerce_stripe_settings' );
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$live_fields = array();
		$candidates  = array(
			'secret_key'      => '/^(sk|rk)_live_/',
			'publishable_key' => '/^pk_live_/',
		);
		foreach ( $candidates as $field => $pattern ) {
			$value = isset( $settings[ $field ] ) ? trim( (string) $settings[ $field ] ) : '';
			if ( '' !== $value && preg_match( $pattern, $value ) ) {
				$live_fields[] = $field;
			}
		}

		if ( empty( $live_fields ) ) {
			return false;
		}

		$testmode = ! isset( $settings['testmode'] ) || 'yes' === $settings['testmode'];

		return array(
			'armed'   => ! $testmode,
			'enabled' => isset( $settings['enabled'] ) && 'yes' === $settings['enabled'],
			'has_live' => true,
			'fields'  => $live_fields,
		);
	}
}

/**
 * Build the admin URL that triggers the reversible live-key deactivation.
 *
 * @return string
 */
if ( ! function_exists( 'wps_src_deactivate_live_keys_url' ) ) {
	function wps_src_deactivate_live_keys_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wps_src_deactivate_live_keys' ),
			'wps_src_deactivate_live_keys',
			'wps_src_env_nonce'
		);
	}
}

/**
 * Reversibly neutralise the live Stripe keys on a non-production install.
 *
 * Backs the whole gateway settings array up to a timestamped option first, then
 * forces test mode on and blanks the live secret and publishable keys so the
 * renewal charge path cannot reach Stripe live. The gateway's enabled flag and
 * the renewal cron are intentionally left untouched: the named action is about
 * the keys, and leaving the rest lets an admin restore from the backup and carry
 * on. Nothing here is destructive.
 *
 * @return array { backup_key:string, blanked:string[] }
 */
if ( ! function_exists( 'wps_src_deactivate_live_keys' ) ) {
	function wps_src_deactivate_live_keys() {
		$settings = get_option( 'woocommerce_stripe_settings' );
		if ( ! is_array( $settings ) ) {
			return array( 'backup_key' => '', 'blanked' => array() );
		}

		$backup_key = WPS_SRC_STRIPE_BACKUP_PREFIX . gmdate( 'Ymd_His' );
		add_option( $backup_key, $settings, '', 'no' );

		$blanked = array();
		foreach ( array( 'secret_key', 'publishable_key' ) as $field ) {
			$value = isset( $settings[ $field ] ) ? trim( (string) $settings[ $field ] ) : '';
			if ( '' !== $value && preg_match( '/^(sk|rk|pk)_live_/', $value ) ) {
				$settings[ $field ] = '';
				$blanked[]          = $field;
			}
		}
		$settings['testmode'] = 'yes';

		update_option( 'woocommerce_stripe_settings', $settings );

		return array( 'backup_key' => $backup_key, 'blanked' => $blanked );
	}
}

/**
 * admin-post handler for the "Deactivate live keys" button.
 */
if ( ! function_exists( 'wps_src_handle_deactivate_live_keys' ) ) {
	function wps_src_handle_deactivate_live_keys() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wps-subscriptions-extra' ) );
		}
		check_admin_referer( 'wps_src_deactivate_live_keys', 'wps_src_env_nonce' );

		$result = wps_src_deactivate_live_keys();

		$redirect = add_query_arg(
			array(
				'page'                  => 'subscriptions_for_woocommerce_extra',
				'wps_src_env_done'      => 1,
				'wps_src_env_backup'    => rawurlencode( $result['backup_key'] ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
add_action( 'admin_post_wps_src_deactivate_live_keys', 'wps_src_handle_deactivate_live_keys' );

/**
 * Site-wide admin notice: a live Stripe key on a non-production install is the
 * exact condition that lets a staging or local clone charge real customer cards.
 * Shown on every admin screen so it cannot be missed, with the one-click
 * reversible deactivation.
 */
if ( ! function_exists( 'wps_src_environment_notice' ) ) {
	function wps_src_environment_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wps_src_is_nonproduction_env() ) {
			return;
		}
		$live = wps_src_active_live_stripe();
		if ( false === $live ) {
			return;
		}

		$class = $live['armed'] ? 'notice-error' : 'notice-warning';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html__( 'Environment check: live Stripe key on a non-production site', 'wps-subscriptions-extra' ) . '</strong></p>';

		if ( $live['armed'] ) {
			echo '<p>' . esc_html__( 'This install does not look like production, yet the WooCommerce Stripe gateway holds a live key with test mode OFF. Any renewal that runs here can charge a real customer card. This is how a staging or local clone double-charges customers.', 'wps-subscriptions-extra' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'This install does not look like production, yet the WooCommerce Stripe gateway holds a live key. Test mode is currently on, but a single toggle would arm it against real cards.', 'wps-subscriptions-extra' ) . '</p>';
		}

		echo '<p><a href="' . esc_url( wps_src_deactivate_live_keys_url() ) . '" class="button button-primary" onclick="return confirm( \'' . esc_js( __( 'Back up and blank the live Stripe keys on this site, and force test mode on? A timestamped backup is saved so you can restore.', 'wps-subscriptions-extra' ) ) . '\' );">' . esc_html__( 'Deactivate live keys', 'wps-subscriptions-extra' ) . '</a></p></div>';
	}
}
add_action( 'admin_notices', 'wps_src_environment_notice' );

/**
 * Apply the recorded coupon discount(s) to a renewal order.
 *
 * Fired after the base plugin has built, totalled, and saved the renewal order.
 * Discounts stack on the running line total so multiple coupons compound in a
 * defined order. Percent coupons scale each line; product coupons multiply by
 * quantity; flat (fixed_cart) coupons spend a single amount across lines, so a
 * multi-line order is not over-discounted. Taxes are recomputed on the discounted
 * base.
 *
 * @param WC_Order|int $order           Renewal order (object or ID).
 * @param int          $subscription_id Subscription ID.
 */
if ( ! function_exists( 'wps_src_apply_recurring_coupons' ) ) {
	function wps_src_apply_recurring_coupons( $order, $subscription_id ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		// Re-entrancy guard: never discount the same renewal twice.
		if ( 'yes' === $order->get_meta( WPS_SRC_APPLIED_META ) ) {
			return;
		}

		// Duplicate renewal already zeroed by the duplicate-charge guard: leave it
		// at zero so the base gateway charge skips it.
		if ( 'yes' === $order->get_meta( WPS_SRC_DUP_META ) ) {
			return;
		}

		$snapshot = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META );
		if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
			return;
		}

		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
			return;
		}

		// PayPal Standard captures the full billing-agreement amount via IPN
		// before this renewal order even exists, so discounting the order here
		// would not change what the customer was actually charged. Checked after
		// the snapshot load so the note only appears on renewals that would
		// otherwise have been discounted. The applied guard is intentionally
		// left unset since no discount was applied.
		if ( 'paypal' === $order->get_payment_method() ) {
			$order->add_order_note(
				__( 'Coupon discount not applied: this renewal was paid via PayPal Standard, which charges the full billing-agreement amount before the renewal order is created, so discounting the order would not match what the customer was charged.', 'wps-subscriptions-extra' )
			);
			return;
		}

		$mode        = wps_src_get_discount_mode( $subscription_id );
		$applied_any = false;

		if ( 'live' === $mode ) {
			// Re-validate each coupon against its current rules and let
			// WooCommerce compute the discount. Expired, deleted, or
			// usage-exhausted coupons simply yield no discount.
			foreach ( $snapshot as $cpn ) {
				$code = isset( $cpn['code'] ) ? $cpn['code'] : '';
				if ( '' === $code ) {
					continue;
				}
				$result = $order->apply_coupon( $code );
				if ( is_wp_error( $result ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: coupon code, 2: error message from WooCommerce */
							__( 'Coupon "%1$s" could not be applied to this renewal: %2$s', 'wps-subscriptions-extra' ),
							$code,
							$result->get_error_message()
						)
					);
				} else {
					$applied_any = true;
				}
			}
		} else {
			// Price-lock: recompute the discount from the snapshot, independent
			// of the coupon's current state, via the helper shared with the
			// admin preview so the two cannot drift.
			$lines = array();
			foreach ( $items as $item ) {
				$lines[] = array(
					'total' => (float) $item->get_total(),
					'qty'   => $item->get_quantity(),
				);
			}

			$computed   = wps_src_compute_lock_discounts( $snapshot, $lines );
			$item_index = array_values( $items );

			foreach ( $computed['lines'] as $i => $reduction ) {
				if ( $reduction <= 0 ) {
					continue;
				}
				$item = $item_index[ $i ];
				$item->set_total( (float) $item->get_total() - $reduction );
			}

			foreach ( $computed['coupons'] as $code => $coupon_discount ) {
				if ( $coupon_discount <= 0 ) {
					continue;
				}
				$coupon_item = new WC_Order_Item_Coupon();
				$coupon_item->set_code( $code );
				$coupon_item->set_discount( $coupon_discount );
				$coupon_item->set_discount_tax( 0 );
				$order->add_item( $coupon_item );
				$applied_any = true;
			}
		}

		if ( ! $applied_any ) {
			return;
		}

		// The guard must be set on the order object before calculate_totals():
		// its calculate_taxes() step triggers an internal $order->save(), and that
		// save must persist the guard together with the discounted items, or a
		// crash between the two saves would leave a discounted-but-unguarded
		// order that could be discounted again.
		$order->update_meta_data( WPS_SRC_APPLIED_META, 'yes' );

		// Live mode already recalculated via apply_coupon(); only lock mode needs
		// the manually discounted lines re-summed with taxes.
		if ( 'live' !== $mode ) {
			$order->calculate_totals( true );
		}

		// Final save covers the live-mode path and is a safe no-op when
		// calculate_totals() already persisted everything.
		$order->save();

		$order->add_order_note(
			__( 'Original subscription coupon discount re-applied to this renewal by Subscriptions for WooCommerce Extra.', 'wps-subscriptions-extra' )
		);
	}
}
add_action( 'wps_sfw_renewal_order_creation', 'wps_src_apply_recurring_coupons', 20, 2 );

/**
 * Backfill coupon snapshots for subscriptions created before this plugin was
 * active. Shared core used by the WP-CLI command and the admin page.
 *
 * Subscriptions with an existing snapshot, manual subscriptions, and those whose
 * parent order is gone or carries no eligible coupon are skipped. The optional
 * scope narrows which subscriptions are considered.
 *
 * @param bool  $dry_run When true, report without writing meta.
 * @param array $scope   Optional filters: 'active_only' (bool), 'coupon' (string),
 *                       'ids' (int[]), 'date_from'/'date_to' (Y-m-d strings).
 * @return array { updated:int, skipped:int, lines:string[], dry:bool }
 */
if ( ! function_exists( 'wps_src_run_backfill' ) ) {
	function wps_src_run_backfill( $dry_run, $scope = array() ) {
		$result = array(
			'updated' => 0,
			'skipped' => 0,
			'lines'   => array(),
			'dry'     => (bool) $dry_run,
		);

		wps_src_walk_subscriptions(
			function ( $subscription_id ) use ( $dry_run, $scope, &$result ) {
				if ( ! wps_src_subscription_in_scope( $subscription_id, $scope ) ) {
					$result['skipped']++;
					return;
				}

				$existing = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META );
				if ( ! empty( $existing ) ) {
					$result['skipped']++;
					return;
				}

				$parent_order_id = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_PARENT_META );
				if ( empty( $parent_order_id ) || 'manual' === $parent_order_id ) {
					$result['skipped']++;
					return;
				}

				$snapshot = wps_src_build_snapshot( $parent_order_id, $subscription_id );
				if ( empty( $snapshot ) ) {
					$result['skipped']++;
					return;
				}

				// Coupon scope is checked here because it needs the built snapshot.
				if ( ! empty( $scope['coupon'] ) ) {
					$codes = array_map( 'strtolower', wp_list_pluck( $snapshot, 'code' ) );
					if ( ! in_array( strtolower( $scope['coupon'] ), $codes, true ) ) {
						$result['skipped']++;
						return;
					}
				}

				if ( ! $dry_run ) {
					wps_src_update_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META, $snapshot );
				}

				$result['lines'][] = sprintf(
					/* translators: 1: verb, 2: subscription ID, 3: order ID, 4: coupon codes */
					_x( '%1$s subscription #%2$d from order #%3$s: %4$s', 'backfill log line', 'wps-subscriptions-extra' ),
					$dry_run ? __( 'Would snapshot', 'wps-subscriptions-extra' ) : __( 'Snapshotted', 'wps-subscriptions-extra' ),
					$subscription_id,
					$parent_order_id,
					implode( ', ', wp_list_pluck( $snapshot, 'code' ) )
				);
				$result['updated']++;
			}
		);

		return $result;
	}
}

/**
 * Whether a subscription passes the non-coupon scope filters.
 *
 * @param int   $subscription_id Subscription ID.
 * @param array $scope           Scope filters.
 * @return bool
 */
if ( ! function_exists( 'wps_src_subscription_in_scope' ) ) {
	function wps_src_subscription_in_scope( $subscription_id, $scope ) {
		if ( ! empty( $scope['ids'] ) && ! in_array( (int) $subscription_id, array_map( 'intval', $scope['ids'] ), true ) ) {
			return false;
		}

		if ( ! empty( $scope['active_only'] ) && 'active' !== wps_src_get_subscription_meta( $subscription_id, WPS_SRC_STATUS_META ) ) {
			return false;
		}

		if ( ! empty( $scope['date_from'] ) || ! empty( $scope['date_to'] ) ) {
			$order   = wc_get_order( $subscription_id );
			$created = ( $order && $order->get_date_created() ) ? $order->get_date_created()->getTimestamp() : 0;
			if ( ! empty( $scope['date_from'] ) && $created < strtotime( $scope['date_from'] . ' 00:00:00' ) ) {
				return false;
			}
			if ( ! empty( $scope['date_to'] ) && $created > strtotime( $scope['date_to'] . ' 23:59:59' ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * Collect dashboard statistics in a single walk.
 *
 * @return array { total, with_snapshot, eligible_missing, ineligible, expired[] }
 */
if ( ! function_exists( 'wps_src_collect_stats' ) ) {
	function wps_src_collect_stats() {
		$stats = array(
			'total'            => 0,
			'with_snapshot'    => 0,
			'eligible_missing' => 0,
			'ineligible'       => 0,
			'expired'          => array(),
		);

		wps_src_walk_subscriptions(
			function ( $id ) use ( &$stats ) {
				$stats['total']++;
				$snap = wps_src_get_subscription_meta( $id, WPS_SRC_SNAPSHOT_META );

				if ( ! empty( $snap ) && is_array( $snap ) ) {
					$stats['with_snapshot']++;
					foreach ( $snap as $cpn ) {
						$code = isset( $cpn['code'] ) ? $cpn['code'] : '';
						if ( '' !== $code && function_exists( 'wc_get_coupon_id_by_code' ) && ! wc_get_coupon_id_by_code( $code ) ) {
							$stats['expired'][] = array( 'sub' => $id, 'code' => $code );
						}
					}
					return;
				}

				$parent   = wps_src_get_subscription_meta( $id, WPS_SRC_PARENT_META );
				$eligible = ( ! empty( $parent ) && 'manual' !== $parent && ! empty( wps_src_build_snapshot( $parent, $id ) ) );
				$active   = ( 'active' === wps_src_get_subscription_meta( $id, WPS_SRC_STATUS_META ) );
				if ( $eligible && $active ) {
					$stats['eligible_missing']++;
				} else {
					$stats['ineligible']++;
				}
			}
		);

		return $stats;
	}
}

/**
* Cached wrapper around wps_src_collect_stats(): avoids a full walk on every
* admin page load by caching the result in a short-lived transient.
*
* @param bool $force Recompute and refresh the cache.
* @return array
*/
if ( ! function_exists( 'wps_src_get_stats' ) ) {
	function wps_src_get_stats( $force = false ) {
		$cached = $force ? false : get_transient( WPS_SRC_STATS_TRANSIENT );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$stats = wps_src_collect_stats();
		set_transient( WPS_SRC_STATS_TRANSIENT, $stats, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}
}

/**
* Invalidate the cached dashboard stats after a snapshot-changing action.
*/
if ( ! function_exists( 'wps_src_bust_stats' ) ) {
	function wps_src_bust_stats() {
		delete_transient( WPS_SRC_STATS_TRANSIENT );
	}
}

/**
 * Apply the snapshot discount to renewal orders generated at full price before a
 * snapshot existed (pending, on-hold, or failed, and not yet discounted).
 *
 * @param bool $dry_run When true, report without modifying any order.
 * @return array { updated:int, skipped:int, lines:string[], dry:bool }
 */
if ( ! function_exists( 'wps_src_fix_pending_renewals' ) ) {
	function wps_src_fix_pending_renewals( $dry_run ) {
		$result = array(
			'updated' => 0,
			'skipped' => 0,
			'lines'   => array(),
			'dry'     => (bool) $dry_run,
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $result;
		}

		// Paged so memory stays bounded on large stores, matching the
		// subscription walk used elsewhere.
		$paged = 1;
		do {
			$order_ids = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'status'     => array( 'pending', 'on-hold', 'failed' ),
					'limit'      => WPS_SRC_BATCH_SIZE,
					'paged'      => $paged,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'return'     => 'ids',
					'meta_key'   => WPS_SRC_RENEWAL_FLAG_META,
					'meta_value' => 'yes',
				)
			);

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order || 'yes' === $order->get_meta( WPS_SRC_APPLIED_META ) ) {
					$result['skipped']++;
					continue;
				}

				$subscription_id = $order->get_meta( WPS_SRC_SUBSCRIPTION_REF_META );
				if ( empty( $subscription_id ) ) {
					$result['skipped']++;
					continue;
				}

				$snapshot = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META );
				if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
					$result['skipped']++;
					continue;
				}

				if ( ! $dry_run ) {
					wps_src_apply_recurring_coupons( $order, $subscription_id );
				}

				$result['lines'][] = sprintf(
					/* translators: 1: verb, 2: order ID, 3: subscription ID */
					_x( '%1$s renewal #%2$d (subscription #%3$s)', 'fix-renewal log line', 'wps-subscriptions-extra' ),
					$dry_run ? __( 'Would fix', 'wps-subscriptions-extra' ) : __( 'Fixed', 'wps-subscriptions-extra' ),
					$order_id,
					$subscription_id
				);
				$result['updated']++;
			}

			$paged++;
		} while ( count( $order_ids ) === WPS_SRC_BATCH_SIZE );

		return $result;
	}
}

/**
 * Whether a renewal order is eligible for a manual retry charge.
 *
 * A retry is only ever allowed on an order that is unmistakably unpaid, so it
 * cannot double-charge one that already went through. All of these must hold:
 * the order is a renewal, its payment method is Stripe (the only gateway whose
 * off-session charge this can safely re-invoke; PayPal Standard captures the
 * whole agreement out-of-band, so retrying it would double), its status is one
 * of the not-yet-paid states, it carries no transaction id (so no prior capture
 * succeeded behind a mislabelled status), its total is positive, and it was not
 * zeroed by the duplicate-renewal guard.
 *
 * @param WC_Order $order Renewal order.
 * @return true|WP_Error True when retryable, else a WP_Error explaining why not.
 */
if ( ! function_exists( 'wps_src_can_retry_renewal' ) ) {
	function wps_src_can_retry_renewal( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return new WP_Error( 'wps_src_no_order', __( 'Order not found.', 'wps-subscriptions-extra' ) );
		}
		if ( 'yes' !== $order->get_meta( WPS_SRC_RENEWAL_FLAG_META ) ) {
			return new WP_Error( 'wps_src_not_renewal', __( 'Not a subscription renewal order.', 'wps-subscriptions-extra' ) );
		}
		if ( 'stripe' !== $order->get_payment_method() ) {
			return new WP_Error( 'wps_src_not_stripe', __( 'Manual retry is only supported for Stripe renewals.', 'wps-subscriptions-extra' ) );
		}
		if ( ! in_array( $order->get_status(), array( 'pending', 'failed', 'on-hold' ), true ) ) {
			return new WP_Error( 'wps_src_paid', __( 'This renewal is not in an unpaid state, so it will not be retried.', 'wps-subscriptions-extra' ) );
		}
		if ( '' !== (string) $order->get_transaction_id() ) {
			return new WP_Error( 'wps_src_captured', __( 'This renewal already has a transaction id: a charge already succeeded, so it will not be retried.', 'wps-subscriptions-extra' ) );
		}
		if ( (float) $order->get_total() <= 0 ) {
			return new WP_Error( 'wps_src_zero', __( 'This renewal has a zero total, so there is nothing to charge.', 'wps-subscriptions-extra' ) );
		}
		if ( 'yes' === $order->get_meta( WPS_SRC_DUP_META ) ) {
			return new WP_Error( 'wps_src_dup', __( 'This renewal was suppressed as a duplicate, so it will not be charged.', 'wps-subscriptions-extra' ) );
		}
		return true;
	}
}

/**
 * Manually retry the Stripe charge for one failed renewal order, exactly once.
 *
 * The eligibility gate (wps_src_can_retry_renewal) guarantees the order is
 * unpaid and un-captured. On top of that, an atomic in-flight lock is claimed by
 * an INSERT against the UNIQUE option_name column: exactly one caller can hold
 * it, so a double-clicked button (or any overlap with another charge attempt for
 * the same order) cannot fire two charges. Eligibility is re-checked after the
 * lock is held, against a freshly reloaded order, closing the read-then-charge
 * window. The lock is released in a finally-style teardown so a genuinely failed
 * attempt stays retryable; a successful one flips the order out of the unpaid
 * states, so the eligibility gate refuses any further retry regardless.
 *
 * The charge itself is delegated to the base plugin by re-firing the same action
 * its scheduler uses (wps_sfw_other_payment_gateway_renewal), so this stays
 * correct across base-plugin updates and reuses the base gateway's own Stripe
 * idempotency handling.
 *
 * @param int $order_id Renewal order ID.
 * @return array|WP_Error { charged:bool, status:string } or a WP_Error.
 */
if ( ! function_exists( 'wps_src_retry_renewal' ) ) {
	function wps_src_retry_renewal( $order_id ) {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );

		$eligible = wps_src_can_retry_renewal( $order );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$lock_key = 'wps_src_retry_' . $order_id;
		$now      = time();
		$stale    = 5 * MINUTE_IN_SECONDS;

		// Atomic claim. add_option() returns false when the row already exists.
		if ( ! add_option( $lock_key, (string) $now, '', 'no' ) ) {
			$held = (int) get_option( $lock_key, 0 );
			if ( $held && ( $now - $held ) < $stale ) {
				return new WP_Error( 'wps_src_inflight', __( 'A charge attempt for this renewal is already in progress. Try again in a moment.', 'wps-subscriptions-extra' ) );
			}
			// Stale lock from an attempt that died mid-run: take it over atomically.
			global $wpdb;
			$rows = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) <= %d",
					(string) $now,
					$lock_key,
					$now - $stale
				)
			);
			if ( $rows < 1 ) {
				return new WP_Error( 'wps_src_inflight', __( 'A charge attempt for this renewal is already in progress. Try again in a moment.', 'wps-subscriptions-extra' ) );
			}
		}

		// Re-check eligibility under the lock against a fresh order read.
		$order    = wc_get_order( $order_id );
		$eligible = wps_src_can_retry_renewal( $order );
		if ( is_wp_error( $eligible ) ) {
			delete_option( $lock_key );
			return $eligible;
		}

		$subscription_id = (int) $order->get_meta( WPS_SRC_SUBSCRIPTION_REF_META );

		// Delegate the charge to the base plugin's own Stripe renewal path.
		do_action( 'wps_sfw_other_payment_gateway_renewal', $order, $subscription_id, 'stripe' );

		delete_option( $lock_key );

		$after   = wc_get_order( $order_id );
		$status  = $after ? $after->get_status() : 'unknown';
		$charged = $after && ( in_array( $status, array( 'processing', 'completed' ), true ) || '' !== (string) $after->get_transaction_id() );

		if ( $charged ) {
			$order->add_order_note( __( 'Renewal charge retried manually via Subscriptions for WooCommerce Extra: charge succeeded.', 'wps-subscriptions-extra' ) );
		}

		return array( 'charged' => (bool) $charged, 'status' => $status );
	}
}

/**
 * List recent renewal orders that are unpaid and Stripe-based, for the manual
 * retry section on the admin page.
 *
 * @param int $limit Maximum orders to return.
 * @return int[] Order IDs, newest first.
 */
if ( ! function_exists( 'wps_src_failed_renewal_ids' ) ) {
	function wps_src_failed_renewal_ids( $limit = 25 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		return (array) wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array( 'failed', 'pending', 'on-hold' ),
				'limit'      => (int) $limit,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'ids',
				'meta_key'   => WPS_SRC_RENEWAL_FLAG_META,
				'meta_value' => 'yes',
			)
		);
	}
}

/**
 * Substrings the base plugin's Stripe and Stripe SEPA gateways write into an
 * order note when an off-session renewal charge attempt reaches Stripe and is
 * declined or otherwise rejected (subscriptions-for-woocommerce
 * package/gateways/stripe(-sepa)/class-wps-subscriptions-payment-stripe*.php).
 * A renewal order carrying none of these notes never reached the gateway at
 * all: the order sitting unpaid is not itself proof a card was ever tried.
 *
 * @return string[]
 */
if ( ! function_exists( 'wps_src_decline_note_markers' ) ) {
	function wps_src_decline_note_markers() {
		return array( 'unable to process your payment', 'requires authentication', 'charge awaiting authentication' );
	}
}

/**
 * Whether a renewal order shows evidence of an actual charge attempt, versus
 * having simply never been tried yet (e.g. its cron pass has not run, or it
 * failed before reaching the gateway call).
 *
 * @param WC_Order $order Renewal order.
 * @return string 'declined' when an order note matches a known gateway
 *                failure message, else 'no_attempt'.
 */
if ( ! function_exists( 'wps_src_renewal_attempt_status' ) ) {
	function wps_src_renewal_attempt_status( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return 'no_attempt';
		}
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$content = strtolower( wp_strip_all_tags( $note->content ) );
			foreach ( wps_src_decline_note_markers() as $marker ) {
				if ( false !== strpos( $content, $marker ) ) {
					return 'declined';
				}
			}
		}
		return 'no_attempt';
	}
}

/**
 * Compute a subscription's next-renewal price before and after the snapshot
 * discount, without creating an order. Single-line estimate.
 *
 * The discount math goes through wps_src_compute_lock_discounts(), the same
 * helper the actual renewal application uses, so preview and application
 * cannot drift.
 *
 * @param int $subscription_id Subscription ID.
 * @return array { full:float, discounted:float, has_snapshot:bool }
 */
if ( ! function_exists( 'wps_src_preview_amounts' ) ) {
	function wps_src_preview_amounts( $subscription_id ) {
		$snapshot = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META );
		$base     = (float) wps_src_get_subscription_meta( $subscription_id, 'line_total' );
		$qty      = (int) wps_src_get_subscription_meta( $subscription_id, 'product_qty' );
		if ( $qty < 1 ) {
			$qty = 1;
		}

		$running = $base;
		if ( is_array( $snapshot ) ) {
			$computed = wps_src_compute_lock_discounts( $snapshot, array( array( 'total' => $base, 'qty' => $qty ) ) );
			$running  = $base - $computed['lines'][0];
		}

		$next_date = (int) wps_src_get_subscription_meta( $subscription_id, 'wps_next_payment_date' );

		return array(
			'full'         => $base,
			'discounted'   => max( 0, $running ),
			'has_snapshot' => ! empty( $snapshot ),
			'next_date'    => $next_date,
		);
	}
}

/**
 * Price info for the My Account display: the subscription's full recurring
 * total and the total after the locked discount.
 *
 * Returns null (show nothing, base plugin renders the full price as usual)
 * unless all of these hold: a snapshot exists, the effective discount mode is
 * price-lock (in live mode the renewal amount depends on coupon validation at
 * charge time, so no number can be promised), the payment method is not PayPal
 * Standard (PayPal captures the full billing-agreement amount regardless), the
 * subscription is not cancelled, and the discount is actually positive.
 *
 * The discount is computed from the line-total preview and subtracted from the
 * subscription's stored total, so tax/shipping components of the total are
 * preserved; the discount itself is treated as tax-free here, matching how the
 * renewal order applies it before recalculating totals.
 *
 * Cached per request: the templates fire the filter and the action for the
 * same subscription back to back, and the list table repeats per row.
 *
 * @param int $subscription_id Subscription ID.
 * @return array|null { full:float, discounted:float } or null to show nothing.
 */
if ( ! function_exists( 'wps_src_account_price_info' ) ) {
	function wps_src_account_price_info( $subscription_id ) {
		static $cache = array();
		$subscription_id = (int) $subscription_id;
		if ( array_key_exists( $subscription_id, $cache ) ) {
			return $cache[ $subscription_id ];
		}
		$cache[ $subscription_id ] = null;

		if ( 'lock' !== wps_src_get_discount_mode( $subscription_id ) ) {
			return null;
		}
		if ( 'cancelled' === wps_src_get_subscription_meta( $subscription_id, WPS_SRC_STATUS_META ) ) {
			return null;
		}

		$subscription = wc_get_order( $subscription_id );
		if ( ! $subscription || 'paypal' === $subscription->get_payment_method() ) {
			return null;
		}

		$preview = wps_src_preview_amounts( $subscription_id );
		if ( ! $preview['has_snapshot'] ) {
			return null;
		}
		$discount = $preview['full'] - $preview['discounted'];
		if ( $discount <= 0 ) {
			return null;
		}

		$full = (float) $subscription->get_total();
		if ( $full <= 0 ) {
			$full = (float) wps_src_get_subscription_meta( $subscription_id, 'wps_recurring_total' );
		}
		if ( $full <= 0 ) {
			return null;
		}

		$cache[ $subscription_id ] = array(
			'full'       => $full,
			'discounted' => max( 0, $full - $discount ),
		);
		return $cache[ $subscription_id ];
	}
}

/**
 * My Account: swap the displayed recurring total for the locked renewal price.
 *
 * Runs on the base plugin's wps_sfw_sub_recurring_total_my_account_page filter,
 * which feeds both the subscriptions list table and the single-subscription
 * details view (classic and aurora templates).
 *
 * @param mixed $price           Recurring total about to be displayed.
 * @param int   $subscription_id Subscription ID.
 * @return mixed
 */
if ( ! function_exists( 'wps_src_filter_account_recurring_total' ) ) {
	function wps_src_filter_account_recurring_total( $price, $subscription_id ) {
		$info = wps_src_account_price_info( $subscription_id );
		return ( null !== $info ) ? $info['discounted'] : $price;
	}
}
add_filter( 'wps_sfw_sub_recurring_total_my_account_page', 'wps_src_filter_account_recurring_total', 10, 2 );

/**
 * My Account: append the struck-through original price after the discounted
 * total, so customers see the discount they are keeping. Priority 20 runs
 * after the base plugin's price output on the same action.
 *
 * @param int $subscription_id Subscription ID.
 */
if ( ! function_exists( 'wps_src_render_account_original_price' ) ) {
	function wps_src_render_account_original_price( $subscription_id ) {
		$info = wps_src_account_price_info( $subscription_id );
		if ( null === $info ) {
			return;
		}
		echo ' <del class="wps-src-original-price" aria-label="' . esc_attr(
			sprintf(
				/* translators: %s: original (pre-discount) price */
				__( 'Original price %s', 'wps-subscriptions-extra' ),
				wp_strip_all_tags( wc_price( $info['full'] ) )
			)
		) . '">' . wp_kses_post( wc_price( $info['full'] ) ) . '</del>';
	}
}
add_action( 'wps_sfw_display_susbcription_recerring_total_account_page', 'wps_src_render_account_original_price', 20 );

/**
 * Delete a meta key from a subscription, HPOS-aware.
 *
 * @param int    $subscription_id Subscription ID.
 * @param string $key             Meta key.
 */
if ( ! function_exists( 'wps_src_delete_subscription_meta' ) ) {
	function wps_src_delete_subscription_meta( $subscription_id, $key ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $subscription_id ) : false;
		if ( $order ) {
			if ( $order->meta_exists( $key ) ) {
				$order->delete_meta_data( $key );
				$order->save();
			}
			return;
		}
		delete_post_meta( $subscription_id, $key );
	}
}

/**
 * WP-CLI: backfill coupon snapshots.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Report what would change without writing any meta.
 *
 * ## EXAMPLES
 *
 *     wp wps-extra fix-preexisting --dry-run
 *     wp wps-extra fix-preexisting
 *
 * @param array $args       Positional args (unused).
 * @param array $assoc_args Associative args.
 */
if ( ! function_exists( 'wps_src_cli_backfill' ) ) {
	function wps_src_cli_backfill( $args, $assoc_args ) {
		if ( ! function_exists( 'wps_sfw_get_meta_data' ) || ! function_exists( 'wc_get_orders' ) ) {
			WP_CLI::error( 'Subscriptions for WooCommerce must be active to run this command.' );
		}

		$result = wps_src_run_backfill( isset( $assoc_args['dry-run'] ) );

		foreach ( $result['lines'] as $line ) {
			WP_CLI::log( $line );
		}

		$verb = $result['dry'] ? 'would be updated' : 'updated';
		WP_CLI::success( sprintf( '%d subscription(s) %s, %d skipped.', $result['updated'], $verb, $result['skipped'] ) );
	}
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'wps-extra fix-preexisting', 'wps_src_cli_backfill' );
}

/**
 * Register the admin page as a submenu under WooCommerce, next to the
 * Subscriptions for WooCommerce ("Wps Subscriptions") item.
 */
if ( ! function_exists( 'wps_src_register_admin_page' ) ) {
	function wps_src_register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Subscriptions for WooCommerce Extra', 'wps-subscriptions-extra' ),
			__( 'WPS Subscriptions Extra', 'wps-subscriptions-extra' ),
			'manage_woocommerce',
			'subscriptions_for_woocommerce_extra',
			'wps_src_render_admin_page'
		);
	}
}
add_action( 'admin_menu', 'wps_src_register_admin_page', 99 );

/**
 * Hide the base plugin's separate top-level "WP Swings" menu (add_menu_page
 * slug 'wps-plugins'), when the admin has opted in. The base plugin already
 * registers its actual settings as a submenu under WooCommerce -> Wps
 * Subscriptions, so the top-level entry is a duplicate link to the same
 * plugin, not a distinct feature. Runs late on admin_menu so the base
 * plugin's own (priority-unspecified) registration has already happened;
 * remove_menu_page() only hides the menu item, it does not deactivate
 * anything the base plugin does.
 */
if ( ! function_exists( 'wps_src_maybe_hide_wpswings_menu' ) ) {
	function wps_src_maybe_hide_wpswings_menu() {
		if ( 'yes' === get_option( WPS_SRC_HIDE_WPSWINGS_MENU_OPTION ) ) {
			remove_menu_page( 'wps-plugins' );
		}
	}
}
add_action( 'admin_menu', 'wps_src_maybe_hide_wpswings_menu', 999 );

/**
 * Render a backfill/fix result block: a summary notice plus a capped log.
 *
 * @param array|null $result Result array from a backfill/fix run, or null.
 */
if ( ! function_exists( 'wps_src_render_result' ) ) {
	function wps_src_render_result( $result ) {
		if ( null === $result ) {
			return;
		}

		$verb = $result['dry'] ? __( 'would be updated', 'wps-subscriptions-extra' ) : __( 'updated', 'wps-subscriptions-extra' );
		echo '<div class="notice notice-success"><p><strong>' . sprintf(
			/* translators: 1: count, 2: verb, 3: skipped count */
			esc_html__( '%1$d item(s) %2$s, %3$d skipped.', 'wps-subscriptions-extra' ),
			(int) $result['updated'],
			esc_html( $verb ),
			(int) $result['skipped']
		) . '</strong></p></div>';

		if ( ! empty( $result['lines'] ) ) {
			$max = 1000;
			echo '<textarea readonly rows="12" style="width:100%;font-family:monospace;">';
			echo esc_textarea( implode( "\n", array_slice( $result['lines'], 0, $max ) ) );
			echo '</textarea>';
			if ( count( $result['lines'] ) > $max ) {
				echo '<p class="description">' . sprintf(
					/* translators: 1: shown count, 2: total count */
					esc_html__( 'Showing the first %1$d of %2$d entries.', 'wps-subscriptions-extra' ),
					(int) $max,
					(int) count( $result['lines'] )
				) . '</p>';
			}
		}
	}
}

/**
 * Admin edit URL for a subscription's underlying WooCommerce order, HPOS-aware.
 *
 * @param int $subscription_id Subscription (order) ID.
 * @return string Edit-screen URL.
 */
if ( ! function_exists( 'wps_src_order_edit_url' ) ) {
	function wps_src_order_edit_url( $subscription_id ) {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		if ( class_exists( $util ) && method_exists( $util, 'get_order_admin_edit_url' ) ) {
			return $util::get_order_admin_edit_url( $subscription_id );
		}
		return admin_url( 'post.php?post=' . (int) $subscription_id . '&action=edit' );
	}
}

/**
 * Render the paged subscriptions table with per-row snapshot and mode controls.
 */
if ( ! function_exists( 'wps_src_render_subscriptions_table' ) ) {
	function wps_src_render_subscriptions_table() {
		$per_page = 20;
		$paged    = isset( $_GET['wps_src_paged'] ) ? max( 1, absint( $_GET['wps_src_paged'] ) ) : 1;

		$ids = wc_get_orders(
			array(
				'type'    => WPS_SRC_SUBSCRIPTION_TYPE,
				'status'  => 'any',
				'limit'   => $per_page + 1,
				'paged'   => $paged,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		$has_more = count( $ids ) > $per_page;
		if ( $has_more ) {
			array_pop( $ids );
		}

		echo '<h2>' . esc_html__( 'Subscriptions', 'wps-subscriptions-extra' ) . '</h2>';
		if ( empty( $ids ) ) {
			echo '<p>' . esc_html__( 'No subscriptions found.', 'wps-subscriptions-extra' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'wps-subscriptions-extra' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wps-subscriptions-extra' ) . '</th>';
		echo '<th>' . esc_html__( 'Snapshot', 'wps-subscriptions-extra' ) . '</th>';
		echo '<th>' . esc_html__( 'Mode', 'wps-subscriptions-extra' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wps-subscriptions-extra' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $ids as $id ) {
			$status   = wps_src_get_subscription_meta( $id, WPS_SRC_STATUS_META );
			$snap     = wps_src_get_subscription_meta( $id, WPS_SRC_SNAPSHOT_META );
			$override = wps_src_get_subscription_meta( $id, WPS_SRC_MODE_META );

			$summary = '-';
			if ( ! empty( $snap ) && is_array( $snap ) ) {
				$parts = array();
				foreach ( $snap as $c ) {
					$part = $c['code'] . ' (' . $c['type'] . ' ' . $c['amount'];
					if ( isset( $c['discount'] ) ) {
						$part .= ' ' . "\u{2192}" . ' ' . $c['discount'];
					}
					$parts[] = $part . ')';
				}
				$summary = implode( ', ', $parts );
			}

			$mode_label = ( 'lock' === $override || 'live' === $override ) ? $override : __( 'inherit', 'wps-subscriptions-extra' );

			$edit_url = wps_src_order_edit_url( $id );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . (int) $id . '</a></td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( $summary ) . '</td>';
			echo '<td>' . esc_html( $mode_label ) . '</td>';
			echo '<td><form method="post" style="margin:0;">';
			wp_nonce_field( 'wps_src_row', 'wps_src_row_nonce' );
			echo '<input type="hidden" name="wps_src_row_id" value="' . (int) $id . '" />';
			echo '<select name="wps_src_row_mode">';
			echo '<option value=""' . selected( $override, '', false ) . '>' . esc_html__( 'Inherit', 'wps-subscriptions-extra' ) . '</option>';
			echo '<option value="lock"' . selected( $override, 'lock', false ) . '>' . esc_html__( 'Price-lock', 'wps-subscriptions-extra' ) . '</option>';
			echo '<option value="live"' . selected( $override, 'live', false ) . '>' . esc_html__( 'Live', 'wps-subscriptions-extra' ) . '</option>';
			echo '</select> ';
			echo '<button type="submit" name="wps_src_row_action" value="setmode" class="button button-small">' . esc_html__( 'Set mode', 'wps-subscriptions-extra' ) . '</button> ';
			echo '<button type="submit" name="wps_src_row_action" value="resnapshot" class="button button-small" onclick="return confirm( \'' . esc_js( __( 'Re-snapshot this subscription from its original order? This overwrites the current snapshot.', 'wps-subscriptions-extra' ) ) . '\' );">' . esc_html__( 'Re-snapshot', 'wps-subscriptions-extra' ) . '</button> ';
			echo '<button type="submit" name="wps_src_row_action" value="clear" class="button button-small" onclick="return confirm( \'' . esc_js( __( 'Remove this snapshot? Renewals will bill the full price until re-snapshotted.', 'wps-subscriptions-extra' ) ) . '\' );">' . esc_html__( 'Clear', 'wps-subscriptions-extra' ) . '</button>';
			echo '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$base_url = admin_url( 'admin.php?page=subscriptions_for_woocommerce_extra' );
		echo '<p>';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'wps_src_paged', $paged - 1, $base_url ) ) . '">' . esc_html__( 'Previous', 'wps-subscriptions-extra' ) . '</a> ';
		}
		if ( $has_more ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'wps_src_paged', $paged + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'wps-subscriptions-extra' ) . '</a>';
		}
		echo '</p>';
	}
}

/**
 * Render the settings page and handle its forms.
 *
 * Every form is protected by a capability check and its own nonce. Backfill and
 * the renewal fix run synchronously; very large stores should use WP-CLI.
 */
if ( ! function_exists( 'wps_src_render_admin_page' ) ) {
	function wps_src_render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wps-subscriptions-extra' ) );
		}

		$notices         = array();
		$backfill_result = null;
		$fix_result      = null;
		$preview         = null;
		$preview_id      = 0;

		// Save discount mode.
		if ( isset( $_POST['wps_src_savemode'] ) ) {
			check_admin_referer( 'wps_src_savemode', 'wps_src_savemode_nonce' );
			$mode = ( isset( $_POST['wps_src_mode'] ) && 'live' === sanitize_text_field( wp_unslash( $_POST['wps_src_mode'] ) ) ) ? 'live' : 'lock';
			update_option( WPS_SRC_MODE_OPTION, $mode );
			$notices[] = array( 'success', __( 'Discount mode saved.', 'wps-subscriptions-extra' ) );
		}

		// Save menu-cleanup preference.
		if ( isset( $_POST['wps_src_savemenu'] ) ) {
			check_admin_referer( 'wps_src_savemenu', 'wps_src_savemenu_nonce' );
			update_option( WPS_SRC_HIDE_WPSWINGS_MENU_OPTION, isset( $_POST['wps_src_hide_wpswings_menu'] ) ? 'yes' : 'no' );
			$notices[] = array( 'success', __( 'Menu preference saved. Reload wp-admin to see the change.', 'wps-subscriptions-extra' ) );
		}

		// Backfill.
		if ( isset( $_POST['wps_src_backfill'] ) ) {
			check_admin_referer( 'wps_src_backfill', 'wps_src_backfill_nonce' );
			$scope = array(
				'active_only' => isset( $_POST['wps_src_active_only'] ),
				'coupon'      => isset( $_POST['wps_src_coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['wps_src_coupon'] ) ) : '',
				'date_from'   => isset( $_POST['wps_src_date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['wps_src_date_from'] ) ) : '',
				'date_to'     => isset( $_POST['wps_src_date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['wps_src_date_to'] ) ) : '',
				'ids'         => array(),
			);
			if ( isset( $_POST['wps_src_ids'] ) && '' !== trim( (string) wp_unslash( $_POST['wps_src_ids'] ) ) ) {
				$scope['ids'] = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', sanitize_text_field( wp_unslash( $_POST['wps_src_ids'] ) ) ) ) );
			}
			$backfill_result = wps_src_run_backfill( isset( $_POST['wps_src_dry_run'] ), $scope );
			update_option( WPS_SRC_LASTRUN_OPTION, array( 'type' => 'backfill', 'time' => time(), 'dry' => $backfill_result['dry'], 'updated' => $backfill_result['updated'], 'skipped' => $backfill_result['skipped'] ), false );
			if ( ! $backfill_result['dry'] ) {
				wps_src_bust_stats();
			}
		}

		// Fix pending renewals.
		if ( isset( $_POST['wps_src_fixrenewals'] ) ) {
			check_admin_referer( 'wps_src_fixrenewals', 'wps_src_fixrenewals_nonce' );
			$fix_result = wps_src_fix_pending_renewals( isset( $_POST['wps_src_fix_dry_run'] ) );
			update_option( WPS_SRC_LASTRUN_OPTION, array( 'type' => 'fix-renewals', 'time' => time(), 'dry' => $fix_result['dry'], 'updated' => $fix_result['updated'], 'skipped' => $fix_result['skipped'] ), false );
		}

		// Preview next renewal.
		if ( isset( $_POST['wps_src_preview'] ) ) {
			check_admin_referer( 'wps_src_preview', 'wps_src_preview_nonce' );
			$preview_id = isset( $_POST['wps_src_preview_id'] ) ? absint( $_POST['wps_src_preview_id'] ) : 0;
			if ( $preview_id ) {
				$preview = wps_src_preview_amounts( $preview_id );
			}
		}

		// Per-row actions.
		if ( isset( $_POST['wps_src_row_action'] ) ) {
			check_admin_referer( 'wps_src_row', 'wps_src_row_nonce' );
			$sub_id = isset( $_POST['wps_src_row_id'] ) ? absint( $_POST['wps_src_row_id'] ) : 0;
			$action = sanitize_text_field( wp_unslash( $_POST['wps_src_row_action'] ) );
			if ( $sub_id ) {
				if ( 'resnapshot' === $action ) {
					$parent = wps_src_get_subscription_meta( $sub_id, WPS_SRC_PARENT_META );
					$snap   = ( $parent && 'manual' !== $parent ) ? wps_src_build_snapshot( $parent, $sub_id ) : array();
					if ( ! empty( $snap ) ) {
						wps_src_update_subscription_meta( $sub_id, WPS_SRC_SNAPSHOT_META, $snap );
						wps_src_bust_stats();
						/* translators: %d: subscription ID */
						$notices[] = array( 'success', sprintf( __( 'Re-snapshotted subscription #%d.', 'wps-subscriptions-extra' ), $sub_id ) );
					} else {
						/* translators: %d: subscription ID */
						$notices[] = array( 'warning', sprintf( __( 'No eligible coupon found for subscription #%d.', 'wps-subscriptions-extra' ), $sub_id ) );
					}
				} elseif ( 'clear' === $action ) {
					wps_src_delete_subscription_meta( $sub_id, WPS_SRC_SNAPSHOT_META );
					wps_src_bust_stats();
					/* translators: %d: subscription ID */
					$notices[] = array( 'success', sprintf( __( 'Cleared snapshot for subscription #%d.', 'wps-subscriptions-extra' ), $sub_id ) );
				} elseif ( 'setmode' === $action ) {
					$row_mode = isset( $_POST['wps_src_row_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['wps_src_row_mode'] ) ) : '';
					if ( 'lock' === $row_mode || 'live' === $row_mode ) {
						wps_src_update_subscription_meta( $sub_id, WPS_SRC_MODE_META, $row_mode );
					} else {
						wps_src_delete_subscription_meta( $sub_id, WPS_SRC_MODE_META );
					}
					/* translators: %d: subscription ID */
					$notices[] = array( 'success', sprintf( __( 'Updated mode for subscription #%d.', 'wps-subscriptions-extra' ), $sub_id ) );
				}
			}
		}

		// Manual retry of a failed renewal charge.
		if ( isset( $_POST['wps_src_retry'] ) ) {
			check_admin_referer( 'wps_src_retry', 'wps_src_retry_nonce' );
			$retry_id = isset( $_POST['wps_src_retry_id'] ) ? absint( $_POST['wps_src_retry_id'] ) : 0;
			if ( $retry_id ) {
				$retry = wps_src_retry_renewal( $retry_id );
				if ( is_wp_error( $retry ) ) {
					/* translators: 1: order ID, 2: reason */
					$notices[] = array( 'warning', sprintf( __( 'Renewal #%1$d not retried: %2$s', 'wps-subscriptions-extra' ), $retry_id, $retry->get_error_message() ) );
				} elseif ( ! empty( $retry['charged'] ) ) {
					/* translators: %d: order ID */
					$notices[] = array( 'success', sprintf( __( 'Renewal #%d charged successfully.', 'wps-subscriptions-extra' ), $retry_id ) );
				} else {
					/* translators: 1: order ID, 2: resulting status */
					$notices[] = array( 'warning', sprintf( __( 'Renewal #%1$d retry ran but did not succeed (status: %2$s). No duplicate charge was made; the card was declined or the gateway rejected it.', 'wps-subscriptions-extra' ), $retry_id, esc_html( $retry['status'] ) ) );
				}
			}
		}

		// Confirmation after the live-key deactivation redirect.
		if ( isset( $_GET['wps_src_env_done'] ) ) {
			$backup = isset( $_GET['wps_src_env_backup'] ) ? sanitize_text_field( wp_unslash( $_GET['wps_src_env_backup'] ) ) : '';
			/* translators: %s: backup option name */
			$notices[] = array( 'success', sprintf( __( 'Live Stripe keys blanked and test mode forced on. Original settings backed up to option "%s"; restore from there if this was the wrong site.', 'wps-subscriptions-extra' ), $backup ) );
		}

		$stats        = wps_src_get_stats();
		$current_mode = ( 'live' === get_option( WPS_SRC_MODE_OPTION, 'lock' ) ) ? 'live' : 'lock';
		$last_run     = get_option( WPS_SRC_LASTRUN_OPTION, array() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Subscriptions for WooCommerce Extra', 'wps-subscriptions-extra' ) . '</h1>';

		// Shared header: name + links, then the one-line description.
		echo '<p class="description"><strong>' . esc_html__( 'Subscriptions for WooCommerce Extra', 'wps-subscriptions-extra' ) . '</strong>: ';
		echo '<a href="https://github.com/christefano/wps-subscriptions-extra" target="_blank" rel="noopener">' . esc_html__( 'GitHub', 'wps-subscriptions-extra' ) . '</a>';
		echo '&nbsp;|&nbsp;';
		echo '<a href="' . esc_url( plugins_url( 'README.md', __FILE__ ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'README', 'wps-subscriptions-extra' ) . '</a>';
		echo '&nbsp;|&nbsp;';
		echo '<a href="https://macchess.org/donate" target="_blank" rel="noopener">' . esc_html__( 'Donate', 'wps-subscriptions-extra' ) . '</a>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Extra tools and views for Subscriptions for WooCommerce (WP Swings), including support for coupon-based discounts on recurring renewals.', 'wps-subscriptions-extra' ) . '</p>';

		foreach ( $notices as $n ) {
			echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . esc_html( $n[1] ) . '</p></div>';
		}

		// Overview dashboard.
		echo '<h2>' . esc_html__( 'Overview', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<ul style="margin-left:1.5em;list-style:disc;">';
		/* translators: %d: count */
		echo '<li>' . sprintf( esc_html__( 'Total subscriptions: %d', 'wps-subscriptions-extra' ), (int) $stats['total'] ) . '</li>';
		/* translators: %d: count */
		echo '<li>' . sprintf( esc_html__( 'With snapshot (protected): %d', 'wps-subscriptions-extra' ), (int) $stats['with_snapshot'] ) . '</li>';
		/* translators: %d: count */
		echo '<li>' . sprintf( esc_html__( 'Eligible but missing (need backfill): %d', 'wps-subscriptions-extra' ), (int) $stats['eligible_missing'] ) . '</li>';
		/* translators: %d: count */
		echo '<li>' . sprintf( esc_html__( 'Not applicable (no coupon or manual): %d', 'wps-subscriptions-extra' ), (int) $stats['ineligible'] ) . '</li>';
		echo '</ul>';

		if ( ! empty( $last_run ) ) {
			echo '<p class="description">' . sprintf(
				/* translators: 1: run type, 2: updated, 3: skipped, 4: date */
				esc_html__( 'Last run: %1$s, %2$d updated, %3$d skipped on %4$s.', 'wps-subscriptions-extra' ),
				esc_html( $last_run['type'] ) . ( ! empty( $last_run['dry'] ) ? ' (dry run)' : '' ),
				(int) $last_run['updated'],
				(int) $last_run['skipped'],
				esc_html( wp_date( 'Y-m-d H:i', (int) $last_run['time'] ) )
			) . '</p>';
		}

		// Price-lock reassurance banner.
		if ( ! empty( $stats['expired'] ) ) {
			$codes = array_unique( wp_list_pluck( $stats['expired'], 'code' ) );
			echo '<div class="notice notice-info"><p>' . sprintf(
				/* translators: 1: subscription count, 2: coupon codes */
				esc_html__( '%1$d subscription(s) keep their discount even though these coupons no longer exist: %2$s. Price-locked by this plugin.', 'wps-subscriptions-extra' ),
				count( $stats['expired'] ),
				esc_html( implode( ', ', $codes ) )
			) . '</p></div>';
		}

		// Environment check.
		echo '<h2>' . esc_html__( 'Environment check', 'wps-subscriptions-extra' ) . '</h2>';
		$nonprod = wps_src_is_nonproduction_env();
		$live    = wps_src_active_live_stripe();
		if ( $nonprod && false !== $live ) {
			$msg = $live['armed']
				? esc_html__( 'This install does not look like production and holds a live Stripe key with test mode OFF. Renewals here can charge real cards.', 'wps-subscriptions-extra' )
				: esc_html__( 'This install does not look like production and holds a live Stripe key. Test mode is on for now, but one toggle would arm it.', 'wps-subscriptions-extra' );
			echo '<div class="notice ' . ( $live['armed'] ? 'notice-error' : 'notice-warning' ) . ' inline"><p>' . $msg . '</p>';
			echo '<p><a href="' . esc_url( wps_src_deactivate_live_keys_url() ) . '" class="button button-primary" onclick="return confirm( \'' . esc_js( __( 'Back up and blank the live Stripe keys on this site, and force test mode on?', 'wps-subscriptions-extra' ) ) . '\' );">' . esc_html__( 'Deactivate live keys', 'wps-subscriptions-extra' ) . '</a></p></div>';
		} elseif ( $nonprod ) {
			echo '<p>' . esc_html__( 'Non-production install, no live Stripe key active. Safe.', 'wps-subscriptions-extra' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'This install looks like production. No environment warning.', 'wps-subscriptions-extra' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'A live Stripe key on a staging or local clone is what lets it charge real customer cards behind production\'s back. Deactivating is reversible: the full gateway settings are backed up to a timestamped option first.', 'wps-subscriptions-extra' ) . '</p>';

		// Menu cleanup.
		echo '<h2>' . esc_html__( 'Menu cleanup', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'wps_src_savemenu', 'wps_src_savemenu_nonce' );
		$hide_wpswings_menu = ( 'yes' === get_option( WPS_SRC_HIDE_WPSWINGS_MENU_OPTION ) );
		echo '<p><label><input type="checkbox" name="wps_src_hide_wpswings_menu" value="1" ' . checked( $hide_wpswings_menu, true, false ) . ' /> ' . esc_html__( 'Hide the "WP Swings" top-level admin menu item', 'wps-subscriptions-extra' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Subscriptions for WooCommerce already registers its settings under WooCommerce -> Wps Subscriptions. The separate top-level "WP Swings" menu links to the same plugin, so it is a duplicate entry point. Hiding it only removes the menu item; nothing about the plugin itself is disabled.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><button type="submit" name="wps_src_savemenu" value="1" class="button">' . esc_html__( 'Save', 'wps-subscriptions-extra' ) . '</button></p>';
		echo '</form>';

		// Discount mode.
		echo '<h2>' . esc_html__( 'Discount mode', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'wps_src_savemode', 'wps_src_savemode_nonce' );
		echo '<select name="wps_src_mode">';
		echo '<option value="lock"' . selected( $current_mode, 'lock', false ) . '>' . esc_html__( 'Price-lock (use the original discount, ignore later coupon changes)', 'wps-subscriptions-extra' ) . '</option>';
		echo '<option value="live"' . selected( $current_mode, 'live', false ) . '>' . esc_html__( 'Honor live coupon (re-validate each renewal; respects expiry and limits)', 'wps-subscriptions-extra' ) . '</option>';
		echo '</select> ';
		echo '<button type="submit" name="wps_src_savemode" value="1" class="button">' . esc_html__( 'Save mode', 'wps-subscriptions-extra' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Store-wide default. Individual subscriptions can override this in the table below.', 'wps-subscriptions-extra' ) . '</p>';
		echo '</form>';

		// Backfill.
		$dry_checked = ! isset( $_POST['wps_src_backfill'] ) || isset( $_POST['wps_src_dry_run'] );
		echo '<h2>' . esc_html__( 'Fix Pre-existing Renewals', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<p>' . esc_html__( 'Record the original coupon on subscriptions created before this plugin was active.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'wps_src_backfill', 'wps_src_backfill_nonce' );
		echo '<p><label><input type="checkbox" name="wps_src_dry_run" value="1" ' . checked( $dry_checked, true, false ) . ' /> ' . esc_html__( 'Dry run (preview only)', 'wps-subscriptions-extra' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'When checked, lists what would change without saving anything.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><label><input type="checkbox" name="wps_src_active_only" value="1" /> ' . esc_html__( 'Active subscriptions only', 'wps-subscriptions-extra' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Only consider subscriptions whose status is active.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Coupon code', 'wps-subscriptions-extra' ) . ' <input type="text" name="wps_src_coupon" value="" /></label></p>';
		echo '<p class="description">' . esc_html__( 'Only subscriptions whose original order used this coupon code. Leave blank for all.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Created from', 'wps-subscriptions-extra' ) . ' <input type="date" name="wps_src_date_from" /></label> ';
		echo '<label>' . esc_html__( 'to', 'wps-subscriptions-extra' ) . ' <input type="date" name="wps_src_date_to" /></label></p>';
		echo '<p class="description">' . esc_html__( 'Only subscriptions created within this date range. Leave blank for all.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Subscription IDs (comma separated)', 'wps-subscriptions-extra' ) . ' <input type="text" name="wps_src_ids" value="" /></label></p>';
		echo '<p class="description">' . esc_html__( 'Only these subscription IDs. Leave blank for all.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><button type="submit" name="wps_src_backfill" value="1" class="button button-primary">' . esc_html__( 'Fix Pre-existing Renewals', 'wps-subscriptions-extra' ) . '</button></p>';
		echo '</form>';
		wps_src_render_result( $backfill_result );

		// Fix pending renewals.
		echo '<h2>' . esc_html__( 'Fix pending renewals', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<p>' . esc_html__( 'Apply the discount to unpaid renewal orders generated at full price before a snapshot existed.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'wps_src_fixrenewals', 'wps_src_fixrenewals_nonce' );
		$fix_dry_checked = ! isset( $_POST['wps_src_fixrenewals'] ) || isset( $_POST['wps_src_fix_dry_run'] );
		echo '<p><label><input type="checkbox" name="wps_src_fix_dry_run" value="1" ' . checked( $fix_dry_checked, true, false ) . ' /> ' . esc_html__( 'Dry run (preview only)', 'wps-subscriptions-extra' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'When checked, lists which renewal orders would be discounted without changing them.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p><button type="submit" name="wps_src_fixrenewals" value="1" class="button button-primary">' . esc_html__( 'Fix Pending Renewals', 'wps-subscriptions-extra' ) . '</button></p>';
		echo '</form>';
		wps_src_render_result( $fix_result );

		// Retry failed renewal charges.
		echo '<h2>' . esc_html__( 'Retry failed renewal charges', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<p>' . esc_html__( 'Manually re-charge a Stripe renewal that failed. A retry runs only when the order is provably unpaid (unpaid status, no transaction id, positive total) and holds an atomic in-flight lock, so it cannot double-charge one that already went through.', 'wps-subscriptions-extra' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'This list includes both renewals that were actually attempted and declined by the gateway, and renewals still sitting unpaid before any charge attempt was made (their status is "pending" either way; WooCommerce does not have a distinct status for "not yet tried"). The Payment attempt column tells the two apart.', 'wps-subscriptions-extra' ) . '</p>';
		$failed_ids = wps_src_failed_renewal_ids( 25 );
		if ( empty( $failed_ids ) ) {
			echo '<p>' . esc_html__( 'No unpaid Stripe renewal orders found.', 'wps-subscriptions-extra' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Order', 'wps-subscriptions-extra' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'wps-subscriptions-extra' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'wps-subscriptions-extra' ) . '</th>';
			echo '<th>' . esc_html__( 'Payment attempt', 'wps-subscriptions-extra' ) . '</th>';
			echo '<th>' . esc_html__( 'Total', 'wps-subscriptions-extra' ) . '</th>';
			echo '<th>' . esc_html__( 'Can be retried', 'wps-subscriptions-extra' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'wps-subscriptions-extra' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $failed_ids as $rid ) {
				$rorder = wc_get_order( $rid );
				if ( ! $rorder ) {
					continue;
				}
				$can      = wps_src_can_retry_renewal( $rorder );
				$date     = $rorder->get_date_created() ? wp_date( 'Y-m-d H:i', $rorder->get_date_created()->getTimestamp() ) : '-';
				$edit_url = wps_src_order_edit_url( $rid );
				$attempt  = wps_src_renewal_attempt_status( $rorder );
				echo '<tr>';
				echo '<td><a href="' . esc_url( $edit_url ) . '">#' . (int) $rid . '</a></td>';
				echo '<td>' . esc_html( $date ) . '</td>';
				echo '<td>' . esc_html( $rorder->get_status() ) . '</td>';
				echo '<td>' . ( 'declined' === $attempt ? esc_html__( 'Declined by gateway', 'wps-subscriptions-extra' ) : esc_html__( 'Never charged yet', 'wps-subscriptions-extra' ) ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( $rorder->get_total() ) ) . '</td>';
				if ( is_wp_error( $can ) ) {
					echo '<td>' . esc_html__( 'No', 'wps-subscriptions-extra' ) . '</td>';
					echo '<td><span class="description">' . esc_html( $can->get_error_message() ) . '</span></td>';
				} else {
					echo '<td>' . esc_html__( 'Yes', 'wps-subscriptions-extra' ) . '</td>';
					echo '<td><form method="post" style="margin:0;">';
					wp_nonce_field( 'wps_src_retry', 'wps_src_retry_nonce' );
					echo '<input type="hidden" name="wps_src_retry_id" value="' . (int) $rid . '" />';
					echo '<button type="submit" name="wps_src_retry" value="1" class="button button-small" onclick="return confirm( \'' . esc_js( sprintf( /* translators: %d: order ID */ __( 'Retry the Stripe charge for renewal #%d now?', 'wps-subscriptions-extra' ), $rid ) ) . '\' );">' . esc_html__( 'Retry this renewal', 'wps-subscriptions-extra' ) . '</button>';
					echo '</form></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// Preview next renewal.
		echo '<h2>' . esc_html__( 'Preview next renewal', 'wps-subscriptions-extra' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'wps_src_preview', 'wps_src_preview_nonce' );
		echo '<p><label>' . esc_html__( 'Subscription ID', 'wps-subscriptions-extra' ) . ' <input type="number" name="wps_src_preview_id" value="' . esc_attr( $preview_id ? $preview_id : '' ) . '" /></label> ';
		echo '<button type="submit" name="wps_src_preview" value="1" class="button">' . esc_html__( 'Preview', 'wps-subscriptions-extra' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Enter a subscription ID to see its next renewal date and price, before and after the discount. No order is created.', 'wps-subscriptions-extra' ) . '</p>';
		echo '</form>';
		if ( null !== $preview ) {
			if ( $preview['has_snapshot'] ) {
					$next_label = ! empty( $preview['next_date'] ) ? wp_date( get_option( 'date_format' ), $preview['next_date'] ) : __( 'not scheduled', 'wps-subscriptions-extra' );
					echo '<p><strong>' . sprintf(
						/* translators: 1: full price, 2: discounted price, 3: next renewal date */
						esc_html__( 'Next renewal on %3$s: %1$s before, %2$s after discount.', 'wps-subscriptions-extra' ),
						wp_kses_post( wc_price( $preview['full'] ) ),
						wp_kses_post( wc_price( $preview['discounted'] ) ),
						esc_html( $next_label )
					) . '</strong></p>';
				} else {
				echo '<p>' . esc_html__( 'That subscription has no snapshot; its renewal would bill the full price.', 'wps-subscriptions-extra' ) . '</p>';
			}
		}

		// Subscriptions table.
		wps_src_render_subscriptions_table();

		echo '<h3>' . esc_html__( 'Action reference', 'wps-subscriptions-extra' ) . '</h3>';
		echo '<ul style="margin-left:1.5em;list-style:disc;">';
		echo '<li>' . esc_html__( 'Inherit: use the store-wide discount mode for this subscription.', 'wps-subscriptions-extra' ) . '</li>';
		echo '<li>' . esc_html__( 'Price-lock: always apply the original snapshot discount, ignoring later coupon changes.', 'wps-subscriptions-extra' ) . '</li>';
		echo '<li>' . esc_html__( 'Live: re-validate the coupon on each renewal, respecting its current expiry and limits.', 'wps-subscriptions-extra' ) . '</li>';
		echo '<li>' . esc_html__( 'Set mode: save the selected discount mode for this subscription.', 'wps-subscriptions-extra' ) . '</li>';
		echo '<li>' . esc_html__( 'Re-snapshot: rebuild the snapshot from the original order, overwriting the current one.', 'wps-subscriptions-extra' ) . '</li>';
		echo '<li>' . esc_html__( 'Clear: remove the snapshot; renewals then bill the full price until re-snapshotted.', 'wps-subscriptions-extra' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Operations run in this request. On very large stores, use the WP-CLI command "wp wps-extra fix-preexisting".', 'wps-subscriptions-extra' ) . '</p>';
		echo '</div>';
	}
}

/**
 * Plugins-page action links: prepend Settings, append Donate.
 * Order: Settings | Deactivate | Donate.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
if ( ! function_exists( 'wps_src_plugin_action_links' ) ) {
	function wps_src_plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=subscriptions_for_woocommerce_extra' ) ) . '">' . esc_html__( 'Settings', 'wps-subscriptions-extra' ) . '</a>';
		$donate   = '<a href="https://macchess.org/donate" target="_blank" rel="noopener">' . esc_html__( 'Donate', 'wps-subscriptions-extra' ) . '</a>';
		array_unshift( $links, $settings );
		$links[] = $donate;
		return $links;
	}
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wps_src_plugin_action_links' );

/**
 * Plugins-page row meta: replace WordPress's default "Visit plugin site" link
 * (the Plugin URI) with a "View details" link to the project repository.
 *
 * @param string[] $links       Existing row-meta links.
 * @param string   $plugin_file Plugin file this row is for.
 * @return string[]
 */
if ( ! function_exists( 'wps_src_plugin_row_meta' ) ) {
	function wps_src_plugin_row_meta( $links, $plugin_file ) {
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $links;
		}
		foreach ( $links as &$link ) {
			if ( false !== strpos( $link, 'github.com/christefano/wps-subscriptions-extra' ) ) {
				$link = '<a href="https://github.com/christefano/wps-subscriptions-extra" target="_blank" rel="noopener">' . esc_html__( 'View details', 'wps-subscriptions-extra' ) . '</a>';
			}
		}
		return $links;
	}
}
add_filter( 'plugin_row_meta', 'wps_src_plugin_row_meta', 10, 2 );

/**
 * Remove subscription snapshot meta on uninstall, covering both CPT-stored and
 * HPOS-stored subscriptions. The renewal-order flag is left in place; it is
 * harmless and removing it would mean walking every order.
 */
if ( ! function_exists( 'wps_src_uninstall' ) ) {
	function wps_src_uninstall() {
		// Plugin options and cached stats.
		delete_option( WPS_SRC_MODE_OPTION );
		delete_option( WPS_SRC_LASTRUN_OPTION );
		delete_option( WPS_SRC_HIDE_WPSWINGS_MENU_OPTION );
		delete_transient( WPS_SRC_STATS_TRANSIENT );

		// Per-period duplicate-renewal claim rows and manual-retry in-flight locks.
		// Stripe settings backups (wps_src_stripe_settings_backup_*) are left in
		// place on purpose: they may hold the only copy of blanked live keys.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wps_src_renclaim\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wps_src_retry\\_%'" );

		// Legacy CPT-stored subscriptions.
		delete_post_meta_by_key( WPS_SRC_SNAPSHOT_META );
		delete_post_meta_by_key( WPS_SRC_MODE_META );

		// HPOS order-type subscriptions: meta lives in order storage. Walked in
		// batches to keep memory bounded on large stores.
		wps_src_walk_subscriptions(
			function ( $id ) {
				$subscription = wc_get_order( $id );
				if ( ! $subscription ) {
					return;
				}
				$changed = false;
				foreach ( array( WPS_SRC_SNAPSHOT_META, WPS_SRC_MODE_META ) as $key ) {
					if ( $subscription->meta_exists( $key ) ) {
						$subscription->delete_meta_data( $key );
						$changed = true;
					}
				}
				if ( $changed ) {
					$subscription->save();
				}
			}
		);
	}
}
register_uninstall_hook( __FILE__, 'wps_src_uninstall' );
