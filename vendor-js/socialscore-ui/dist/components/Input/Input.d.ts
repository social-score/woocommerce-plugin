import type { InputHTMLAttributes, ReactNode } from 'react';
export type InputSize = 'sm' | 'md';
export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> {
    label?: ReactNode;
    /** Help text under the field. */
    hint?: ReactNode;
    /** Error message. Turns the field red, sets `aria-invalid` and is announced. */
    error?: ReactNode;
    /** An icon at the right end of the field, for example a magnifier. Decoration: it ignores the pointer. */
    endAdornment?: ReactNode;
    /** `sm` is 40px high, `md` (default) 48px. */
    size?: InputSize;
    /** Goes on the wrapper, so a page can set the width. */
    className?: string;
}
export declare const Input: import("react").ForwardRefExoticComponent<InputProps & import("react").RefAttributes<HTMLInputElement>>;
