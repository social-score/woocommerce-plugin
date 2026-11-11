import type { PluginHost } from '../host.js';
import type { BatchResult, CheckResult, CheckSubject, CostEstimate, CustomerExport, CustomerInput, HookCreated, HookInput, OrderInput, Page, PixelState, CheckedCustomer, Profile, RedactCustomerResult, RowId, SegmentSummary, SessionAnswer, StoreOverview, StoreSettingsResponse, TriggerSettings, UninstallResult } from '../types/index.js';
/**
 * `external_id` to SocialScore id of the rows a batch stored. Keep it: an `order.created` event carries
 * our `order_id`, not the shop's order id.
 */
export declare function batchIdMap(ids: RowId[]): Map<string, number>;
export declare function newIdempotencyKey(): string;
/**
 * Typed calls against the public API v2 (design-public-api.md, design-events.md). Every request
 * goes through the injected host, which adds authentication. Responses are returned in camelCase.
 */
export declare class ApiClient {
    private readonly host;
    private readonly storePath;
    constructor(host: Pick<PluginHost, 'apiFetch'>, storeId: string | number);
    private request;
    getSettings(): Promise<StoreSettingsResponse>;
    updateSettings(patch: Partial<TriggerSettings>): Promise<StoreSettingsResponse>;
    /** Only `every_account` has an estimate. `exploresMax` is an upper bound; nothing is run or charged. */
    getCostEstimate(): Promise<CostEstimate>;
    /**
     * The plugin was removed. The store stops being served; its key keeps the two redact routes for 7 days.
     * With `redact`, the store's customers, orders and the profiles its checks built are deleted now.
     */
    uninstall(options?: {
        redact?: boolean;
    }): Promise<UninstallResult>;
    pushCustomers(customers: CustomerInput[]): Promise<BatchResult>;
    pushOrders(orders: OrderInput[]): Promise<BatchResult>;
    /**
     * Runs Check. Pass the same `idempotencyKey` when retrying the same click, so the store is not
     * charged twice; a fresh key is made when none is given.
     */
    runCheck(subject: CheckSubject, idempotencyKey?: string): Promise<CheckResult>;
    getCustomerProfile(externalId: string): Promise<Profile>;
    getOrderProfile(externalId: string): Promise<Profile>;
    /** The worst visit of the customer over 30 days, from the visitors the customer row confirms. */
    getCustomerSession(externalId: string): Promise<SessionAnswer>;
    /** The worst visit tied to the order. */
    getOrderSession(externalId: string): Promise<SessionAnswer>;
    /** 404 `pixel_not_enabled` where the environment does not serve the Pixel. */
    getPixel(): Promise<PixelState>;
    /** Main role only: another member's key gets 403 `permission_required`. */
    setPixel(enabled: boolean): Promise<PixelState>;
    getOverview(): Promise<StoreOverview>;
    listCheckedCustomers(page: number, pageSize: number): Promise<Page<CheckedCustomer>>;
    /** Ready segments with a size band. A store key may read them; it never writes. */
    listSegments(): Promise<SegmentSummary[]>;
    /** What SocialScore holds for one customer. 404 `customer_not_found` when the store has none. */
    exportCustomer(externalId: string): Promise<CustomerExport>;
    /** Redacts the customer and their orders. Safe to repeat: `customersRedacted` is 0 when nothing is left. */
    redactCustomer(externalId: string): Promise<RedactCustomerResult>;
    createHook(input: HookInput): Promise<HookCreated>;
    deleteHook(id: string | number): Promise<void>;
    getHookSamples(eventType: string): Promise<unknown>;
}
