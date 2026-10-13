import type { ReactElement, ReactNode } from 'react';
export interface LockedSectionProps {
    /** What the section does, in a few words. */
    title: ReactNode;
    /** What the customer gets by unlocking it. */
    description?: ReactNode;
    /** Plan that includes the section, for example "Pro". */
    requiredPlan?: string;
    upgradeLabel?: string;
    /** Upgrade as a link. Use `onUpgrade` for an in-page action. One of the two is needed. */
    upgradeHref?: string;
    onUpgrade?: () => void;
    /**
     * Blurred sample of the locked content. It is hidden from screen readers and from the
     * keyboard, and nothing in it can be used.
     */
    preview?: ReactNode;
    headingLevel?: 2 | 3 | 4 | 5;
    className?: string;
}
export declare function LockedSection({ title, description, requiredPlan, upgradeLabel, upgradeHref, onUpgrade, preview, headingLevel, className, }: LockedSectionProps): ReactElement;
