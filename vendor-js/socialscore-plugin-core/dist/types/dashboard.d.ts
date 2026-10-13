import type { ConfidenceLevel } from './profile.js';
/** needs backend: every type in this file is a read the contract does not have yet. */
export interface RiskyOrder {
    externalId: string;
    placedAt?: string;
    total?: number;
    currency?: string;
    fraudScore?: number;
}
/** Aggregates only; the API applies the 5-person rule. */
export interface StoreOverview {
    checkedCustomers: number;
    checkedOrders: number;
    businessBuyers?: number;
    riskyOrders: RiskyOrder[];
}
export interface CheckedCustomer {
    externalId: string;
    /** Masked as the API returns it. */
    displayName?: string;
    email?: string;
    ordersCount?: number;
    healthLevel?: ConfidenceLevel;
    checkedAt?: string;
}
export interface Page<T> {
    items: T[];
    total: number;
}
export interface SegmentSummary {
    id: number | string;
    label: string;
    status?: 'building' | 'ready' | 'failed';
    size?: number;
}
