import type { ReactNode, TextareaHTMLAttributes } from 'react';
export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: ReactNode;
    /** Help text under the field. */
    hint?: ReactNode;
    /** Error message. Turns the field red, sets `aria-invalid` and is announced. */
    error?: ReactNode;
    /** Goes on the wrapper, so a page can set the width or spacing. */
    className?: string;
}
export declare const Textarea: import("react").ForwardRefExoticComponent<TextareaProps & import("react").RefAttributes<HTMLTextAreaElement>>;
