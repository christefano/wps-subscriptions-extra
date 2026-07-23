# Changelog

## 2.0.1 - Double-charge protection.

- Duplicate renewals: before a charge, WPS Subscriptions Extra claims only one renewal per subscription per billing cycle.
- Action Scheduler compatibility: check for duplicate Action Scheduler actions and keep the earliest one.
- Uninstall also removes the per-period claim.

## 2.0 - First public release.

- Snapshots record the actual discount the customer received on the subscription's own product line (variation-aware, falling back to all lines when no line matches), and renewals apply that recorded amount flat with no coupon-type sniffing. Old-format snapshots without the recorded discount fall back to the previous type-based math.
- The renewal application and the admin "Preview next renewal" share one computation helper, so the preview and the actual renewal discount cannot drift.
- The re-application guard meta is set before `calculate_totals()`, so WooCommerce's internal save persists the guard together with the discounted items, closing the window where a crash between saves could leave a renewal discounted but unguarded and eligible to be discounted again.
- Renewals paid via PayPal Standard are skipped with an explanatory order note: PayPal Standard charges the full billing-agreement amount before the renewal order exists, so discounting the order would not match the actual charge. The check runs after the snapshot load, so only renewals that would otherwise have been discounted get the note.
- In live mode, coupons that fail re-validation are logged as order notes including WooCommerce's error message, instead of failing silently.
- My Account shows customers their locked renewal price: the subscriptions list and the single-subscription view display the discounted total with the original price struck through. Shown only when a snapshot exists, the effective mode is price-lock, the subscription is not cancelled, and the payment method is not PayPal Standard — so the number shown is always the number that will be charged.
