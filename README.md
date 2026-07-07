# Subscriptions for WooCommerce Extra

Subscriptions for WooCommerce Extra (or just SFW Extra for short) is a helper plugin for WP Swings' Subscriptions for WooCommerce (WPS SFW) that makes recurring renewal charges honor the coupon discount applied to the subscription's original order.

With the free version of WPS SFW, subscription renewals are charged at full price regardless of coupon-based discounts used at the time of purchase. This is not what customers expect, and SFW Extra adds coupon-based discounts to their recurring renewals.

Enable this plugin and renewals carry the original discount. Disable it, and the free WPS SFW plugin's default full-price renewals resume.

## Features

- Records the coupon code, discount type, and amount on each subscription when it's first created.
- Re-applies that discount to every renewal order the WPS SFW plugin generates (PayPal Standard excepted — see How it works) and recalculates taxes (if any) on the discounted total.
- Adds a coupon line to each renewal order so the discount appears in the order and in WooCommerce reports.
- Snapshot-based (price-lock): an existing subscription keeps its original discount even if the coupon later expires, hits its usage limit, or is deleted.
- Shows customers their locked renewal price on My Account: the subscriptions list and single-subscription view display the discounted total with the original price struck through.
- Compatible with HPOS (High-Performance Order Storage).

## Requirements

SFW Extra depends on WooCommerce (WC) and WP Swings' Subscriptions for WooCommerce (WPS SFW) and is known to work only with WPS SFW 2.0. It relies on WPS SFW 2.0's internal hooks and HPOS-aware meta helpers, which may change in future versions of WPS SFW. Running it against a different version might not work.

Verified with Subscriptions for WooCommerce 2.0, WooCommerce 10.9.3, and with High-Performance Order Storage (HPOS) enabled.

## Versions

SFW Extra's version number mirrors the version of WPS SFW that it's known to work with. For example, v2.0 is known to work with WPS SFW v2.0 and may not have been tested with newer versions.

## Installation

1. Ensure **Subscriptions for WooCommerce** (free, not Pro) is installed and active.
2. Copy the `wps-subscriptions-extra` folder to `wp-content/plugins/`, or upload the zip via Plugins -> Add New -> Upload Plugin.
3. Activate **Subscriptions for WooCommerce Extra**.

## Configuration

SFW Extra actually works with no configuration. It snapshots new subscriptions and discounts their renewals while it's active.

There are plenty of tools, however. The settings page at **WooCommerce -> Wps Subscriptions Extra** supports a number of optional views and tools:

- **Overview** - counts of total, protected (snapshotted), eligible-but-missing, and not-applicable subscriptions, plus the last-run summary.
- **Discount mode** - store-wide default of *Price-lock* (use the original discount, ignore later coupon changes) or *Honor live coupon* (re-validate the coupon each renewal, respecting expiry and usage limits — see "Coupon usage limits in live mode" below). Each subscription can override the default in the table.
- **Fix Pre-existing Renewals** - snapshot subscriptions created before the plugin was active, with optional scope: active-only, a specific coupon code, a creation date range, or specific subscription IDs. Dry run is on by default.
- **Fix pending renewals** - apply the discount to unpaid renewal orders that were generated at full price before a snapshot existed.
- **Preview next renewal** - show a subscription's next-renewal date and price, before and after the discount, without creating an order.
- **Subscriptions table** - per row, re-snapshot from the parent order, clear the snapshot, or set the discount-mode override.

Requires the `manage_woocommerce` capability. These operations run in the request; very large stores should use WP-CLI.

## Fix Pre-existing Renewals

Subscriptions created before enabling this plugin have no snapshot. Record one from each subscription's parent order in either of two ways.

**Admin**
Go to **WooCommerce -> Wps Subscriptions Extra**, leave **Dry run** checked to preview, click **Fix Pre-existing Renewals**, then uncheck Dry run and run again to write. Requires the `manage_woocommerce` capability. Backfill runs in the request, so very large stores may prefer the WP-CLI command line.

**WP-CLI:**

```
wp wps-extra fix-preexisting --dry-run   # report only, writes nothing
wp wps-extra fix-preexisting             # write snapshots
```

Both paths share the same logic. Subscriptions that already have a snapshot, manual subscriptions, and those whose parent order is missing or carries no eligible coupon are skipped. Re-running only touches subscriptions still lacking a snapshot (idempotent). Subscriptions are walked in batches, so this and uninstall cleanup stay memory-bounded on large stores.

## How it works

