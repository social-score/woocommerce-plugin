# Pixel stage 5: the store modules (WooCommerce and Shopify)

Stage 5 of `claudedocs/company/pixel/ceo-brd-plan.md`, requirements in `ceo-brd.md` sections 3.3, 5, 5.1, 7 and 8. The
modules (this repository, `shopify-app`) get a flag for orders that did not come from a person, server-side detection of
bots without JavaScript (WooCommerce only), the three bad-bot modes, a prepared and switched-off Level 2 identity, and a
reports page. `social-score` gets the setting for the modes and the cleaning of audiences and segments. The publishing
(5.7) is the owner's and is not part of this stage.

This page lives in `woocommerce-plugin` because it is the main repository of the stage. Branches: `feat/pixel-s5-plugins`
in `woocommerce-plugin`, `shopify-app`, `pixel` and `social-score`.

## 0. What already exists (checked in the code before building)

| Existing | Used for |
|---|---|
| `GET /api/v2/stores/{id}/orders/{external_id}/session` (stage 1) | The class and signals of the visit behind an order. The one order to session link is the checkout reference (Woo) or the cart token (Shopify), already synced as the order's `cart_token`. 5.1 reads this; no second link is made. |
| plugin-core `CheckPanel` and the Shopify Check card | Already show the class of the visit. 5.1 adds the flag next to it, the list column and the order note. |
| `GET /api/v2/stores/{id}/pixel/report` (stage 2) | The data of the reports page (5.5). Totals, series, comparison. |
| `GET`/`PATCH /api/v2/stores/{id}/pixel` and `Pixel::state()` (ten-minute cache) | Carries the new `bot_mode` (5.3) and, later, `level2`. |
| `on_site_behavior` ties (`pixel_visitors`, `ecommerce_customers.person_id`) | The same ties decide which person is a bot for the audiences (5.6). |
| `Sync::settle`, `queue_unique`, Action Scheduler, `Labels` | Retry, background jobs and quiet order saves for the new job. |

## 1. Server-side detection, WooCommerce (5.2, C3)

`Bot_Detector::classify( $_SERVER )` is a pure function of the request headers. It runs on every storefront request of a
visitor who is not logged in (`Bot_Guard::inspect`, `init` priority 1), keeps **no cookie, no stored id and no IP**, and
writes nothing for a request it does not take for a bot. Result: a class (`info_bot`, `ai_agent`, `bad_bot`, or `unknown`)
and signal codes, in the words the Pixel uses (contract v4 section 2).

| Order of checks | Class | Signals |
|---|---|---|
| `Signature-Agent` header | `ai_agent` (shop) | `signature_agent` |
| User agent has a declared AI crawler or agent token (GPTBot, ClaudeBot, ChatGPT-User, Perplexity-User, Applebot-Extended ...) | `ai_agent` with system and purpose | `declared_agent` |
| User agent has a declared good bot token (Googlebot, bingbot, facebookexternalhit, UptimeRobot ...; the list of contract section 2) | `info_bot` | `declared_bot` |
| Empty user agent; headless or automation token; HTTP library or scraper token (curl, python-requests, Go-http-client, Scrapy ...); security scanner token | `bad_bot` | `empty_ua`, `headless_ua`, `http_client_ua`, `scanner_ua` |
| A `Mozilla/` user agent missing **two or more** of: `Accept-Language`, `Accept`, and (modern Chromium over HTTPS) fetch metadata | `bad_bot` | `no_accept_language`, `no_accept`, `no_fetch_metadata` |
| Anything else | `unknown` | none |

It can say that a request is a bot, never that it is a person: a visitor who runs no JavaScript and sends a normal browser
header set stays `unknown`. A declared bot is taken at its word (no reverse DNS), so a bot can pretend to be Googlebot. That is
why only a `bad_bot` is ever blocked or changed.

