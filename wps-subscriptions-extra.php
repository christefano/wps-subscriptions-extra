<?php
/**
 * Plugin Name: Subscriptions for WooCommerce Extra
 * Description: Extra tools and views for Subscriptions for WooCommerce (WP Swings), including support for coupon-based discounts on recurring renewals.
 * Version: 2.0
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
 * Version policy: this plugin's version mirrors the Subscriptions for WooCommerce
 * release it is built and verified against. It is known to work ONLY with
 * Subscriptions for WooCommerce 2.0, so this version is pinned at 2.0 and must not
 * be advanced beyond it. Tested with WooCommerce 10.9 and HPOS enabled.
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
 * @param int $order_id Order ID to read coupons from.
 * @return array List of { code, type, amount }; empty when none apply.
 */
if ( ! function_exists( 'wps_src_build_snapshot' ) ) {
	function wps_src_build_snapshot( $order_id ) {
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

		$snapshot = array();
		foreach ( $codes as $code ) {
			$coupon = new WC_Coupon( $code );
			$type   = $coupon->get_discount_type();

			// Already persisted by the base plugin; do not re-apply.
			if ( wps_src_is_native_recurring_type( $type ) ) {
				continue;
			}

			$snapshot[] = array(
				'code'   => $code,
				'type'   => $type,
				'amount' => (float) $coupon->get_amount(),
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
		$snapshot = wps_src_build_snapshot( $order_id );
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

		$snapshot = wps_src_get_subscription_meta( $subscription_id, WPS_SRC_SNAPSHOT_META );
		if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
			return;
		}

		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
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
				if ( ! is_wp_error( $order->apply_coupon( $code ) ) ) {
					$applied_any = true;
				}
			}
		} else {
			// Price-lock: recompute the discount from the snapshot, independent
			// of the coupon's current state.
			foreach ( $snapshot as $cpn ) {
				$code   = isset( $cpn['code'] ) ? $cpn['code'] : '';
				$type   = isset( $cpn['type'] ) ? $cpn['type'] : '';
				$amount = isset( $cpn['amount'] ) ? (float) $cpn['amount'] : 0;

				if ( '' === $code || $amount <= 0 ) {
					continue;
				}

				$is_percent = ( false !== strpos( $type, 'percent' ) );
				$is_product = ( ! $is_percent && false !== strpos( $type, 'product' ) );

				// null marks per-line (percent/product); a number is the shared
				// flat budget spent across lines (fixed_cart applied once).
				$flat_remaining  = ( ! $is_percent && ! $is_product ) ? $amount : null;
				$coupon_discount = 0.0;

				foreach ( $items as $item ) {
					$base = (float) $item->get_total(); // running total; lets coupons stack
					if ( $base <= 0 ) {
						continue;
					}

					$discount = wps_src_coupon_line_discount( $type, $amount, $base, $item->get_quantity(), $flat_remaining );
					if ( $discount <= 0 ) {
						continue;
					}

					$item->set_total( $base - $discount );
					$item->save();
					$coupon_discount += $discount;

					if ( null !== $flat_remaining && $flat_remaining <= 0 ) {
						break;
					}
				}

				if ( $coupon_discount > 0 ) {
					$coupon_item = new WC_Order_Item_Coupon();
					$coupon_item->set_code( $code );
					$coupon_item->set_discount( $coupon_discount );
					$coupon_item->set_discount_tax( 0 );
					$order->add_item( $coupon_item );
					$applied_any = true;
				}
			}
		}

		if ( ! $applied_any ) {
			return;
		}

		$order->update_meta_data( WPS_SRC_APPLIED_META, 'yes' );
		// Live mode already recalculated via apply_coupon(); only lock mode needs
		// the manually discounted lines re-summed with taxes.
		if ( 'live' !== $mode ) {
			$order->calculate_totals( true );
		}
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

				$snapshot = wps_src_build_snapshot( $parent_order_id );
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
				$eligible = ( ! empty( $parent ) && 'manual' !== $parent && ! empty( wps_src_build_snapshot( $parent ) ) );
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
 * Compute a subscription's next-renewal price before and after the snapshot
 * discount, without creating an order. Single-line estimate.
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
			foreach ( $snapshot as $cpn ) {
				$type   = isset( $cpn['type'] ) ? $cpn['type'] : '';
				$amount = isset( $cpn['amount'] ) ? (float) $cpn['amount'] : 0;
				$is_p   = ( false !== strpos( $type, 'percent' ) );
				$is_pr  = ( ! $is_p && false !== strpos( $type, 'product' ) );
				$flat   = ( ! $is_p && ! $is_pr ) ? $amount : null;
				$running -= wps_src_coupon_line_discount( $type, $amount, $running, $qty, $flat );
			}
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
			__( 'Wps Subscriptions Extra', 'wps-subscriptions-extra' ),
			'manage_woocommerce',
			'subscriptions_for_woocommerce_extra',
			'wps_src_render_admin_page'
		);
	}
}
add_action( 'admin_menu', 'wps_src_register_admin_page', 99 );

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
					$parts[] = $c['code'] . ' (' . $c['type'] . ' ' . $c['amount'] . ')';
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
					$snap   = ( $parent && 'manual' !== $parent ) ? wps_src_build_snapshot( $parent ) : array();
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
		delete_transient( WPS_SRC_STATS_TRANSIENT );

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
