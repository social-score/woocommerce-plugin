export type Platform = 'woocommerce' | 'shopify';
/**
 * `disconnected` is answered while the member who connected the store is gone or disabled. A store that
 * was uninstalled answers 401 on the settings route, so the screen shows the expired-connection message.
 */
export type StoreStatus = 'active' | 'disconnected' | 'uninstalled';
/** When the plugin runs Check by itself (BRD 12.6). Abandoned cart needs the Pixel (phase 5): the API has no such field. */
export interface TriggerSettings {
    onNewAccount: boolean;
    everyAccount: boolean;
    orderNewCustomer: boolean;
    orderAnyCustomer: boolean;
    manualOnly: boolean;
}
export type TriggerKey = keyof TriggerSettings;
export interface StoreSettingsResponse {
    storeId: number;
    status: StoreStatus;
    settings: TriggerSettings;
}
/** What turning on "For every account" would cost. `exploresMax` is an upper bound. */
export interface CostEstimate {
    /** At most this many customers would be checked and charged. */
    exploresMax: number;
    /** Customers with a still valid check of the organization's own; no charge. */
    reusedKnown: number;
}
export interface UninstallResult {
    storeId: number;
    status: string;
    customersRedacted: number;
    ordersRedacted: number;
    profilesErased: number;
}
