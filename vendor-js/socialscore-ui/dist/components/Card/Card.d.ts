import type { ReactElement, ReactNode } from 'react';
export interface CardProps {
    title?: ReactNode;
    /** Level of the title heading. Pick the one that fits the page outline. */
    headingLevel?: 2 | 3 | 4 | 5;
    /**
     * BRD 7.2: a box with no data does not render. `false` hides the box
     * (for example `when={items.length > 0}`).
     */
    when?: boolean;
    /**
     * Also hide the box when `children` hold nothing to show (null, false, '' or an
     * empty list). Pass `false` for a box that must stay visible.
     */
    hideWhenEmpty?: boolean;
    children?: ReactNode;
    className?: string;
}
export declare function Card({ title, headingLevel, when, hideWhenEmpty, children, className, }: CardProps): ReactElement | null;