Counting: `Bot_Stats` adds one to `socialscore_bot_hits[day][class]` (option, `autoload=no`, 31 days) for each such request,
only while the store is connected and the Pixel is on. Requests, not visitors. A lost update at the same instant is accepted.

Limit: a full-page cache that answers before WordPress runs also answers bots. Such a request is neither counted nor blocked
(block it at the cache, CDN or firewall). The module sees what reaches PHP.

Left alone: logged-in users, the admin, cron, WP-CLI, the REST API outside `/wc/store` (payment and SocialScore callbacks),
`wc-api` callbacks, `xmlrpc.php`, `wp-cron.php`. The filter `socialscore_bot_guard_skip` lets a shop add its own exemption.

## 2. The three modes (5.3)

The decision is the store's setting at SocialScore: `stores.pixel_bot_mode` (`flag` default, `substitute`, `block`),
returned as `bot_mode` by `GET /api/v2/stores/{id}/pixel` and set by `PATCH` (same role as the switch: the member who
connected the store needs Main). The module reads it through `Pixel::state()` and never decides: unknown or unreachable is
`flag`. It acts only on a `bad_bot`, only when the store is connected and the Pixel is on.

| Mode | What a bad bot gets |
|---|---|
| `flag` | The normal page and the normal price. Stock shows only as in stock or out of stock, no quantity. The order made from that request is flagged (section 3). |
| `substitute` | As `flag`, but the price is replaced for display: price HTML, the variation JSON, the structured data (JSON-LD) and the Store API product answers show the real price times a factor between 0.85 and 1.15, fixed per product and day and different per site. **Only what is shown changes**: the cart and the checkout read the real prices, so a person taken for a bot by mistake pays the right amount. |
| `block` | 403 (`wp_die`) before the shop answers, also on the Store API. |

The stock text is hidden in every mode through `woocommerce_get_availability_text`, `woocommerce_available_variation` and the
Store API `low_stock_remaining`.

**Does ingest need to tell the module what it decided for a session? No, not in this stage.** Ingest classifies after
JavaScript has run, on a session key the module cannot compute (an HMAC with a secret), so telling the module per request
would need a lookup on every page, with the visitor's address sent from the shop to ingest. What the module needs from the
Pixel is already there: the class of the visit behind an order (`orders/{id}/session`, section 3). A JavaScript-running
bad bot is therefore flagged on its order but not blocked on its page views; blocking acts on what the module itself can see.
See "Decisions" 3.

## 3. The flag on orders (5.1, C2)

An order is flagged from two sources into one set of order meta (`_socialscore_not_human` = `bad_bot`, `ai_agent` or
`info_bot`, `_socialscore_not_human_signals`, `_socialscore_not_human_source` = `server` or `pixel`):

1. **The request that placed the order** (`woocommerce_checkout_create_order` and the Store API
   `..._update_order_from_request`): the module's own verdict. This is what catches a bot without JavaScript.
2. **The class of the visit** (`Not_Human::handle_session`): after an order is synced, a background job asks
   `GET /orders/{id}/session` two minutes later, and again after 15 minutes, 1 hour and 6 hours while the answer is
   `404 session_not_found` (the Pixel server sends a session some time after the visit). A bad bot, a known bot and an AI
   agent flag the order (`source` = `pixel`); a person removes a flag the Pixel set earlier (the latest session wins, as in
   SocialScore) and never one the module wrote from the request.

Where the merchant sees it: an order note (once per class), the orders list cell ("Not human: Bad bot"), a warning with the
reasons above the Check panel on the order screen, and the Warnings list on the reports page. The order status is never
changed: a bot's order that is real is not a problem in itself.

Shopify: the order card of the admin extension shows a banner "Not from a human" when the visit behind the order is a bad bot,
a known bot or an AI agent (same route). No order tag is written: that needs the `write_orders` scope.

The flag is not sent to SocialScore (the API has no field for it); the order to session link already tells SocialScore.

## 4. Audiences and segments without bots (5.6, social-score)

