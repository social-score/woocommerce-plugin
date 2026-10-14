import type { CheckLabel } from './checks.js';
import type { ConfidenceLevel } from './profile.js';
/** An order labelled for review: the shop's own order id and the order's figures, no personal data. */
export interface RiskyOrder {
    externalId: string;
    placedAt?: string;
    /** A decimal string such as "59.90". */
    total?: string;
    currency?: string;
    labels: CheckLabel[];
}
/**
 * Counts of people. The API sends `null` for a count with fewer than 5 people behind it, which
 * `ApiClient` turns into a missing key, so a missing count means "fewer than 5", never zero.
 */
export interface StoreOverview {
    checkedCustomers?: number;
    checkedOrders?: number;
    businessBuyers?: number;
    riskyOrders: RiskyOrder[];
}
/** Level of each health signal of the last check; a missing key means no value or switched off. */
export interface CustomerHealth {
    email?: ConfidenceLevel;
    phone?: ConfidenceLevel;
    /** The strength of the risk. */
    fraudLevel?: ConfidenceLevel;
    digitalPresence?: ConfidenceLevel;
}
/** A row of `GET .../customers`: no name, email or phone is sent. */
export interface CheckedCustomer {
    externalId: string;
    profileId?: number;
    health: CustomerHealth;
    labels: CheckLabel[];
    checkedAt?: string;
}
export interface Page<T> {
    items: T[];
    total: number;
}
export type SizeBand = '5-49' | '50-499' | '500-4999' | '5000+';
/** A ready segment. A missing `sizeBand` means fewer than 5 people. */
export interface SegmentSummary {
    id: number;
    name: string;
    sizeBand?: SizeBand;
}
