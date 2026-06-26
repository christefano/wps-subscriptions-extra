# Subscriptions for WooCommerce Extra

Subscriptions for WooCommerce Extra (or just SFW Extra for short) is a helper plugin for WP Swings' Subscriptions for WooCommerce (WPS SFW) that makes recurring renewal charges honor the coupon discount applied to the subscription's original order.

With the free version of WPS SFW, subscription renewals are charged at full price regardless of coupon-based discounts used at the time of purchase. This is not what customers expect, and SFW Extra adds coupon-based discounts to their recurring renewals.

Enable this plugin and new subscriptions renew with their original discount. Disable it, and the free WPS SFW plugin's default full-price renewals resume.

SFW Extra was built for the McMinnville Chess Club website with recurring club memberships in mind, but it's been generalized to be used for any WordPress site using WPS SFW. If you find this plugin useful, consider [making a donation](https://macchess.org/donate) to the McMinnville Chess Club!

## Features

- Records the coupon code, discount type, and amount on each subscription when it's first created.
- Re-applies that discount to every renewal order the WPS SFW plugin generates and recalculates taxes (if any) on the discounted total.
- Adds a coupon line to each renewal order so the discount appears in the order and in WooCommerce reports.
- Snapshot-based (price-lock): an existing subscription keeps its original discount even if the coupon later expires, hits its usage limit, or is deleted.
- Compatible with HPOS (High-Performance Order Storage).

## Requirements

SFW Extra depends on WooCommerce (WC) and WP Swings' Subscriptions for WooCommerce (WPS SFW) and is known to work only with WPS SFW 2.0. It relies on WPS SFW 2.0's internal hooks and HPOS-aware meta helpers, which may change in future versions of WPS SFW. Running it against a different version might not work.

Verified with Subscriptions for WooCommerce 2.0, WooCommerce 10.9, and with High-Performance Order Storage (HPOS) enabled.

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
- **Discount mode** - store-wide default of *Price-lock* (use the original discount, ignore later coupon changes) or *Honor live coupon* (re-validate the coupon each renewal, respecting expiry and usage limits). Each subscription can override the default in the table.
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

- On `wps_sfw_after_created_subscription`, the original order's coupons are read and stored on the subscription as a snapshot (`code`, `type`, `amount`).
- On `wps_sfw_renewal_order_creation` (both the legacy and HPOS renewal paths), the discount is recomputed from the snapshot and subtracted from the renewal line total, a coupon line is added, and totals plus taxes are recalculated.
- Percent coupons scale the line; product coupons multiply by quantity; cart (flat) coupons subtract their amount. Every discount is capped at the line total.

## Limitations

- Only applies to subscriptions **created while this plugin is active**. Subscriptions created earlier have no snapshot and renew at full price until backfilled with `wp wps-extra fix-preexisting` (see Fix Pre-existing Renewals).
- Pro-native recurring coupon types (`recurring_product_discount`, `recurring_product_percent_discount`) are skipped, because WPS SFW already persists those into the recurring price; this plugin would otherwise double the discount.
- Variable-product subscriptions are not renewed by WPS SFW, so the discount never reaches them.
- `fixed_cart` coupons spend a single flat amount across the order's line items rather than per line, so a multi-line renewal is discounted by the coupon amount once, not once per line.
- Discount stacking order across multiple coupons follows snapshot order.

## Troubleshooting

- **Renewal still billed at full price**
Confirm the subscription was created after activation (check for the `_wps_src_recurring_coupons` snapshot meta), the original order actually carried a coupon, and the coupon was a normal type rather than a Pro recurring type.

- **Discount applied twice**
The re-entrancy flag (`_wps_src_applied`) prevents double application per renewal order. If you see this, confirm no other customization re-fires `wps_sfw_renewal_order_creation` for the same order.

## Data

- Subscription meta `_wps_src_recurring_coupons`: array of `{ code, type, amount }` recorded at subscription creation. Stored on the subscription wherever the base plugin keeps it (post meta on legacy installs, HPOS order storage when High-Performance Order Storage is enabled).
- Subscription meta `_wps_src_discount_mode`: optional per-subscription discount-mode override (`lock` or `live`).
- Renewal order meta `_wps_src_applied`: flag marking a renewal as already discounted.
- Options `wps_src_discount_mode` (store-wide mode) and `wps_src_last_run` (last backfill/fix summary). Dashboard stats are cached in the `wps_src_stats` transient.

Uninstalling SFW Extra removes the `_wps_src_recurring_coupons` and `_wps_src_discount_mode` subscription meta (legacy post meta and HPOS order storage), both options, and the stats transient. The renewal-order flag is harmless and left in place.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later.
