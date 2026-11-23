# Submitting SocialScore for WooCommerce to WordPress.org (board #13)

The submission itself is done by a person. This page lists what to do, in order, and what to expect.
Checked 23.11.2026 against the Plugin Handbook (Detailed Plugin Guidelines, Plugin Developer FAQ,
How Your Plugin Assets Work, How to Use Subversion) and the official Plugin Check 2.2.0.

## State of the plugin

- Official Plugin Check (`wp plugin check socialscore --include-experimental`, WordPress 7.1.5, WooCommerce 11.3.1,
  plugin built with `npm run dist`): **0 errors, 0 warnings**, before and after the changes in this branch.
- Guidelines reviewed by hand (1 GPL, 4 readable code, 5/6 no trialware and service documented, 7 consent and external
  data, 8 outside code, 10 credits, 11 admin notices, 13 default libraries, 17 trademarks). What changed:
  - `assets/pixel/loader.min.js` (minified, no source) became the readable `assets/pixel/loader.js`.
  - `readme.txt` documents the daily download of the Google, Bing and Cloudflare address ranges (it was not listed
    under "External services"), links the public source repository. The `Requires Plugins: woocommerce` header was already in `socialscore.php` (WordPress.org reads dependencies from there).
  - The range downloads now send the user agent `SocialScore/<version>`; WordPress's default contains the site address.
  - `docs/` is no longer shipped in the zip (`.distignore`).
- Known failure, not ours: the smoke test `tests/smoke/bots.php` fails on `main` too (HTTP 400 instead of 403 for a core
  batch with a cart add-item), so the smoke run stops there and `flow-end.php` is not reached in CI. It blocks the next
  release workflow (the smoke runs before a release) until fixed.
- Not changed on purpose: the version (0.2.0 in `socialscore.php`, `readme.txt`, `package.json`), so merging does not
  start a GitHub release. `Tested up to: 7.1` is right: 7.1.5 is the current stable release (7.2 is still RC).

## Before you submit

1. **WordPress.org account.** Log in with the account that will own the plugin. Add `plugins@wordpress.org` to the
   mail allow-list; every answer comes from that address (look in spam too).
2. **`Contributors:` in `readme.txt`** must be real WordPress.org usernames. It says `socialscore`. Make sure that
   account exists (or put the real usernames in, comma separated) before submitting. This could not be checked from here.
3. **Slug.** WordPress.org takes the slug from `Plugin Name` ("SocialScore for WooCommerce"), so the automatic slug is
   `socialscore-for-woocommerce`. The text domain in the code is `socialscore` and the folder is `socialscore`; the
   Plugin Handbook says the text domain is the slug. Two ways, pick one:
   - on the submission page change the slug once to `socialscore` (the FAQ allows one change after submitting), or
   - keep the automatic slug and change the text domain (`'socialscore'` in every `__()`, `esc_html__()` etc.,
     the `Text Domain:` header, and the tests), which is a code change and a new review round.
   The first is much cheaper. Both names were not found on WordPress.org on 23.11.2026 (API `plugins/info/1.0`), but
   that does not prove they are free: the system tells you on submission.
4. **Build the zip from `main` after this branch is merged.** The GitHub release `v0.2.0` and the "latest" link were
   built before these fixes, so do not upload them. Either download the `socialscore-0.2.0` artifact of the release
   workflow run on the merge commit (kept 30 days), or build it yourself:

   ```
   npm ci && npm run dist && (cd dist && zip -qr ../socialscore.zip socialscore)
   ```

   The zip is about 0.55 MB, 170 files, one top folder `socialscore/`, limit is 10 MB. Install it once with
   WordPress's "Upload Plugin" on a test site before sending it.

## Submit

1. Open https://wordpress.org/plugins/developers/add/ (logged in), upload `socialscore.zip`, tick what the form asks
   (the guidelines confirmation), submit. You get an automatic email with the slug. Fix the slug now (see above).
