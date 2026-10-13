import type { ReactElement } from 'react';
export interface PaginationProps {
    /** 1-based. */
    currentPage: number;
    totalRecords: number;
    pageSize: number;
    onPageChange: (page: number) => void;
    /** Accessible name of the navigation landmark. */
    label?: string;
    className?: string;
}
export declare function Pagination({ currentPage, totalRecords, pageSize, onPageChange, label, className, }: PaginationProps): ReactElement;
