export type Platform = 'woocommerce' | 'shopify';
export type StoreStatus = 'active' | 'disconnected' | 'uninstalled';
/** Enrichment triggers from BRD 12.6 (design-public-api.md, `stores.settings`). */
export interface TriggerSettings {
    onNewAccount: boolean;
    everyAccount: boolean;
    /** Hidden in the settings screen until the Pixel exists (phase 5). */
    abandonedCart: boolean;
    orderNewCustomer: boolean;
    orderAnyCustomer: boolean;
    manualOnly: boolean;
}
export type TriggerKey = keyof TriggerSettings;
export interface StoreSettingsResponse {
    /** needs backend: the contract names `settings` only; the connect status has no field yet. */
    status?: StoreStatus;
    settings: TriggerSettings;
}
/** needs backend: "API returns the estimate" has no endpoint in design-public-api.md. */
export interface CostEstimate {
    /** How many Explores turning on "For every account" would run. */
    explores: number;
}