`app/services/pixel/bots.py`: a person is a bot when the **latest** session of the visitors the shop server tied to them
(the ties of `on_site_behavior`, in the stores of the profile's own company) is `bad_bot` or `info_bot`. An AI agent stays (it
can shop for a person); a person the Pixel never saw stays (nothing says they are a bot). The condition is part of:

- the audience people query (`audiences/members.py`): so the upload, the CSV export, the real-time adds and the preview
  counts all leave bots out;
- the segment member lists: `SegmentRepository.execution_member_ids` and, for an own-data selection, `without_bots`.

A segment that already exists keeps its stored members until it is rebuilt; an audience is cleaned at each upload.

## 5. Level 2 identity, prepared and OFF (5.4, C4)

**Level 1 sends nothing personal.** The login and registration code exists but does nothing unless all three hold, checked on
every event: Level 2 is on for the store at SocialScore (`level2` in the Pixel state, which SocialScore does not send yet),
the plugin's own switch is on (constant `SOCIALSCORE_LEVEL2` or filter `socialscore_level2_enabled`, default off), and the
visitor has consented (WP Consent API `wp_has_consent( 'marketing' )`; the filter `socialscore_level2_consent` may replace the
answer for a banner without it).

| | WooCommerce (`Identity`) | Shopify (embed `socialscore-identity`) |
|---|---|---|
| Events | `login` (`wp_login`), `register` (`user_register`), `order` (`woocommerce_new_order`) | `login` (the signed-in customer on a page) |
| What leaves | SHA-256 of the trimmed lower-case email and, for an order, of the phone's digits, the type and the time | SHA-256 of the email |
| How | Action Scheduler job `socialscore_identity_event` posts to `POST /api/v2/stores/{id}/pixel/identity` (**stage 6 adds the route**; until then a send is answered 404 and dropped) | An `identity` event on `window.ssPixel`; Level 1 ingest drops an unknown type (tested), so it cannot be stored before stage 6 |
| Switched off by | the three conditions above | the Level 1 embed carries no customer data (tested); this is a block of its own, prints nothing unless the app-data metafield `socialscore_pixel.level2` is true (the app never writes it in this version), needs the merchant to switch the embed on, and the script waits for `customerPrivacy.marketingAllowed()` |

Shopify registration cannot be told apart in a theme (the customer object has no creation time), so Shopify sends `login`
only; a registration hash from the server (`customers/create`) would have no consent to go with it. Stage 6 decides.

The hash is a **comparison hash** for the server to match a person. The server-side hash with a company key and the encrypted
copy (owner decision 4) are stage 6.3; the plugin's SHA-256 is what an ad platform also takes, so it adds no new secret.

## 6. Reports page (5.5)

WooCommerce: **SocialScore > Reports** (`Reports_Page`), rendered by PHP: the totals with the change against the previous
period, by kind of visitor and by channel (two calls, cached five minutes), what this server caught without JavaScript
(section 1), the bad-bot mode form (saved at SocialScore, section 2) and the Warnings list (flagged orders). Shopify: **Reports**
in the app menu (`app.reports`), the same totals and groupings from the same route through the app's proxy, which gets
`stores/:sid/pixel/report` on its allow-list. The browser never sees the store key.

## 7. Orders from an AI agent ({added}, MCP store, stage 8)

The MCP Store module (`mcp-store`, `docs/architecture-pixel-s8.md` §4 and §8) sets the order meta `_mcpstore_agent_order` =
`yes` on an order an agent placed. `Mapper::order` sends `placed_by_agent: true` in `orders:batch` when it finds exactly
that value, and nothing otherwise, so a shop without the module sends the same rows as before. social-score stores it in
`ecommerce_orders.placed_by_agent` (boolean, default false, migration `d4f9b6e2a8c1`, expand only); a push that leaves the
field out keeps the stored value, a value that is not a boolean is a 422. No field for this existed on orders (stages 1-2
have `agent_system` on sessions only), so this one is new; it is not a second copy of the session's. Only the WooCommerce
plugin sends it; reading it back into reports is later.

