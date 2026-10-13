import type { HTMLAttributes, ReactNode } from 'react';
export type BadgeVariant = 'neutral' | 'success' | 'warning' | 'error' | 'strong' | 'medium' | 'weak';
export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
    /** `strong`, `medium` and `weak` are the signal levels from BRD 7.1. */
    variant?: BadgeVariant;
    /** Defaults to "Strong", "Medium" or "Weak" for the level variants. */
    children?: ReactNode;
    /** Adds a remove button. Clicking it does not reach the badge's own `onClick`. */
    onRemove?: () => void;
    /** Accessible name of the remove button, e.g. "Remove Country: Germany". */
    removeLabel?: string;
}
/** Colour is never the only signal: level badges carry their word, the dot is decoration. */
export declare function Badge({ variant, className, children, onRemove, removeLabel, ...rest }: BadgeProps): import("react/jsx-runtime").JSX.Element;
