import type { Profile } from './profile.js';
/** Answer of `DELETE .../customers/{external_id}`. `customersRedacted: 0` means the store had no such customer. */
export interface RedactCustomerResult {
    storeId: number;
    externalId: string;
    customersRedacted: number;
    ordersRedacted: number;
    profileErased: boolean;
}
export interface ExportedCustomer {
    externalId: string;
    email?: string;
    phone?: string;
    firstName?: string;
    lastName?: string;
    city?: string;
    postcode?: string;
    country?: string;
    ordersCount: number;
    totalSpent?: string;
    currency?: string;
    firstOrderAt?: string;
    lastOrderAt?: string;
}
export interface ExportedOrder {
    externalId: string;
    total?: string;
    currency?: string;
    placedAt?: string;
    items: {
        productId: string;
        name?: string;
        quantity: number;
        price?: string;
    }[];
    shippingCity?: string;
    shippingPostcode?: string;
    shippingCountry?: string;
    labels: string[];
}
/** Answer of `GET .../customers/{external_id}/export` (data request). */
export interface CustomerExport {
    storeId: number;
    customer: ExportedCustomer;
    orders: ExportedOrder[];
    profile?: Profile;
    earlierProfiles: Profile[];
}
