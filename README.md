# WooCommerce Tracking Bridge

Bridges bidirectional tracking sync between **WooCommerce PayPal Payments**, **WooCommerce Shipment Tracking**, and **WooCommerce Shipping**.

## The problem

All three plugins maintain their own tracking data stores, but do not fully sync with each other. Two gaps exist:

**Gap 1 — PayPal Package Tracking → Shipment Tracking**

When a store manager enters tracking via the PayPal Package Tracking metabox and saves it to PayPal's API, the data is never written back to Shipment Tracking. PayPal fires an action hook at this point (`woocommerce_paypal_payments_before_tracking_is_added`), but the data is embedded in a JSON-encoded HTTP request body rather than being passed as clean parameters — no practical integration point exists for Shipment Tracking to consume.

**Gap 2 — WooCommerce Shipping label purchase → PayPal**

When a shipping label is purchased in WooCommerce Shipping, tracking data is written directly to the `_wc_shipment_tracking_items` order meta, bypassing both of the entry points that PayPal Payments already hooks:

- `wp_ajax_wc_shipment_tracking_save_form` — manual admin UI save
- `woocommerce_rest_prepare_order_shipment_tracking` — REST API create

The label purchase path falls through both, so PayPal never learns about it.

## What this plugin does

**Direction 1 — PayPal → Shipment Tracking**

Hooks `woocommerce_paypal_payments_before_tracking_is_added` to capture the tracking data before it is sent to PayPal's API, then hooks `woocommerce_paypal_payments_after_tracking_is_added` (which only fires on HTTP 201) to write the entry to Shipment Tracking via `wc_st_add_tracking_number()`. The two-hook approach ensures nothing is written to Shipment Tracking unless the PayPal API call actually succeeded.

**Direction 2 — WooCommerce Shipping label → PayPal**

Hooks `added_order_meta` and `updated_order_meta` on the `_wc_shipment_tracking_items` key. Before acting, checks whether we are inside a context that PayPal already handles natively (the ST admin AJAX form save, or an ST REST API request). If so, skips — no double-sync. For all other contexts (primarily WC Shipping label purchases), invokes PayPal's existing `woocommerce_rest_prepare_order_shipment_tracking` filter with a synthetic request that satisfies PayPal's `create_item` check, triggering PayPal's own tested sync logic.

## Requirements

| Plugin | Minimum version |
|---|---|
| WooCommerce | 6.0 |
| WooCommerce PayPal Payments | Any current release |
| WooCommerce Shipment Tracking | Any current release |
| WooCommerce Shipping | Required only if you purchase labels via WooCommerce Shipping |

PHP 7.4 or higher is required.

The plugin declares HPOS (High-Performance Order Storage) compatibility and works with both the legacy CPT order storage and the `wp_wc_orders` table.

## Installation

1. Upload the `woocommerce-shipment-tracking-bridge` folder to `wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. No configuration is needed

## Behaviour when a plugin is not active

The bridge degrades gracefully when a required plugin is absent:

- **WooCommerce Shipment Tracking not active** — Direction 1 is skipped (the `wc_st_add_tracking_number()` function does not exist). Direction 2 is skipped (PayPal does not register its `woocommerce_rest_prepare_order_shipment_tracking` callback when Shipment Tracking is absent, so `has_filter()` returns false). A notice is written to the WooCommerce log (`source: wc-tracking-bridge`) to explain why the sync did not run.
- **WooCommerce PayPal Payments not active** — Neither direction's action hooks exist, so neither fires. No errors.
- **WooCommerce Shipping not active** — Direction 2's meta hook is still registered, but no WC Shipping label purchase will occur to trigger it. No impact.

## Known limitations

- **Tracking updates are not synced.** If a tracking number is changed after initial entry, the change is not propagated to the other plugin. Only new entries are covered.
- **Tracking deletion is not synced.** Removing tracking in one plugin does not remove it in the other.
- **Compound PayPal carrier codes.** PayPal uses codes such as `FEDEX`, `UPS`, and `DHL_API_TOSHIP`. When writing to Shipment Tracking, these are passed through `wc_st_add_tracking_number()`, which applies `sanitize_title()` to match against ST's provider list. Simple codes (`FEDEX` → `fedex`, `UPS` → `ups`) will match. Compound codes (`DHL_API_TOSHIP`, `YANWEN_CN`, etc.) will not match a known provider and will be stored as a custom provider — the Shipment Tracking link lookup will not work for these carriers.

## How the deduplication works

**Direction 1** uses ST's `_wc_shipment_tracking_items` order meta to check whether the tracking number is already present before writing. A static flag (`$writing_to_st`) prevents the resulting meta write from triggering Direction 2 and creating a sync loop.

**Direction 2** uses PayPal's `_ppcp_paypal_tracking_info_meta_name` order meta (keyed by tracking number) to check whether PayPal has already synced a given tracking number. Entries already present are skipped.
