export type Level = 'strong' | 'medium' | 'weak';
/** Upper bounds of Weak and Medium: below the first is Weak, below the second Medium. */
export type LevelThresholds = readonly [number, number];
/**
 * Decided by the owner on 10.10.2026: Strong 70-100, Medium 40-69, Weak 0-39. The backend
 * filters use the same cut-offs. Pages can also pass `thresholds` per indicator.
 */
export declare const DEFAULT_LEVEL_THRESHOLDS: LevelThresholds;
export declare const DEFAULT_LEVEL_NAMES: Record<Level, string>;
/**
 * `inverted` is for scores where lower is better (fraud): a low value is Strong.
 */
export declare function getLevel(value: number, thresholds?: LevelThresholds, inverted?: boolean): Level;
