# KD-Bonus

Network-activated WordPress multisite starter plugin for a global WooCommerce reward program using **Kamagra Dollar ($KD)**.

## Included starter implementation

- Network Admin **KD Bonus** settings submenu under **Settings** with tabbed pages for:
  - General Settings
  - Membership Statuses
  - Email Settings
  - Points & Reward Settings
- Multisite-aware frontend dashboard page creation with `[kd_bonus_dashboard]`
- WooCommerce reward accrual based on eligible **product subtotal only**
- Membership progression based on **lifetime eligible spend**
- Checkout balance display plus **partial or full** $KD redemption
- Global transaction ledger table for reward history
- Currency conversion filter hook for multi-currency integrations:
  - `kd_bonus_currency_conversion_rate`
- Eligible subtotal customization hook:
  - `kd_bonus_eligible_line_total`
- Completion action hook:
  - `kd_bonus_order_rewards_processed`

## Notes

- The plugin is intended for **WordPress multisite** and should be **network activated**.
- If WooCommerce is unavailable, the plugin keeps admin and dashboard scaffolding available while reward processing remains inactive.
