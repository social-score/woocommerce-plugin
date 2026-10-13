import type { ReactElement, ReactNode } from 'react';
export interface ContactBadgeProps {
    /** Usually a `ColoredIcon`. */
    icon: ReactNode;
    /** Bold first line, for example "Email". */
    label: ReactNode;
    /** Second line, for example the address. */
    text: ReactNode;
    className?: string;
}
/** An icon with a label and a value under it, centred; a way to reach somebody. */
export declare function ContactBadge({ icon, label, text, className }: ContactBadgeProps): ReactElement;
