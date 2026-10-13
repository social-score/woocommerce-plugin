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
| Order meta box (HPOS aware), user profile section, orders list column | `includes/class-check-panels.php` |
| Admin dashboard (plugin-core `StoreDashboard`) | `includes/class-admin-page.php`, `src/js/dashboard.tsx` |

The browser never talks to SocialScore: `src/js/host.ts` builds a `PluginHost` whose `apiFetch` calls the WP proxy.
Nothing is sent to SocialScore before an administrator presses Connect.

The plugin codes against `design-public-api.md` / `design-events.md`. Paths that the API does not serve yet (overview,
customers list, segments, estimate, token response shape) are called as plugin-core calls them; see "needs backend"
in the PR notes.

## Development

Everything runs in Docker (the host Node is too new for the tooling).

```sh
# PHP: install, lint, unit tests
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install && vendor/bin/phpcs && vendor/bin/phpunit"

# JS: build and test (wp-scripts)
docker run --rm -v "$PWD":/app -w /app node:20 sh -c "npm ci && npm run build && npm test"

# WordPress + WooCommerce smoke on the release layout (activate, HPOS, connect/sync/check flow against a mock API,
# uninstall, Plugin Check, PHP log must be clean)

```

`vendor-js/` holds the tarballs of `@socialscore/ui` and `@socialscore/plugin-core` (consumed with `file:`) until
GitHub Packages publishing exists. After updating them run `npm run gen:messages` to refresh
`includes/class-messages.php`, the translatable catalogue of the shared screens.

## Release zip

`.distignore` lists what is left out. The built `build/` folder must be present; the readable source stays in
`src/js` as WordPress.org requires.
