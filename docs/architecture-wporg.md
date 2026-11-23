# WordPress.org readiness (board #13)

Goal: the plugin passes the WordPress.org review the first time. Submission is done by a person: `docs/wporg-submission.md`.

## Findings

- Plugin Check 2.2.0 (WordPress 7.1.5, WooCommerce 11.3.1, release layout from `npm run dist`): no errors or warnings on `main`.
- Guideline 4 (readable code): `assets/pixel/loader.min.js` was minified with no source.
- Guidelines 6, 7, 8 (documented external services): the daily download of Google, Bing and Cloudflare address ranges
  (`Bot_Verifier::refresh`) was not in `readme.txt`.
- Readme: no `Requires Plugins` header, no link to the public source repository.
- `docs/` (internal) shipped inside the zip.

## Decisions

- The version is not raised (a version bump on `main` publishes a release).
- The loader keeps its behaviour; only the formatting changed, so the tests that matched the exact minified text now match a regex.
- The range downloads stay (they are documented, not removed); removal is the fallback if a reviewer objects.
- Slug and text domain: left to the human at submission (`docs/wporg-submission.md`), because the slug can be changed once there.
- Vendored packages keep their source in the source maps; extracting it into the zip is the fallback if a reviewer asks.
- Not fixed, out of scope: the smoke test `bots.php` fails on `main` too with current WordPress/WooCommerce images (see test plan).

## Tasks

- [x] T1 Run Plugin Check before and after
- [x] T2 Review the guidelines by hand
- [x] T3 Replace the minified loader with `assets/pixel/loader.js`
- [x] T4 Document the range downloads, source link and `Requires Plugins` in `readme.txt`
- [x] T5 Keep `docs/` out of the zip
- [x] T6 Write `docs/wporg-submission.md`
- [x] T7 Run the repository's tests and linters
