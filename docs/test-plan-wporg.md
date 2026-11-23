# Test plan: WordPress.org readiness

Result: PASS 23.11.2026 (one smoke failure that is also on `main`, see below)

| Check | Command | Result |
| --- | --- | --- |
| Plugin Check before (main) | `wp plugin check socialscore --include-experimental` in WordPress 7.1.5 + WooCommerce 11.3.1 | 0 errors, 0 warnings |
| Plugin Check after | same, on `dist/socialscore` of this branch | 0 errors, 0 warnings |
| Plugin Check works | same command on `hello.php` and `akismet` | reports errors, so an empty result is real |
| phpcs | `vendor/bin/phpcs` | exit 0 |
| phpunit | `vendor/bin/phpunit` | OK (381 tests, 2870 assertions) |
| typecheck | `npm run typecheck` | exit 0 |
| build | `npm run build` | compiled successfully |
| jest | `npm test` | 4 suites, 44 tests passed |
| unit test for the user agent | `phpunit --filter user_agent` | fails without the fix (null), passes with it |
| smoke | `docker compose -f tests/smoke/docker-compose.yml run --rm smoke` after `npm run dist` | flow, http, pixel pass (pixel: loader checks pass with the new loader); `bots.php` fails at "core batch with a cart add-item gives the bot a 403 inside" (HTTP 400); the same assertion fails on `origin/main` built the same way; `flow-end.php` is not reached |

The loader change is covered by `PixelTest` (placeholders, validation, site id and source in the output) and by `tests/smoke/pixel.php`.
There is no test that fails without the change in the sense of a bug fix: the change is a file format and readme change.