- On `wps_sfw_after_created_subscription`, the original order's coupons are read and stored on the subscription as a snapshot (`code`, `type`, `amount`, `discount`).
- The `discount` key records the actual dollar amount the customer saved on the subscription's own product line (variation-aware) in the original order, apportioned across coupons by WooCommerce's own per-coupon discount split. In a mixed cart (subscription plus one-off products), only the subscription's line counts, so savings on other products never inflate the renewal discount. When no line matches the subscription's product (product changed or deleted, or meta absent), the sum falls back to all lines.
- On `wps_sfw_renewal_order_creation` (both the legacy and HPOS renewal paths), the recorded `discount` is spent flat across the renewal's lines — no coupon-type sniffing — a coupon line is added, and totals plus taxes are recalculated. Snapshots recorded before the `discount` key existed fall back to type-based math: percent coupons scale the line; product coupons multiply by quantity; cart (flat) coupons subtract their amount. Every discount is capped at the line total.
- Renewals paid via PayPal Standard are skipped, with an order note explaining why: PayPal Standard charges the full billing-agreement amount via IPN before the renewal order is even created, so discounting the order would not change what the customer was actually charged.
- In live mode, a coupon that fails re-validation (expired, deleted, usage-exhausted) is logged as an order note carrying WooCommerce's error message, so failed applications are visible on the renewal order.
- In live mode, every successfully re-validated renewal calls WooCommerce's `apply_coupon()`, which records a real usage against the coupon (same as a checkout use). Renewals spend from the same usage-limit budget as new signups.
- On My Account, the base plugin's recurring-total display (the `wps_sfw_sub_recurring_total_my_account_page` filter and `wps_sfw_display_susbcription_recerring_total_account_page` action, used by both the subscriptions list and the single-subscription view) is adjusted to show the locked renewal price with the original struck through. The discounted price is only shown when it is certain to be charged: a snapshot must exist, the effective mode must be price-lock (live mode depends on coupon validation at charge time), the subscription must not be cancelled, and the payment method must not be PayPal Standard. In every other case the display is left untouched.

## Limitations

- Only applies to subscriptions **created while this plugin is active**. Subscriptions created earlier have no snapshot and renew at full price until backfilled with `wp wps-extra fix-preexisting` (see Fix Pre-existing Renewals).
- Pro-native recurring coupon types (`recurring_product_discount`, `recurring_product_percent_discount`) are skipped, because WPS SFW already persists those into the recurring price; this plugin would otherwise double the discount.
- Variable-product subscriptions are not renewed by WPS SFW, so the discount never reaches them.
- `fixed_cart` coupons spend a single flat amount across the order's line items rather than per line, so a multi-line renewal is discounted by the coupon amount once, not once per line.
- Discount stacking order across multiple coupons follows snapshot order.

## Coupon usage limits in live mode

Price-lock mode never re-checks the coupon, so its usage limits don't affect renewals at all. **Live mode does**, and a coupon set up for a one-time checkout promo will misbehave if left as-is for recurring use:

- **Usage limit per user** - if set to 1 (a typical signup-promo setting), the customer already used the coupon at signup, so every renewal after that fails re-validation and bills full price. For a coupon meant to keep discounting a subscription in live mode, set this to unlimited (blank).
- **Usage limit (global, across all customers)** - each renewal that successfully re-validates also records a usage, spending from the same pool as new checkouts. A coupon meant to gate e.g. "first 50 new customers" will instead have its pool consumed by renewals of the first few subscribers over time, not just new signups, and will eventually stop working for both. Leave this unlimited (or generously high, understanding it now caps total renewal-charges, not just signups) for any coupon used in live mode.

Both limits are on the coupon itself (Marketing -> Coupons -> the coupon -> Usage limits tab), not on the subscription.

## Troubleshooting

- **Renewal still billed at full price**
Confirm the subscription was created after activation (check for the `_wps_src_recurring_coupons` snapshot meta), the original order actually carried a coupon, and the coupon was a normal type rather than a Pro recurring type.

- **Discount applied twice**
The re-entrancy flag (`_wps_src_applied`) prevents double application per renewal order. If you see this, confirm no other customization re-fires `wps_sfw_renewal_order_creation` for the same order.

- **Customer does not see the discounted price on My Account**
The struck-through display appears only when the discount is certain: the subscription has a snapshot, its effective discount mode is price-lock, it is not cancelled, and its payment method is not PayPal Standard. A subscription in live mode intentionally shows the full price, since its renewal amount depends on coupon validation at charge time.

## Data

- Subscription meta `_wps_src_recurring_coupons`: array of `{ code, type, amount, discount }` recorded at subscription creation. Stored on the subscription wherever the base plugin keeps it (post meta on legacy installs, HPOS order storage when High-Performance Order Storage is enabled).
- Subscription meta `_wps_src_discount_mode`: optional per-subscription discount-mode override (`lock` or `live`).
- Renewal order meta `_wps_src_applied`: flag marking a renewal as already discounted.
- Options `wps_src_discount_mode` (store-wide mode) and `wps_src_last_run` (last backfill/fix summary). Dashboard stats are cached in the `wps_src_stats` transient.

Uninstalling SFW Extra removes the `_wps_src_recurring_coupons` and `_wps_src_discount_mode` subscription meta (legacy post meta and HPOS order storage), both options, and the stats transient. The renewal-order flag is harmless and left in place.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later.
