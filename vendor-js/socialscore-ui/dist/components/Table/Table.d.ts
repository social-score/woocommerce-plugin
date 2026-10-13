import type { Key, ReactElement, ReactNode } from 'react';
export type SortDirection = 'asc' | 'desc';
export interface SortState {
    key: string;
    direction: SortDirection;
}
export interface TableColumn<Row> {
    /** Unique id; also the default property read from the row. */
    key: string;
    header: ReactNode;
    /** Cell value for the default render, sort and filter. Defaults to `row[key]`. */
    value?: (row: Row) => unknown;
    /** Replaces the default cell content. */
    render?: (row: Row, rowIndex: number) => ReactNode;
    sortable?: boolean;
    /** Replaces the default comparison (numbers numerically, text by locale). */
    compare?: (a: Row, b: Row) => number;
    /** Replaces the default filter (case-insensitive "contains" on the cell value). */
    filter?: (row: Row, filterValue: string) => boolean;
    align?: 'start' | 'center' | 'end';
}
export type TableStripes = 'surface-first' | 'subtle-first' | 'none';
export interface TableProps<Row> {
    columns: TableColumn<Row>[];
    rows: Row[];
    /** Stable key per row. Defaults to the row index. */
    getRowKey?: (row: Row, index: number) => Key;
    /** Names the table for screen readers (rendered as a visually hidden caption). */
    caption: string;
    /** Controlled sort. `null` means unsorted. Leave out for an uncontrolled table. */
    sort?: SortState | null;
    defaultSort?: SortState | null;
    /** Gets the next state and the clicked column key. Clicking cycles asc, desc, unsorted. */
    onSortChange?: (next: SortState | null, columnKey: string) => void;
    /** Rows arrive already sorted (server side): the header still shows and reports the sort. */
    manualSort?: boolean;
    /** Column key to filter text. Filtering is local to the rows given. */
    filters?: Record<string, string>;
    /** Rows with the same group value are shown together under a heading row. */
    groupBy?: (row: Row) => string;
    renderGroupHeader?: (group: string, rows: Row[]) => ReactNode;
    /** Pagination shows when `pageSize` is set, even for 0 rows. 1-based. */
    pageSize?: number;
    page?: number;
    defaultPage?: number;
    onPageChange?: (page: number) => void;
    /** Set when the rows are one server-side page: no local slicing, this is the full count. */
    totalRecords?: number;
    /** Shown instead of the rows when there are none. */
    empty?: ReactNode;
    fixed?: boolean;
    stripes?: TableStripes;
    className?: string;
}
/**
 * Headless-style table: describe `columns`, pass `rows`, and the table sorts, filters,
 * groups and paginates them. Every column can replace its cell with `render`.
 */
export declare function Table<Row>({ columns, rows, getRowKey, caption, sort, defaultSort, onSortChange, manualSort, filters, groupBy, renderGroupHeader, pageSize, page, defaultPage, onPageChange, totalRecords, empty, fixed, stripes, className, }: TableProps<Row>): ReactElement;
