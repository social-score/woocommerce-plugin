import type { InputHTMLAttributes, ReactNode } from 'react';
export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> {
    /** Text next to the box. Without it, give the input an `aria-label`. */
    label?: ReactNode;
    hint?: ReactNode;
    /** Error message. Sets `aria-invalid` and is announced. */
    error?: ReactNode;
    /** Goes on the wrapper, so a page can set spacing or width. */
    className?: string;
}
/** A native checkbox, so Space, Tab, form submit and screen readers work as they do everywhere. */
export declare const Checkbox: import("react").ForwardRefExoticComponent<CheckboxProps & import("react").RefAttributes<HTMLInputElement>>;