2. The review starts from the queue. A small plugin with correct code is normally approved within 14 days of the
   first review; the first reply usually comes within 10 business days. Follow-ups: reply to the same email
   thread, not a new mail. Do not re-submit when you get a "Review in Progress" mail: fix the points and reply (the
   zip can be replaced on the submission page).
3. What the reviewers will look at, and what we already prepared:
   - **External services.** The review mail will ask for every external call to be in `readme.txt`. It lists
     `socialscore.io` (API), `pixel.socialscore.io` (Pixel script, only after Connect and Pixel on), and the Google /
     Bing / Cloudflare range files, each with terms and privacy links. The Pixel script is loaded from the service's
     own domain: guideline 8 allows it for a documented service. Expect questions here; answer from the readme.
   - **Minified files.** `build/*.js` is built from `src/js` (included in the zip); `vendor-js/*` are our own GPL
     packages, built, with the source in the source maps and the public repository
     https://github.com/social-score/woocommerce-plugin named in the readme FAQ. If the reviewer wants readable files
     instead, the answer is to extract `sourcesContent` of `vendor-js/*/dist/index.js.map` into the zip.
   - **Remote data (guideline 7/8).** The range files are plain lists read by the server once a day, only while the
     store is connected; the one `phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent` in
     `includes/class-bot-verifier.php` explains it. If the reviewer objects, the daily job can be removed and
     declared bots left as "unverified".
   - **Tracking consent.** Nothing is sent before an administrator presses Connect; the Pixel is off until switched on.
4. You may be asked to change things and re-upload. Each mail says what. Never answer by removing the plugin and
   submitting again.

## After approval (SVN)

The approval mail gives the SVN address `https://plugins.svn.wordpress.org/<slug>`. Use your WordPress.org
username (case sensitive) and your password; if the commit says "Access forbidden", add
`--username` and `--password` to `svn ci`. (Whether this account needs a separate SVN password is not checked here.)

```
svn co https://plugins.svn.wordpress.org/<slug> wporg-svn
# put the content of dist/socialscore/ (the files, not the folder) into trunk/ - socialscore.php must sit directly in trunk/
rsync -a --delete dist/socialscore/ wporg-svn/trunk/
svn add --force wporg-svn/trunk/*
svn ci -m "Add SocialScore for WooCommerce 0.2.0" wporg-svn
svn cp wporg-svn/trunk wporg-svn/tags/0.2.0
svn ci -m "Tag 0.2.0" wporg-svn
```

`Stable tag` in `trunk/readme.txt` must equal the tagged version (0.2.0). Later releases: raise the version in the four
places (see Serena memory `woocommerce-plugin-release`), copy to `trunk`, `svn cp` a new tag, change `Stable tag`.
Do not commit often: every commit rebuilds the zip (guideline 14). The page shows up in search in 6 to 14 days.

## Banners, icons, screenshots

None exist in the repository (the `assets/` folder is the plugin's own `assets/pixel/loader.js`, not WordPress.org
assets). They are not needed to submit or to be approved; without an icon WordPress.org makes a generated one.
Make them before or soon after the first commit. They go in `assets/` at the SVN root (next to `trunk/`), not in
`trunk/`, and are added with `svn add` + `svn ci`:

| File | Size | Needed |
| --- | --- | --- |
| `icon-128x128.png` and `icon-256x256.png` (or `icon.svg` plus a PNG fallback) | 128 and 256 px square, up to 1 MB | recommended |
| `banner-772x250.png` | 772 x 250 | recommended |
| `banner-1544x500.png` | 1544 x 500 (high-DPI) | recommended |
| `banner-772x250-rtl.png`, `banner-1544x500-rtl.png` | same | optional |
| `screenshot-1.png`, `screenshot-2.png` ... | any size, up to 10 MB each | optional |

Screenshots need captions in `readme.txt` in a `== Screenshots ==` section (`1. Dashboard ...`); the section is not in
the readme now because there are no files yet. Suggested shots: the SocialScore dashboard, the Check panel on an order,
the Reports page, the Connect screen.
