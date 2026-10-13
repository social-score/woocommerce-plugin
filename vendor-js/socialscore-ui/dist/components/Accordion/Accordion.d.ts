import type { ReactElement, ReactNode } from 'react';
export interface AccordionProps {
    title: ReactNode;
    /** Level of the title heading. Pick the one that fits the page outline. */
    headingLevel?: 2 | 3 | 4 | 5;
    /** The text. A few lines show while it is collapsed. */
    children: ReactNode;
    collapsedLabel?: string;
    expandedLabel?: string;
    defaultExpanded?: boolean;
    className?: string;
}
/** A title and a short text; the toggle button reveals the rest of the text. */
export declare function Accordion({ title, headingLevel, children, collapsedLabel, expandedLabel, defaultExpanded, className, }: AccordionProps): ReactElement;
