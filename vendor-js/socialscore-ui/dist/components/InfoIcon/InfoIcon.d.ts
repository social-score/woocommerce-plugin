import type { ReactNode } from 'react';
type InfoField = 'source' | 'confidence' | 'reason' | 'date';
export interface InfoIconProps {
    /** Where the number or statement comes from. */
    source?: ReactNode;
    /** How sure we are, e.g. "High" or "82%". */
    confidence?: ReactNode;
    /** Why the value is what it is. */
    reason?: ReactNode;
    /** When it was measured. A `Date` is shown as YYYY-MM-DD. */
    date?: string | Date;
    /** Accessible name of the icon button. */
    label?: string;
    /** Row titles, to translate or reword. */
    labels?: Partial<Record<InfoField, string>>;
    className?: string;
}
/** The "i" icon from BRD 7.1: source, confidence, reason and date of a value, in a tooltip. */
export declare function InfoIcon({ source, confidence, reason, date, label, labels, className, }: InfoIconProps): import("react/jsx-runtime").JSX.Element;
export {};
