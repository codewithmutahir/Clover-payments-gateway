=== Clover Payment Gateway for WooCommerce ===

Contributors: elitesolutionusa
Tags: clover, payment gateway, woocommerce, credit card, checkout
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept credit card payments via Clover with full order sync, tax reporting, and inventory sync. By Elite Solution USA.

== Description ==

Clover Payment Gateway for WooCommerce connects your store to Clover so that:

* **Card payments** are processed through Clover (PCI-compliant via Clover iframe).
* **Orders** are created in Clover with real product names and line items.
* **COD / pickup orders** and other gateways (COD, BACS) sync to Clover POS automatically.
* **Tax** is recorded in Clover’s Tax Report when you set a Default Tax Rate ID (no double-counting).
* **Item Sales** reporting works when products are linked to Clover inventory (manual or via Clover Sync).
* **Inventory sync** matches WooCommerce products to Clover items by SKU/name and supports bulk export.
* **Duplicate prevention** uses DB-level locks so concurrent hooks do not create two Clover orders.

= Requirements =

* WooCommerce 5.0+
* Clover merchant account and API credentials

= Credits =

Developed by **Elite Solution USA** (https://elitesolutionusa.com).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WordPress admin.
2. Activate the plugin.
3. Go to WooCommerce → Settings → Payments and enable Clover Payments.
4. Enter your Clover Merchant ID, API Token, and eCommerce Public/Private keys.
5. Optionally set a Default Tax Rate ID (use “Browse Tax Rates” to select) so tax appears in Clover’s Tax Report.
6. Use WooCommerce → Clover Sync to link products to Clover inventory or export products to Clover.

== Frequently Asked Questions ==

= Where do I get Clover API credentials? =

In your Clover Dashboard: Developer Dashboard → API Tokens (v3 REST) and eCommerce API keys for the iframe and charges.

= COD orders show as Open in Clover. How do I mark them paid? =

Edit the order in WordPress, choose “Mark as paid in Clover” from the Order actions dropdown, and click Update.

= How do I add more items to an order already sent to Clover? =

Add the line items in the WooCommerce order, then use “Push new items to Clover” from Order actions. When ready, use “Mark as paid in Clover.”

== Changelog ==

= 1.0.2 =
* Fix Clover tax calculation divisor and order-level taxAmount for configured tax rates.
* Prevent double tax by omitting a separate WC Tax line when Default Tax Rate ID is set.
* Compact checkout card form; Clover SDK styling for iframe fields.
* Add non-card POS order sync (COD, BACS) with duplicate-prevention lock and manual re-sync action.
* Sync credentials fall back to official Clover Payments plugin when gateway settings are empty.
* Improved API error handling and idempotent order recovery.

= 1.0.1 =
* Fix XSS in checkout error display by escaping error messages.

= 1.0.0 =
* Initial release. Clover card payments, order sync, tax reporting, inventory sync, COD/pickup support.

== Upgrade Notice ==

= 1.0.2 =
Tax reporting, checkout UI, and POS sync reliability improvements. Recommended for all sites.

= 1.0.1 =
Security fix for checkout error display.

= 1.0.0 =
Initial release.
