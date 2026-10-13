import type { ReactNode } from 'react';
export type SelectSize = 'sm' | 'md';
export type SelectOption = string | {
    value: string;
    label?: ReactNode;
    disabled?: boolean;
};
export interface SelectProps {
    label?: ReactNode;
    /** A string selects one option. An array turns on multiple mode: `onChange` gets the clicked option, the page toggles it, and the list stays open. */
    value?: string | string[] | null;
    options?: SelectOption[] | null;
    onChange: (value: string) => void;
    /** Shown in the open list when there are no options (e.g. while they load). */
    emptyText?: string;
    /** Shown while `value` matches no option. */
    placeholder?: string;
    hint?: ReactNode;
    error?: ReactNode;
    required?: boolean;
    disabled?: boolean;
    /** `sm` is 40px high, `md` (default) 48px. */
    size?: SelectSize;
    /** Label for an option given as a plain string (or as `{ value }` without a label). */
    getOptionLabel?: (value: string) => ReactNode;
    /** Replaces the text inside the closed control, e.g. to add an icon. */
    renderValue?: (value: string | string[]) => ReactNode;
    /** Adds a hidden input so the value submits with a form. */
    name?: string;
    id?: string;
    'aria-label'?: string;
    /** Goes on the wrapper, so a page can set the width. */
    className?: string;
}
/**
 * Accessible single select: a combobox (select-only pattern) with a listbox popup.
 * Keys: ArrowDown/ArrowUp open and move, Home/End jump, Enter or Space picks,
 * Escape closes, typing a letter jumps to the next option that starts with it.
 */
export declare function Select({ label, value: valueProp, options: optionsProp, onChange, placeholder, emptyText, hint, error, required, disabled, size, getOptionLabel, renderValue, name, id, 'aria-label': ariaLabel, className, }: SelectProps): import("react/jsx-runtime").JSX.Element;