## Decisions

1. **The flag is for `bad_bot`, `info_bot` and `ai_agent`.** The requirements say "from a bot or not from a human"; an agent's
   order is not a person's order directly. The text differs by class and only a bad bot is shown as critical.
2. **Detection is conservative**: strong evidence (a script's user agent, empty user agent, a headless token) or two weak
   header signals; one weak signal is not enough.
3. **Blocking acts on what the module sees itself.** No per-request lookup to ingest (section 2).
4. **The mode is set in the module's reports page and kept at SocialScore.** The dashboard (`client`) has no screen for it yet;
   stage 3 or a later stage can add one over the same `bot_mode` field.
5. **Substitute changes display, never the charge.**
6. **Counts are requests, not visitors**, and stay on the shop (option), because the API has no route for server-side hits.
   Sending them to SocialScore, so they appear in the traffic report, needs a route and a `pixel_daily` row kind; not in this stage.
7. **A bot is a person's latest session** (section 4), not any session in a window, so one bad session does not exclude a
   person for ever.
8. **Level 2 is dormant in three places** and every one is tested.

9. **Declared search engines are checked by their published address ranges** (Googlebot family against Google's four files under
   `developers.google.com/static/crawling/ipranges/` (the old `/search/apis/ipranges/` addresses redirect there), Bingbot against
   `bing.com/toolbox/bingbot.json`; checked 13.11.2026). A daily wp-cron job downloads them into one option (`autoload=no`); an engine
   whose download fails or comes back empty keeps its old list. The check on a request is a comparison with that list: **no DNS, no
   network, no write**. A public address in none of the engine's ranges is a bad bot (`fake_bot`). No list yet (the first day), or a
   private address, **fails open**: a known bot with the signal `unverified_bot`. A shop behind a proxy or CDN must give the real
   client address in the filter `socialscore_client_ip`, or the proxy's address is in no range; `socialscore_verify_declared_bots`
   returns false to switch the check off. Only these engines are checked; the other declared bots are taken at their word.
10. **A real `wc-api` request is never touched**, judged the way WooCommerce judges it (a non-empty text name in the query, or the
    `/wc-api/<name>/` path). A webhook that is blocked is a lost payment, and the request builds no page. An empty name, a list or
    a name in the POST body is not an API request to WooCommerce: it builds the page, so the guard treats it as a page.
11. **admin-ajax is guarded for `woocommerce_*` actions only**: they are the storefront's own (variation lookup), open
    to visitors who are not logged in. Other plugins' admin-ajax actions are not ours to block.

## Not done here, and what depends on whom

- The route `POST /api/v2/stores/{id}/pixel/identity`, the `level2` field of the Pixel state, the Level 2 switch and
  consent in ingest: **stage 6**. Until then no personal event is made anywhere.
- A screen for the bad-bot mode in the dashboard (`client`): later.
- Server-side hits in the traffic report: later (decision 6).
- Publishing to WordPress.org and the Shopify App Store (5.7): the owner. `readme.txt` now describes the reports page and the
  bot handling, because the external-services text must be true before review.
- A shop behind a CDN must set `socialscore_client_ip` (or switch the check off) for the Googlebot and Bingbot check to be right.

## Tasks

- [x] T1 social-score: `stores.pixel_bot_mode` (migration `c3e8a5d1f7b4`), `bot_mode` in `GET`/`PATCH .../pixel`, snapshot and docs {verified}
- [x] T2 social-score: `pixel/bots.py`, bots out of audiences (`AudienceMembers`) and segments (`execution_member_ids`, `without_bots`) {verified}
- [x] T3 woocommerce: `Bot_Detector` (headers to class and signals) and its tests {verified}
- [x] T4 woocommerce: `Bot_Guard` modes: flag, substitute, block; stock without quantities; skips for integrations {rejected r2: block-theme product and shop pages embed Store API product JSON for a bad bot with the real price ("4747") and low_stock_remaining:2 in substitute and flag mode; on 6cb84aa an empty/array/POST wc-api serves the page unguarded}
- [x] T5 woocommerce: `Bot_Stats` counts per day and class {verified}
- [x] T6 woocommerce: `Not_Human` flag on orders from the request, and from the session route through a delayed job; note, list cell, order screen {verified}
- [x] T7 woocommerce: `Pixel::state()` carries `bot_mode` and `level2` {verified}
- [x] T8 woocommerce: `Identity` (C4), off by default, three conditions, hashes only {verified}
- [x] T9 woocommerce: `Reports_Page`, mode form, warnings list {verified}
- [x] T10 shopify: "Not from a human" banner on the order card {verified}
- [x] T11 shopify: Reports route, proxy allow-list for the report {verified}
- [x] T12 shopify: `socialscore-identity` embed and script, off, tested {verified}
- [x] T13 pixel: tests that a hash event is dropped and a hash on `login` is refused on Level 1 {verified}
- [x] T14 smoke: WordPress and WooCommerce run the bot scenario over HTTP (flag, substitute, block, reports, order flag) {rejected r2: SMOKE OK on 6cb84aa, but it misses the hydrated JSON leak (it searches "9.90", the JSON holds "990") and the empty/array wc-api cases; the working-tree smoke fails at bots.php:196 (POST to a GET-only route without _method=GET, 404)}
- [x] T15 readme and listing texts {verified}
- [x] T19 {added} Orders from the MCP store: `placed_by_agent` in the plugin row and in social-score {verified}
- [x] T20 {added} Bot_Guard: a callback is skipped only when really routed (a `wc-api` name some hook handles; a REST path under the home URL or `rest_route`, which WordPress routes to REST on any address and so never renders a page); a marker added to a page address no longer lets a bot through {rejected r2: replaced by T26, which skips every wc-api request; on 6cb84aa ?wc-api=, ?wc-api[]=x, ?wc-api=%20 and POST wc-api= give the normal product page in block mode (200, "2 in stock")}
- [x] T21 {added} Bot_Guard: every response it changes, and the 403, is sent with `nocache_headers`, `Cache-Control: private, no-store` and `DONOTCACHEPAGE` {verified}
- [x] T22 {added} Shopify: checked, no analog (the app changes no storefront response; the embed only observes) {verified}
- [x] T23 {added} Bot_Guard: the Store API `prices` and `price_range` are objects, and are changed as objects; substitute applies to `/wc/store/v1/products` only, never to the cart; checked in the smoke on a real answer {rejected: direct Store API product answers are now substituted (verified live), but product JSON hydrated into pages does not go through rest_post_dispatch and stays real; collection-data price_range shows real min/max (990/7272)}
- [x] T24 {added} Bot_Guard: a Store API route is judged from the route WordPress dispatched, lower-cased, slashes collapsed; `rest_route` is read from the POST body first, as WordPress does; an unreadable route is checked, not skipped {verified: body rest_route, /WC/STORE, unversioned /wc/store/products, rest_route[] all 403 in block; direct answers clean in substitute}
- [x] T25 {added} Bot_Guard: admin-ajax actions `woocommerce_*` are guarded like the storefront (they answer visitors who are not logged in); other admin screens and actions are not {verified: admin-ajax woocommerce_get_variation 403 in block, substituted in substitute}
- [x] T26 {added} Bot_Guard: every `wc-api` request (query, POST, `/wc-api/<name>/`) is left alone: a gateway webhook must never get a 403, and WooCommerce answers it with a stub, not a page (smoke: 400, 2 bytes); the earlier rule that looked for a hook at `init` priority 1 saw no gateway yet {rejected: every wc-api request skipped is a bypass: empty name, list and POST-body name render the normal product page with no block, no substitute and the stock quantity}
- [x] T27 {added} Pixel: the price in the Pixel events and in `data-ss-price` goes through `Bot_Guard::shown_price` {verified: data-ss-price on /shop/ for a bad bot shows substitute values (41.3 for 47.47)}
- [x] T28 {added} Substitute factor never 1.00 (30 steps, 0.85 to 1.15 without 1.00) {verified: code and unit tests; factor 0.85-0.99 or 1.01-1.15}
- [x] T29 {added} `Bot_Verifier`: a declared Googlebot or Bingbot is checked by reverse then forward DNS, kept 24 hours per address; fail is a bad bot, no answer fails open with the signal `unverified_bot` {verified with limit: real Googlebot/Bingbot 200, consistent non-engine PTR 403 (cached); a fake Googlebot from an IP with no PTR or a PTR without matching forward still passes (fails open by design)}
- [x] T30 {added} `Identity::send` checks the switches again; social-score: Klaviyo list sync adds no bot and removes a listed one {verified: social-score suite 9451 OK incl. 3 Klaviyo bot tests; Identity unit tests}
- [x] T31 {added} Bot_Guard: `rest_pre_dispatch` decides from the dispatched route (`$request->get_route()`, any case): in block mode a bad bot gets 403 on every Store API route, however the address or the POST body `rest_route` named it (body route behind `/wp-json/wp/v2/types`, `cart/add-item`, encoded and doubled paths, `index.php`, capitals); the post-dispatch scrub uses the same rule {verified on the uncommitted working tree only, not pushed: body rest_route cart/add-item 403 in block}
- [x] T32 {added} Bot_Guard: `wc-api` is judged as WooCommerce does (a query name that is text and not empty after `sanitize_key`, or the `/wc-api/<name>/` path); an empty name, a list or a POST body name is a page request and is blocked or substituted {verified on the uncommitted working tree only, not pushed: empty/array/POST wc-api 403 in block, substituted in substitute}
- [x] T33 {added} wc-api: a marker does not exempt admin-ajax or a Store API route; the exemption needs a non-empty text name in the query or the `/wc-api/<name>/` path (smoke: all three variants on a product give 403 in block mode and the substitute price in substitute mode; `probe_gw` with a Go and a Java agent passes)
- [x] T34 {added} Substitute applies to every Store API version and route (products, `collection-data` price range, batch, v2 and later) except the visitor's own cart, checkout and order
- [x] T35 {added} `Bot_Verifier` uses the published address ranges of Google (four files) and Bing, downloaded once a day by wp-cron into one option; no DNS and no network on the way of a request; the first day or a failed download fails open (`unverified_bot`)
- [x] T36 {added} wc-api endpoint path: the name after `/wc-api/` must be non-empty after `sanitize_key`; `/wc-api/`, `/wc-api//` and `/wc-api/%20/` are page requests (403 in block)
- [x] T37 {added} Store API filter moved to `rest_request_after_callbacks` (inside `dispatch()`), so the answers WooCommerce Blocks gets from `rest_do_request` are covered as well as the server's own
- [x] T38 {added} Block-theme pages (twentytwentyfive): Store API data printed into the HTML does not pass any REST filter, so for a bad bot the page is cleaned on its way out (`Bot_Guard::start_buffer`/`clean_page`): `low_stock_remaining` becomes null and a stock-sized `add_to_cart.maximum` becomes 9999 in every mode; in substitute mode the minor-unit prices of every `prices` object (and price ranges) are replaced with the product's factor. Smoke on the real block theme looks for `4747`, `47.47` and the remaining stock in the whole product and shop page
- [x] T39 {added} No quantity leaves the Store API in any mode: `low_stock_remaining` and the stock-sized `add_to_cart.maximum`
- [ ] T16 {added} Level 2 route and `level2` state (stage 6)
- [ ] T17 {added} Mode screen in the dashboard `client`
- [ ] T18 {added} Server-side hits into the traffic report
