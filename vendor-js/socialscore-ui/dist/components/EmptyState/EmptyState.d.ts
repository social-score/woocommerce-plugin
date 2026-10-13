import type { ReactElement, ReactNode } from 'react';
export interface EmptyStateProps {
    title: ReactNode;
    description?: ReactNode;
    /** Decorative icon or illustration; hidden from screen readers. */
    icon?: ReactNode;
    /** Next step, for example a Button. */
    action?: ReactNode;
    headingLevel?: 2 | 3 | 4 | 5;
    className?: string;
}
export declare function EmptyState({ title, description, icon, action, headingLevel, className, }: EmptyStateProps): ReactElement;
