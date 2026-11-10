import type { ReactElement, ReactNode } from 'react';
export interface PageHeaderProps {
    /** The page's only `h1`. */
    title: ReactNode;
    /** One line under the title. */
    description?: ReactNode;
    /** Above the title, for example links back to the parent page. */
    breadcrumb?: ReactNode;
    /** Right of the title on wide screens, under it on narrow ones. */
    actions?: ReactNode;
    className?: string;
}
export declare function PageHeader({ title, description, breadcrumb, actions, className, }: PageHeaderProps): ReactElement;
