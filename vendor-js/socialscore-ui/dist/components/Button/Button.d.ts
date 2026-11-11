import type { ButtonHTMLAttributes, ReactElement } from 'react';
/**
 * `contained-inverse` is the dark button. `link` is an underlined text button that
 * sizes to its text; the caller sets its size with classes. `link-danger` is the same
 * in the error colour, for Delete, Remove and Cancel.
 */
export type ButtonVariant = 'contained-primary' | 'outlined-primary' | 'contained-inverse' | 'link' | 'link-danger';
/** Minimum height of a pill button: 32, 40 or 48px. Links are not sized. */
export type ButtonSize = 'sm' | 'md' | 'lg';
/** Like a native button, `type` is left to the browser (submit inside a form). */
export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Fill the container's width. By default a button is as wide as its label. */
    fullWidth?: boolean;
}
export declare function Button({ variant, size, fullWidth, className, disabled, children, ...rest }: ButtonProps): ReactElement;
