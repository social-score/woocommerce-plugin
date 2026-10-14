/** Rows pushed with `customers:batch` and `orders:batch` (at most 500 per call, no charge). */
export interface CustomerInput {
    externalId: string;
    email?: string;
    phone?: string;
    firstName?: string;
    lastName?: string;
    city?: string;
    postcode?: string;
    country?: string;
    ordersCount?: number;
    totalSpent?: number | string;
    currency?: string;
    firstOrderAt?: string;
    lastOrderAt?: string;
}
export interface OrderItemInput {
    productId: string;
    name: string;
    quantity: number;
    price: number | string;
}
export interface OrderInput {
    externalId: string;
    /** Required by the API; a guest checkout is a customer with an id you choose. */
    customerExternalId: string;
    total: number | string;
    currency: string;
    placedAt: string;
    /** Product data only, no personal data. */
    items?: OrderItemInput[];
    shippingCity?: string;
    shippingPostcode?: string;
    shippingCountry?: string;
}
export type RejectReason = 'invalid_email' | 'invalid_phone' | 'invalid_country' | 'invalid_currency' | 'customer_not_found';
export interface RejectedRow {
    /** Position in the request. */
    index: number;
    externalId: string;
    reason: RejectReason;
}
/** The id SocialScore gave a stored row. `order.created` events carry this `id`, not the external id. */
export interface RowId {
    externalId: string;
    id: number;
}
/** Answer of both batch routes. `ids` lists every row stored (created or updated), not the rejected ones. */
export interface BatchResult {
    created: number;
    updated: number;
    rejected: RejectedRow[];
    ids: RowId[];
}
