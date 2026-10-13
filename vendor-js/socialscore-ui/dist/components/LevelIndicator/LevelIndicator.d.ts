import type { ReactElement, ReactNode } from 'react';
import type { Level, LevelThresholds } from './levels.js';
export interface LevelIndicatorProps {
    /** 0 to 100. Values outside are clamped. */
    value: number;
    thresholds?: LevelThresholds;
    /** Lower is better. */
    inverted?: boolean;
    /**
     * Fixed colour. Use it for a plain progress bar that is not a score: the level name
     * is then not announced.
     */
    level?: Level;
    /** Text before the bar; also the accessible name. */
    label?: string;
    /** Content after the bar, for example the number. */
    valueLabel?: ReactNode;
    /** Show the level name (Strong / Medium / Weak) as text, so colour is not the only cue. */
    showLevelName?: boolean;
    levelNames?: Record<Level, string>;
    className?: string;
}
export declare function LevelIndicator({ value, thresholds, inverted, level: fixedLevel, label, valueLabel, showLevelName, levelNames, className, }: LevelIndicatorProps): ReactElement;
