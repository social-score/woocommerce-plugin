import type { ReactElement, ReactNode } from 'react';
import type { PaletteColor } from '../../tokens.js';
/** Which text colour reaches WCAG AA on a tile: `primary` on light colours, `inverse` on dark. */
export type LabeledStackTone = 'primary' | 'inverse';
export interface LabeledStackProps {
    /** Percentages, one per tile. */
    values: number[];
    /** Palette colour of each tile, in the order of `values`. */
    colors: PaletteColor[];
    /** Text colour of each tile; pick the one that has AA contrast on that tile's colour. */
    tones: LabeledStackTone[];
    /**
     * Replaces the default "61%" for a tile. The tile sets the text colour, so the node
     * inherits it and needs no colour of its own.
     */
    renderValueFns?: ((value: number) => ReactNode)[];
    className?: string;
}
/** Coloured tiles in a column, each with its number; the key to a multi-ring chart. */
export declare function LabeledStack({ values, colors, tones, renderValueFns, className, }: LabeledStackProps): ReactElement;
