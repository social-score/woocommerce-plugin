# @socialscore/ui

Design tokens and React components shared by the SocialScore client (this repo) and
the e-commerce plugins (WordPress admin, Shopify admin). It is an npm workspace inside
the `client` repo.

```
packages/ui/
  src/tokens.css        all token values, as --ss-* CSS custom properties (the source of truth)
  src/theme.css         Tailwind 4 @theme that maps the tokens onto utilities
  src/components.css    component styles (.ss-* class selectors only)
  src/styles.css        tokens.css + components.css, for consumers without Tailwind
  src/tokens.ts         palette names and runtime readers for JS (charts)
  src/components/       React components, each with a story and a test
  smoke/                a stand-in plugin that installs the packed tarball
```

## Using it

### In the client app

The app builds the package from source; there is no package build step in dev, tests
or CI. `vite.config.ts` aliases `@socialscore/ui` to `packages/ui/src`, and a
`tsconfig.json` `paths` entry does the same for TypeScript. The published `exports`
point at `dist/` only.
`src/index.css` loads it after Tailwind:

```css
@import 'tailwindcss';
@import '@socialscore/ui/tokens.css';
@import '@socialscore/ui/theme.css';
@import '@socialscore/ui/components.css' layer(components);
```

Putting the component CSS in the `components` layer lets page utilities win, so
`<Button className="h-12">` works.

### In a plugin

