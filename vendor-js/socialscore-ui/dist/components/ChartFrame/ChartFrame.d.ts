import type { ReactElement, ReactNode } from 'react';
export interface ChartFrameProps {
    title?: ReactNode;
    headingLevel?: 2 | 3 | 4 | 5;
    /** Where the numbers come from; shown as "Source: ...". */
    source?: string;
    loading?: boolean;
    /** Error text or node. Takes the place of the chart (unless `loading`). */
    error?: ReactNode;
    /** Shows a Retry button next to the error. */
    onRetry?: () => void;
    /** No data to plot. Shown instead of the chart. */
    empty?: boolean;
    /** Replaces the default empty message. */
    emptyState?: ReactNode;
    loadingLabel?: string;
    /** Minimum height of the chart area, so the page does not jump between states. */
    minHeight?: number | string;
    /** Text summary of the chart for screen readers; SVG charts are otherwise silent. */
    description?: string;
    /** The chart itself, for example an ApexCharts element. The package has no chart library. */
    children?: ReactNode;
    className?: string;
}
export declare function ChartFrame({ title, headingLevel, source, loading, error, onRetry, empty, emptyState, loadingLabel, minHeight, description, children, className, }: ChartFrameProps): ReactElement;
