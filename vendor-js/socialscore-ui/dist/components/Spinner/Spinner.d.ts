import type { ReactElement } from 'react';
export interface SpinnerProps {
    /** Text announced to screen readers. */
    label?: string;
    className?: string;
}
/**
 * The rotation is SVG animation (SMIL), not a CSS keyframe: the package CSS
 * allows only `.ss-*` selectors and a keyframe adds selectors of its own.
 */
export declare function Spinner({ label, className }: SpinnerProps): ReactElement;