Run `npm run build -w @socialscore/ui` (output in `dist/`: ESM JS, `.d.ts`, CSS).
`react` and `react-dom` are peer dependencies: the plugin supplies them (React from
`wp-element` in WordPress, the app's React in Shopify).

```js
import { Button } from '@socialscore/ui';
import '@socialscore/ui/styles.css'; // tokens + component styles
```

A plugin that uses Tailwind 4 can import `tokens.css` and `theme.css` the way the client
does instead.

`npm run test:smoke` (repo root) builds the package, packs it with `npm pack`, installs
the tarball into a fixture in a temp directory outside the repo, type-checks the fixture
with `moduleResolution` `bundler` and `nodenext`, and runs `smoke/plugin.smoke.test.tsx`
there. CI runs it. The tarball holds `dist/` only; relative imports in `src/` carry `.js`
so the emitted `.d.ts` files resolve under `nodenext`.

## No preflight, no element selectors

The package CSS loads inside host admin pages. It must not restyle them, so:

- no reset or preflight, and no `@import 'tailwindcss'`;
- no element selectors (`button`, `h1`, `*`, `body`). Rules target `.ss-*` classes only;
  `tokens.css` sets custom properties on `:root`, all prefixed `--ss-`;
- components set the properties they rely on (font, border, background), because the
  host's own reset is unknown.

The client keeps its own preflight from Tailwind. `src/tokens.test.ts` and the smoke
test fail if a selector other than `:root` or `.ss-*` appears.

## Tokens

Two levels. The **palette** holds raw values taken from the 2023 design (the old
`ThemeColor` enum and the BRD mockups). **Semantic** tokens say what a colour is for;
pages and components use them. Data-viz series may use the palette directly.

### Palette (`--ss-color-*`, Tailwind `bg-violet-400`, `fill-blue-500`, …)

| Scale  | Shades                                                                                 |
| ------ | -------------------------------------------------------------------------------------- |
| white, black | `#ffffff`, `#000000`                                                             |
| gray   | 50 `#f8f8f8` · 100 `#f1f1f1` · 200 `#e0e0e0` · 300 `#d4d4d4` · 400 `#bbbbbb` · 500 `#686e79`* |
| navy   | 500 `#595b7a` · 700 `#414367` · 900 `#111441`                                         |
| blue   | 50 `#e6f6ff` · 100 `#dbf0ff` · 500 `#0d99ff` · 700 `#0271c0`* · 800 `#0061af`*          |
| violet | 200 `#cfbefb` · 300 `#a794e2` · 400 `#9780f5` · 500 `#836ee1` · 600 `#755bcc`* · 700 `#63549c` |
| teal   | 200 `#89dbdb` · 400 `#2bc7c8` · 700 `#298181`                                         |
| sage   | 300 `#99bfbe` · 500 `#77aaa9`                                                         |
| red    | 500 `#fe504f` · 600 `#d5222d`*                                                        |
| orange | 400 `#ff9841` · 700 `#a95900`*                                                        |

\* added for WCAG AA: the original shade with OKLCH lightness lowered until text
reaches 4.5:1, hue and chroma kept.

### Semantic colours

| Role | Token (`--ss-color-…`) | Tailwind | Value |
| --- | --- | --- | --- |
| Page background | `surface-page` | `bg-page` | gray-100 |
| Card / panel | `surface`, `surface-subtle`, `surface-muted` | `bg-surface…` | white, gray-50, gray-100 |
| Chips, controls, code | `surface-strong`, `surface-emphasis` | `bg-surface-strong/-emphasis` | gray-300, gray-400 |
| Sidebar, footer | `surface-inverse`, `surface-inverse-subtle` | `bg-inverse…` | navy-900, navy-700 |
| Accent (icons, borders, bars) | `accent`, `accent-subtle`, `accent-faint` | `bg-accent…` | blue-500, blue-100, blue-50 |
| Filled control with text | `action`, `action-hover` | `bg-action…` | blue-700, blue-800 |
| Disabled control | `disabled` | `bg-disabled` | gray-400 |
| Text | `text-primary`, `-secondary`, `-muted` | `text-primary…` | navy-900, navy-700, navy-500 |
| Text on dark / on action | `text-inverse`, `text-on-action` | `text-inverse`, `text-on-action` | white |
| Links | `text-link` | `text-link` | blue-700 |
| Highlighted text | `text-highlight` | `text-highlight` | violet-600 |
| Placeholder | `text-placeholder` | `text-placeholder` | gray-500 |
| Borders | `border`, `-control`, `-strong`, `-selected`, `-inverse`, `-accent`, `-highlight` | `border-default…` | gray-200, gray-300, gray-400, navy-900, white, blue-500, violet-400 |
| Success / warning / error | `success`, `warning`, `error` (+ `-text`) | `bg-/border-error`, `text-error` | teal-400 / orange-400 / red-500; text teal-700 / orange-700 / red-600 |
| Levels Strong / Medium / Weak | `level-strong`, `-medium`, `-weak` (+ `-text`) | `bg-level-…`, `text-level-…` | violet-400 / orange-400 / red-500; text violet-600 / orange-700 / red-600 |
| Locked section | `locked-surface`, `-border`, `-text`, `-overlay` | `bg-locked`, `border-locked`, `text-locked` | gray-50, gray-300, navy-500, navy-900 at 70 % |
| Focus ring | `focus-ring` (+ `--ss-focus-ring-width/-offset`) | `border-focus` | blue-700, 2px, 2px |

The levels follow the score bars the app already had (high violet, middle orange, low
red). The locked section has no mockup yet; its tokens reuse existing values and wait
for design.

### Contrast

`npm run contrast -w @socialscore/ui` prints every text/surface pair with its ratio,
and `npm test` fails if a pair drops below AA (4.5:1 for text, 3:1 for the focus ring).
The pairs are listed in `src/contrast.test.ts`.

### Typography

Font: `--ss-font-sans` (Inter). Sizes are named by role. The old names were misleading
(`text-sm` was 16px, `text-base` 18px); the values did not change.

| Tailwind | Token | Size | Old class |
| --- | --- | --- | --- |
| `text-micro` | `--ss-font-size-micro` | 0.625rem (10px) | `text-xxs` |
| `text-caption` | `--ss-font-size-caption` | 0.875rem (14px) | `text-xs` |
| `text-body` | `--ss-font-size-body` | 1rem (16px) | `text-sm` |
| `text-body-lg` | `--ss-font-size-body-lg` | 1.125rem (18px) | `text-base` |
| `text-subtitle` | `--ss-font-size-subtitle` | 1.25rem (20px) | `text-lg` |
| `text-title-sm` | `--ss-font-size-title-sm` | 1.3rem (20.8px) | `text-xl` |
| `text-title` | `--ss-font-size-title` | 1.5rem (24px) | `text-2xl` |
| `text-title-lg` | `--ss-font-size-title-lg` | 1.75rem (28px) | `text-3xl` |
| `text-display-sm` | `--ss-font-size-display-sm` | 2rem (32px) | `text-4xl` |
| `text-display` | `--ss-font-size-display` | 2.25rem (36px) | `text-5xl` |
| `text-display-lg` | `--ss-font-size-display-lg` | 2.625rem (42px) | `text-6xl` |

Weights: `--ss-font-weight-thin` … `-black` (100–900), as Tailwind's `font-*`.

### Spacing, radii, shadows, z-index, breakpoints

| Group | Tokens | Tailwind |
| --- | --- | --- |
| Spacing | `--ss-space-unit` 0.25rem; every step is a multiple | `p-4`, `gap-2`, `h-10` (n × unit) |
| Radii | `--ss-radius-xs` 0.125 · `sm` 0.25 · `md` 0.375 · `lg` 0.5 · `xl` 0.75 · `2xl` 1 · `3xl` 1.5rem · `full` | `rounded-*` |
| Shadows | `--ss-shadow-md`, `-lg`, `-all` (even glow) | `shadow-md`, `shadow-lg`, `shadow-all` |
| Z-index | `--ss-z-base` 0 · `raised` 10 · `dropdown` 20 · `sticky` 30 · `overlay` 40 · `modal` 50 · `toast` 60 | `z-dropdown`, … |
| Breakpoints | `--ss-breakpoint-sm` 40 · `md` 48 · `lg` 64 · `xl` 80 · `2xl` 96rem (Tailwind defaults) | `sm:` … `2xl:` |

Breakpoints are repeated as literals in `theme.css`, because media queries cannot read
custom properties; keep the two in sync. `theme.css` resets each Tailwind namespace, so
a class outside the tokens (`text-sm`, `bg-red-600`, `rounded-4xl`) generates nothing.

### Tokens in JavaScript

Libraries that need a concrete colour string (ApexCharts) read the token at render
time:

```ts
import { paletteValue, type PaletteColor } from '@socialscore/ui';

const fill: PaletteColor = 'violet-400';
const options = { colors: [paletteValue(fill)] }; // '#9780f5'
```

Call it while rendering, not at module load: the stylesheet may not be applied yet.
`src/tokens.test.ts` checks that the `PaletteColor` list matches `tokens.css`.

## Scripts

| Command (repo root) | What it does |
| --- | --- |
| `npm run build -w @socialscore/ui` | Vite library build + `.d.ts` into `dist/` |
| `npm test` | app tests, then package tests (components, tokens, contrast) |
| `npm run test:smoke` | build and pack the package, install the tarball into a plugin fixture, type-check and test it |
| `npm run contrast -w @socialscore/ui` | print the contrast table |

## Data display components

| Component | Notes |
| --- | --- |
| `Table` | `columns` + `rows`; sorting (`aria-sort`, header buttons), `filters`, `groupBy`, pagination, `empty` slot. Client side by default; `sort` + `manualSort` and `totalRecords` + `page` for server side. Every column can `render` its cell. |
| `Pagination` | Navigation landmark, `aria-current` on the page, three-page window. `Table` uses it when `pageSize` is set. |
| `Card` | A box that does not render without data: `when={false}`, or empty `children` (`hideWhenEmpty={false}` keeps it). BRD 7.2. |
| `EmptyState` | Title, description, icon and action slots. Also fits `Table`'s `empty`. |
| `LockedSection` | Section gated by plan: what it does, `requiredPlan`, upgrade button (`onUpgrade`) or link (`upgradeHref`), optional blurred `preview` that is inert. Uses the `locked-*` tokens, which still wait for design. |
| `LevelIndicator` | Strong / Medium / Weak bar. `thresholds` default to `[40, 70]` (decided by the owner on 10.10.2026: Strong 70-100, Medium 40-69, Weak 0-39); the default lives in `DEFAULT_LEVEL_THRESHOLDS` in `levels.ts`. `inverted` for lower-is-better scores; `level` for a fixed colour. |
| `ChartFrame` | Title, `Source:` line and loading / error (with retry) / empty states around any chart. ApexCharts stays an app dependency; the package takes `children`. |
| `Spinner` | Status spinner. The rotation is SVG animation because the package CSS allows no `@keyframes`. |

Tooltips are not in the package yet (part A); cells use plain text.
