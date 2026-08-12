# KD-Bonus

Network-activated WordPress multisite starter plugin for a global WooCommerce reward program using **Kamagra Dollar ($KD)**.

## Included starter implementation

- Network Admin **KD Bonus** top-level menu with a **Settings** submenu and tabbed pages for:
  - General Settings
  - Membership Statuses
  - Email Settings
  - Points & Reward Settings
- Configurable WooCommerce order status trigger for automatic reward awarding
- General Settings action to rebuild memberships from existing WooCommerce order spend using interactive resumable batches
- Orders using coupon discounts are excluded from earning new reward points
- Repeatable membership statuses with editable priority, spend threshold, and reward percentage
- Multisite-aware frontend dashboard page creation with `[kd_bonus_dashboard]`
- Admin user profile section for viewing KD rewards metadata, manual balance adjustments with notes, and membership status overrides
- WooCommerce reward accrual based on eligible **product subtotal only**
- Configurable reward expiry window that resets unused balances after the last reward deposit ages out
- Membership progression based on **lifetime eligible spend**
- Checkout balance display plus **partial or full** $KD redemption
- Global reward event log and transaction ledger with automatic pruning to the latest 5000 records
- Currency conversion filter hook for multi-currency integrations:
  - `kd_bonus_currency_conversion_rate`
- Eligible subtotal customization hook:
  - `kd_bonus_eligible_line_total`
- Completion action hook:
  - `kd_bonus_order_rewards_processed`

## Notes

- The plugin is intended for **WordPress multisite** and should be **network activated**.
- If WooCommerce is unavailable, the plugin keeps admin and dashboard scaffolding available while reward processing remains inactive.
