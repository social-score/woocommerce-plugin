# SocialScore for WooCommerce

WordPress plugin that connects a WooCommerce store to SocialScore (public API v2). PHP 7.4+, WordPress 6.4+,
WooCommerce 8.0+, HPOS compatible. GPL-2.0-or-later. Slug and text domain: `socialscore`.

## How it works

| Part | Where |
|---|---|
| Connect (state + PKCE, store key encrypted, `autoload=no`) | `includes/class-connect.php`, `class-crypto.php`, `class-connection.php` |
| REST proxy `/wp-json/socialscore/v1/proxy/*` (nonce, `manage_woocommerce`, path allow-list; the key stays on the server) | `includes/class-rest-proxy.php` |
| First import and real-time sync (Action Scheduler, batches of 500, backoff, `Retry-After`) | `includes/class-sync.php`, `class-mapper.php` |
| Automatic checks with an Idempotency-Key per order/customer, labels and order notes | `includes/class-enrichment.php`, `class-labels.php` |
| Personal data export and erase (`wp_privacy_personal_data_exporters` / `_erasers`) call `GET .../customers/{id}/export` and `DELETE .../customers/{id}` for the account id and the guest id of the email; the export also lists, and the eraser removes, the checkout reference (`_ss_checkout_ref`) and the Pixel visit and session ids that older plugin versions left on the email's orders (`_ss_pixel_visitor`, `_ss_pixel_session`; the plugin no longer writes those two) | `includes/class-privacy.php` |
| Pixel on the storefront: loader, shop events, and a `login` event on the page after a login (no cookie copied onto orders, no user id sent) | `includes/class-pixel.php` |
| Checkout reference, the only order link: the plugin makes a random UUIDv4 in the WooCommerce session when it renders the checkout (classic and Checkout block), the Pixel sends it as `cart_token` with `begin_checkout`, the checkout posts it back (hidden field `ss_checkout_ref`, or Store API extension data `socialscore.ss_checkout_ref`), and an order keeps it in `_ss_checkout_ref` only when it equals the one in the buyer's own session. It is synced as the order's `cart_token`. The `purchase` event's order id is for analytics only | `includes/class-pixel.php`, `includes/class-mapper.php`, `src/js/pixel-events.ts` |
| Order meta box (HPOS aware), user profile section, orders list column; the flag "not from a human" shows in all three | `includes/class-check-panels.php` |
| Stage 5, bots that run no JavaScript: `Bot_Detector` reads the request headers (no cookie, no stored id, no IP) and gives a class and signals; `Bot_Guard` acts on a bad bot as the store's mode at SocialScore says (`flag` default, `substitute`, `block`); `Bot_Stats` counts requests per day | `includes/class-bot-detector.php`, `class-bot-guard.php`, `class-bot-stats.php` |
| Stage 5, the flag "not from a human" on orders, from the checkout request and from the class of the order's visit (`GET .../orders/{id}/session`, asked in the background after the order is synced) | `includes/class-not-human.php` |
| Stage 5, **SocialScore > Reports**: traffic from the Pixel report API, what the server caught, the bad-bot mode, the flagged orders | `includes/class-reports-page.php` |
| Stage 5, login, registration and order events with a hashed email or phone for Level 2: **off** (needs Level 2 at SocialScore, the plugin switch and the visitor's consent; Level 1 sends nothing personal) | `includes/class-identity.php` |
| Admin dashboard (plugin-core `StoreDashboard`) | `includes/class-admin-page.php`, `src/js/dashboard.tsx` |

The browser never talks to SocialScore: `src/js/host.ts` builds a `PluginHost` whose `apiFetch` calls the WP proxy.
Nothing is sent to SocialScore before an administrator presses Connect.

The plugin follows the final public API (`public-api-v2-ecommerce.md` in the social-score repo):

- Connect sends `redirect_uri` with the code exchange. The cost estimate is shown by plugin-core as an upper bound.
- The `ids` of every `orders:batch` answer are kept: each order stores the SocialScore id in the `_socialscore_order_id`
  meta (HPOS aware, removed on uninstall). The id is written through the order data store, so the order is not saved:
  no `woocommerce_update_order`, no webhook and no `date_modified` change for the orders of an import (on HPOS this relies on the
  `woocommerce_orders_table_datastore_should_save_after_meta_change` filter, WooCommerce 8.8+; older versions save the order). `Sync::order_for_socialscore_id()` finds the shop order for the `order_id` of an
  `order.created` event. Customer ids are not kept (events do not need them).
- Every order row carries `customer_external_id` (the account id, `guest-<sha1 of the email>`, or `guest-order-<id>`).
- Settings answer `active` or `disconnected` (plugin-core shows it). A store uninstalled on the SocialScore side answers
  `401`: any `401` on a store route makes `Api_Client` forget the key, stop the queue and show "connect again" on the page.
- `/api/v2/hooks` is company-key only and is not called by this plugin.
- Customer redact/export work for 7 days after an uninstall on the server side, but a disconnect here deletes the key, so
  the WordPress tools only reach SocialScore while the store is connected.

## Development

Everything runs in Docker (the host Node is too new for the tooling).

```sh
# PHP: install, lint, unit tests
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install && vendor/bin/phpcs && vendor/bin/phpunit"

# JS: build and test (wp-scripts)
docker run --rm -v "$PWD":/app -w /app node:20 sh -c "npm ci && npm run build && npm test"

# WordPress + WooCommerce smoke on the release layout (activate, HPOS, connect/sync/check flow against a mock API,
# the bot scenario of stage 5 over HTTP, uninstall, Plugin Check, PHP log must be clean). Needs `npm run dist` first.
npm run dist && docker compose -f tests/smoke/docker-compose.yml run --rm smoke; docker compose -f tests/smoke/docker-compose.yml down -v

```

`vendor-js/` holds `@socialscore/ui` and `@socialscore/plugin-core` unpacked from `npm pack` (consumed with `file:`;
unpacked because Plugin Check refuses compressed files in the zip) until GitHub Packages publishing exists. To update
one, extract the new tarball over its folder, then run `npm install` and `npm run gen:messages` to refresh
`includes/class-messages.php`, the translatable catalogue of the shared screens. The catalogue has the
plugin-core 0.4.0 texts (signals, `info_bot`, no bot score).

## Release zip

`.distignore` lists what is left out. The built `build/` folder must be present; the readable source stays in
`src/js` and `vendor-js/` ships too, so the zip can be rebuilt (`npm ci && npm run build`) as WordPress.org requires.

## Stage 5 (Pixel): documents

`docs/architecture-pixel-s5.md` (what was built, the decisions, what depends on stage 6) and `docs/test-plan-pixel-s5.md`.
