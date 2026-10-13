/**
 * JS access to the tokens in tokens.css. The CSS file holds the values; this module
 * only names them (a test checks the two lists match) and reads them at runtime.
 * Use it where a library needs a concrete colour string instead of a CSS class,
 * such as ApexCharts.
 */
export declare const paletteColors: readonly ["white", "black", "gray-50", "gray-100", "gray-200", "gray-300", "gray-400", "gray-500", "navy-200", "navy-500", "navy-700", "navy-900", "blue-50", "blue-100", "blue-500", "blue-700", "blue-800", "violet-200", "violet-300", "violet-400", "violet-500", "violet-600", "violet-700", "teal-200", "teal-400", "teal-700", "sage-300", "sage-500", "red-500", "red-600", "orange-400", "orange-700"];
export type PaletteColor = (typeof paletteColors)[number];
/** `var(--ss-color-violet-400)`, for inline styles and SVG `style` props. */
export declare function paletteVar(name: PaletteColor): string;
/**
 * The computed value of a token, e.g. `tokenValue('--ss-color-violet-400')` → `#9780f5`.
 * Call it while rendering, not at module load: the stylesheet may not be applied yet.
 * Returns '' when tokens.css is not loaded (for example under jsdom).
 */
export declare function tokenValue(name: `--ss-${string}`, element?: Element): string;
/** Shorthand for `tokenValue('--ss-color-<name>')`. */
export declare function paletteValue(name: PaletteColor, element?: Element): string;
