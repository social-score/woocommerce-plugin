import type { ButtonHTMLAttributes, ReactElement } from 'react';
/**
 * `contained-inverse` is the dark button. `link` is an underlined text button that
 * sizes to its text; the caller sets its colour and size with classes.
 */
export type ButtonVariant = 'contained-primary' | 'outlined-primary' | 'contained-inverse' | 'link';
/** Like a native button, `type` is left to the browser (submit inside a form). */
export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
}
export declare function Button({ variant, className, disabled, children, ...rest }: ButtonProps): ReactElement;
