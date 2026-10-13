import type { InputHTMLAttributes, ReactNode } from 'react';
export interface SwitchProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size' | 'role'> {
    /** Text before the switch. Without it, give the input an `aria-label`. */
    label?: ReactNode;
    hint?: ReactNode;
    /** Goes on the wrapper, so a page can set spacing or width. */
    className?: string;
}
/** A native checkbox with `role="switch"`: Space toggles, screen readers say on/off. */
export declare const Switch: import("react").ForwardRefExoticComponent<SwitchProps & import("react").RefAttributes<HTMLInputElement>>;
