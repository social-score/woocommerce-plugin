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
    totalSpent?: number;
    currency?: string;
    firstOrderAt?: string;
    lastOrderAt?: string;
}
export interface OrderItemInput {
    productId: string;
    name: string;
    quantity: number;
    price: number;
}
export interface OrderInput {
    externalId: string;
    customerExternalId?: string;
    total: number;
    currency: string;
    placedAt: string;
    /** Product data only, no personal data. */
    items?: OrderItemInput[];
    shippingCity?: string;
    shippingPostcode?: string;
    shippingCountry?: string;
}
/** The contract does not describe the batch answer; it is passed through as received. */
export type BatchResult = Record<string, unknown>;
