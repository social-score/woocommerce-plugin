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
* Orders that did not come from a person (a bad bot, a known bot or an AI agent) are flagged in the orders list, on the order screen and with an order note.
* A **Reports** page under SocialScore shows your traffic from the SocialScore Pixel, what your server caught without JavaScript, the flagged orders, and lets you choose what happens to a bad bot: flag it (default), show it substitute data, or block it.
* Export and erase requests for a customer (Tools > Export / Erase Personal Data) also reach SocialScore.
* You choose when checks run automatically (new account, every account, new customer's order, every order, or manual only).

The plugin is fully functional on its own. Which checks your SocialScore plan includes is decided by the SocialScore service, not by the plugin: nothing in the plugin is locked behind a payment.

Compatible with High-Performance Order Storage (custom order tables).

== External services ==

This plugin connects to the SocialScore service (https://socialscore.io), operated by SocialScore, to check customers and orders. It is a paid service with a free tier; an account is required.

**When data is sent:** nothing is sent until an administrator clicks "Connect" on the SocialScore admin page and approves the connection in SocialScore. Before that the plugin makes no request to SocialScore. After connecting, data is sent when an order or customer is created or updated, once for the first import of existing data, and when a check is run (manually or by the automatic checks you enabled).

**Pixel:** only after you connect and switch the SocialScore Pixel on, a script from https://pixel.socialscore.io is loaded on your storefront. It sends page-level events (page views, product, cart, checkout and the order total) to SocialScore. On the default level it writes no cookie or other data to the visitor's device and sends no name, email, phone or address.

**Bots:** to recognise a bot that runs no JavaScript the plugin reads the user agent and a few other headers of each storefront request of a visitor who is not logged in. It keeps no cookie, no address and no user agent: only a count per day and kind of visitor, on your own site. Nothing about the visitor is sent to SocialScore for this. The Reports page asks SocialScore for your traffic totals (no personal data) and the order screen asks for the kind of visit behind an order (a class and signal codes). The plugin can send a hash of a customer's email at login, registration and order only if you switch on Level 2 in SocialScore, in the plugin, and the visitor has given consent; it is off by default. If your store is behind Cloudflare or another proxy you can tell the plugin on the Reports page; "Behind Cloudflare" is safe only when your server accepts requests from Cloudflare alone.

**What is sent:** your store address; for customers: email, phone, first and last name, city, postcode, country, number of orders, total paid and the date of the last order; for orders: order number, total, currency, date, the products (id, name, quantity, price), and shipping city, postcode and country. Payment details, passwords and street addresses are not sent. The store key SocialScore issues stays on your server and is never sent to the browser.

**Privacy requests:** the plugin adds SocialScore to WordPress's Export Personal Data and Erase Personal Data tools. For a customer's email address, an export asks SocialScore for what it holds about that customer in your store (profile data, orders, check results), and an erasure asks SocialScore to redact that customer and their orders. Only the customer's id in your store is sent, and only while the store is connected. If the store is not connected (for example after Disconnect, which keeps the data at SocialScore until it is redacted), the plugin cannot ask SocialScore, and the erasure tool reports that data may still be retained there.

**Disconnecting:** the Disconnect button tells SocialScore to remove the store and deletes the key from your site. Uninstalling the plugin removes its options and scheduled actions. If SocialScore stops accepting the store key (for example the store was uninstalled in SocialScore), the plugin forgets the key, stops sending data and asks you to connect again.

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

The admin screens are built with `@wordpress/scripts`. The readable source is in `src/js` in the plugin folder. The shared interface packages (`@socialscore/ui`, `@socialscore/plugin-core`) are included unpacked in the `vendor-js` folder of the plugin; the original TypeScript source of each is embedded in its source map (`dist/index.js.map`, field `sourcesContent`). To rebuild, run `npm ci && npm run build` in the plugin folder.

React and ReactDOM are not part of the plugin: the screens use the copies WordPress ships (script handles `react` and `react-dom`, listed in `build/*.asset.php`). WordPress 6.4 (React 18.2) is enough; the shared packages need React 18.2 or newer. `react/jsx-runtime`, which WordPress registers only from 6.6, is provided by the small shim `src/js/jsx-runtime-shim.ts` on top of WordPress's `react`.

= Which permission do I need? =

The `manage_woocommerce` capability (Shop manager and Administrator).

== Changelog ==

= 0.1.0 =
* First release: connect, dashboard, check panel, order and customer sync, automatic checks, labels, personal data export and erase.

== Upgrade Notice ==

= 0.1.0 =
First release.
