import type { ComponentType, ReactElement, SVGProps } from 'react';
import type { PaletteColor } from '../../tokens.js';
export type ColoredIconSize = 'sm' | 'md' | 'lg';
export interface ColoredIconProps {
    /** An SVG icon component. It is decoration: put the meaning in the text next to it. */
    icon: ComponentType<SVGProps<SVGSVGElement>>;
    /** 40px, 48px or 64px circle. */
    size?: ColoredIconSize;
    /** Palette colour of the circle. The icon is drawn in the inverse text colour. */
    color?: PaletteColor;
    className?: string;
}
/** An icon on a filled circle, the marker for a row of information or a form heading. */
export declare function ColoredIcon({ icon: Icon, size, color, className, }: ColoredIconProps): ReactElement;
