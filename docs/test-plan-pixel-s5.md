# Test plan: Pixel stage 5 (store modules)

Result: PASS 13.11.2026

Architecture: `architecture-pixel-s5.md`. The done criterion of the stage: **in a test WooCommerce shop a bot without
JavaScript is caught, its order gets a flag, and the mode "block" stops the request.** It is proven twice: as a unit test
(`BotGuardTest::test_a_bot_without_javascript_is_caught_its_order_is_flagged_and_block_mode_stops_the_request`) and in real
WordPress and WooCommerce over HTTP (`tests/smoke/bots.php`, part of the CI smoke job).

## What is tested, by part

| Part | Test | What it proves |
|---|---|---|
| 5.2 detection | `BotDetectorTest` (22) | A plain browser is `unknown`, never a person. Script, headless, scanner and empty user agents are `bad_bot` with their signal. One missing browser header is not enough, two are. Fetch metadata is expected only over HTTPS from modern Chromium. Declared good bots are `info_bot`; AI agents get system and purpose; `Applebot-Extended` is not `Applebot`; `Signature-Agent` makes a shopping agent. A declared bot is never `bad_bot`. A very long or non-string header is safe. The result holds no address. |
| 5.3 modes | `BotGuardTest` | `block` gives 403 to a bad bot; `flag` and `substitute` serve it. The mode is `flag` when the server does not say or says something unknown. A good bot or an AI agent is never blocked or changed. A person costs no request to SocialScore and writes nothing. Nothing happens with the Pixel off, the store disconnected, a logged-in user, cron or admin. REST and `wc-api` callbacks and cron are never blocked; the Store API is. A filter can exempt a request. In every mode stock shows without quantities; in `flag` the price is real; in `substitute` the price, the variation JSON, the JSON-LD and the Store API prices change, stably per product and day, and the stored product is untouched; a person always sees the real values. |
| 5.2 counts | `BotGuardTest` | Counted per day and class, no address and no user agent, `autoload=no`, 31 days kept. |
| 5.1 flag | `NotHumanTest` (18) | The session route is asked after 2 minutes and again at 15 min, 1 h, 6 h while `session_not_found`; other 404 ends it; a 5xx retries with the sync backoff. Bad bot, known bot and AI agent flag the order with a note once; a person removes a Pixel flag and never a request flag; a class this version does not know changes nothing. Saving the flag does not wake the order sync. The classic and block checkout flag a bot request and leave a person's order alone. List cell, order-screen warning (escaped, reasons as words). A value that is not one of the classes is not a flag. `SyncTest`: a synced order gets the delayed look; an order the API set aside does not. |
| 5.4 Level 2 | `IdentityTest` (10) | Level 1 (the default) queues nothing, sends nothing and does not even look up the Pixel state. Each of the three conditions is needed. With all three, login, registration and order queue only SHA-256 hashes (email lower-cased and trimmed, phone digits): the queue holds no plain value. The Consent API answer is the default and the filter may replace it. A send goes to the identity route with the hashes only. The Pixel class has no email or hash. Shopify: `identity.test.ts` (embed prints nothing unless `level2` is true, a customer is signed in and not in the theme editor; prints only the hash; reads only `customer.email`; the Level 1 embed and script are free of any hash; the script sends one `identity` event per session only with marketing consent, waits for the consent banner, ignores bad data). Pixel (Go): an `identity` event is dropped with or without marketing consent and no byte of it reaches BigQuery; a hash on `login` is refused. |
| 5.5 reports | `ReportsPageTest` (16), `reports.test.ts`, `proxy.server.test.ts`, `app.reports.test.ts` | The right call (period, grouping, comparison, the store's key), a five-minute cache, an unknown period falls back, error codes become plain words, totals with change and a dash with nothing to compare, revenue only for ISO codes, escaping of keys, the server counts, the warnings list queries by meta, the mode form has a nonce and saves through `PATCH`, an invalid mode is refused before any request, `off`/`denied` are shown. Shopify: the proxy lets through only `GET stores/:sid/pixel/report` with plain-word query values, and the browser still cannot send `bot_mode`; the loader forwards no page query and never the key; a failing channel grouping keeps the traffic. |
| 5.6 audiences | `tests/services/test_pixel_bots.py` (11), `tests/controllers/audiences/test_pixel_bots.py` | Bad bot and known bot are left out; human, lurker, AI agent and a person never seen stay; only the latest session decides; a visitor tied through the customer row counts; another person's or another company's bot does not; a phone-only profile is found through its identifier; an upload's bot is not a member of its segment; a real audience people query leaves the bots out. |
| 5.3 setting | `test_pixel_public.py` (SwitchTest) | `bot_mode` is `flag` until set and kept by a switch that names none; three values only (422 otherwise); the role of the switch is needed; the database check refuses another value; the snapshot `openapi_v2_ecommerce.json` is regenerated on purpose. |
| Order flag, Shopify | `check.test.ts` | Flagged classes, their texts, and that the flag follows the one order to session route. |
| Whole stack | `tests/smoke/bots.php` | In real WordPress and WooCommerce (HPOS on) against the mock API: flag mode serves a script, Googlebot and a person; the server counted them without an address or agent; the classic checkout of a script is flagged and noted, a person's is not; the Pixel class from the API flags an order, a missing one does not; stock is hidden for a bot in every mode and shown to a person; in `substitute` the bot sees another price, and the real price is nowhere in its page (JSON-LD included: this found a real bug in the first run, `priceSpecification` is a list); the charge is unchanged; in `block` a script gets 403 on a product page, home and Store API, a request with no user agent too, while a person, Googlebot, a REST callback, `wc-api` and `wp-cron` are served; with the Pixel off nothing is blocked; the reports page opens with the traffic, the three modes and the warning; the orders list and order screen show the flag; no identity event is queued on Level 1. |

## Commands and what they must give (as CI runs them)

| Repository | Command | Expected |
|---|---|---|
| woocommerce-plugin | `vendor/bin/phpcs` then `vendor/bin/phpunit` (CI: PHP 7.4, 8.2, 8.4) | phpcs clean; all tests green |
| woocommerce-plugin | `npm ci && npm run typecheck && npm run build && npm test` | green (no JS source changed) |
| woocommerce-plugin | `npm run dist && docker compose -f tests/smoke/docker-compose.yml run --rm smoke` | `bots done` and exit 0 |
| shopify-app | `npm run typecheck`, `npm test` (there is no CI workflow in this repository) | green; `npm run lint` has the errors of files this stage did not touch |
| pixel | `make test` (`go test ./... -race` in Docker) | ok |
| social-score | `black --check`, `pylint` on changed files, `python scripts/run_tests_parallel.py -j 6` | clean, all tests ran |

## Run

- woocommerce-plugin: `phpcs` clean (includes, uninstall); `phpunit` 267 tests, 754 assertions, PHP 8.4; JS typecheck and 44 tests;
  the smoke on WordPress and WooCommerce: `SMOKE OK` (bot scenario, Plugin Check without errors, empty PHP log, uninstall cleanup).
- shopify-app: typecheck clean, 326 tests pass (1 skipped).
- pixel: `make test` ok (`go test ./... -race`).
- social-score: black and pylint clean; `scripts/run_tests_parallel.py -j 6`: 9448 tests discovered and run, OK (3 skipped, as recorded).
- Second round (independent check): substitute mode on the real Store API (object `prices`, `price_range`, mixed-case route, `rest_route`),
  admin-ajax `woocommerce_get_variation` (substitute and block), gateway webhooks with a Java and a Go user agent in both `wc-api` forms
  (not blocked), the real price absent in every form from a bot's product page; unit tests for the same with the shapes WooCommerce returns,
  `BotVerifierTest` (verified, fake, fail open, cache, domain suffix), the factor never 1.00, the queued identity event re-checked,
  Klaviyo list adds and removals (`BotsTest`).
- Added later in this stage: a bot with a made-up `?wc-api=x` is blocked or served substitute data (unit and smoke); a changed
  response and the 403 carry `no-store` and `DONOTCACHEPAGE` (unit and smoke); `placed_by_agent` (MapperTest, `test_order_placed_by_agent.py`).

## Not tested, on purpose

- PHP 7.4 and 8.2 (only 8.4 was available on the build host; the code uses no syntax after 7.4 and `phpcs` runs the
  PHPCompatibility ruleset).
- A real browser on the storefront and the real Shopify admin (the Check card banner is a Polaris component; its logic is
  unit tested, its look is not).
- Real bots, real Google or AI crawlers: the classes come from header text only.
- Level 2 end to end: there is no server side yet (stage 6).
