# Test plan: WordPress.org readiness

Result: PASS 23.11.2026

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
| smoke | `docker compose -f tests/smoke/docker-compose.yml run --rm smoke` after `npm run dist` | with the smoke fix of `fix/smoke-core-batch` (WooCommerce 11.3.1 refuses Store API calls in core `/batch/v1`) the whole run passes, including `flow-end.php` and its Plugin Check step; without that fix `bots.php` fails the same way on `main` |

The loader change is covered by `PixelTest` (placeholders, validation, site id and source in the output) and by `tests/smoke/pixel.php`.
There is no test that fails without the change in the sense of a bug fix: the change is a file format and readme change.
