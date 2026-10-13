=== SocialScore for WooCommerce ===
Contributors: socialscore
Tags: woocommerce, fraud, customer data, risk, orders
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Check customers and orders with SocialScore: digital presence, email and phone quality, fraud signals and business-buyer detection inside WooCommerce.

== Description ==

SocialScore for WooCommerce connects your store to your SocialScore account. After you connect:

* A **SocialScore** page in the admin menu shows the store dashboard: overview, checked profiles, segments and settings.
* Every order screen and customer profile gets a **Check panel** with the result, and the orders list gets a risk column.
* New and updated orders and customers are sent to SocialScore in the background, and your existing customers and orders are imported in batches of up to 500.
* Orders get a note and a label such as "Review before shipping" or "Possible business buyer" from the check result.
* You choose when checks run automatically (new account, every account, new customer's order, every order, or manual only).

The plugin is fully functional on its own. Which checks your SocialScore plan includes is decided by the SocialScore service, not by the plugin: nothing in the plugin is locked behind a payment.

Compatible with High-Performance Order Storage (custom order tables).

== External services ==

This plugin connects to the SocialScore service (https://socialscore.io), operated by SocialScore, to check customers and orders. It is a paid service with a free tier; an account is required.

**When data is sent:** nothing is sent until an administrator clicks "Connect" on the SocialScore admin page and approves the connection in SocialScore. Before that the plugin makes no request to SocialScore. After connecting, data is sent when an order or customer is created or updated, once for the first import of existing data, and when a check is run (manually or by the automatic checks you enabled).

**What is sent:** your store address; for customers: email, phone, first and last name, city, postcode, country, number of orders, total paid and the date of the last order; for orders: order number, total, currency, date, the products (id, name, quantity, price), and shipping city, postcode and country. Payment details, passwords and street addresses are not sent. The store key SocialScore issues stays on your server and is never sent to the browser.

**Disconnecting:** the Disconnect button tells SocialScore to remove the store and deletes the key from your site. Uninstalling the plugin removes its options and scheduled actions.

Service: https://socialscore.io
Terms of Service: https://socialscore.io/terms
Privacy Policy: https://socialscore.io/privacy

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Open **SocialScore** in the admin menu and click **Connect**.
3. Sign in to SocialScore as an owner or administrator and approve the connection.
4. The import of existing customers and orders starts in the background.

== Frequently Asked Questions ==

= Does the plugin contact SocialScore before I connect? =

No. The first request to SocialScore happens after you press Connect.

= Where is the source of the JavaScript? =

The admin screens are built with `@wordpress/scripts`. The readable source is in `src/js` in the plugin folder and in the public repository at https://github.com/social-score/woocommerce-plugin. Build with `npm ci && npm run build`. The shared interface packages (`@socialscore/ui`, `@socialscore/plugin-core`) are vendored as tarballs in the `vendor-js` folder of that repository.

= Which permission do I need? =

The `manage_woocommerce` capability (Shop manager and Administrator).

== Changelog ==

= 0.1.0 =
* First release: connect, dashboard, check panel, order and customer sync, automatic checks, labels.

== Upgrade Notice ==

= 0.1.0 =
First release.
