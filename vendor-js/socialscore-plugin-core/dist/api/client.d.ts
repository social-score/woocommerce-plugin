import type { PluginHost } from '../host.js';
import type { BatchResult, CheckResult, CheckSubject, CostEstimate, CustomerInput, HookCreated, HookInput, OrderInput, Page, CheckedCustomer, Profile, SegmentSummary, StoreOverview, StoreSettingsResponse, TriggerSettings } from '../types/index.js';
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
    /** needs backend: no estimate endpoint in the contract. */
    getCostEstimate(): Promise<CostEstimate>;
    uninstall(): Promise<void>;
    pushCustomers(customers: CustomerInput[]): Promise<BatchResult>;
    pushOrders(orders: OrderInput[]): Promise<BatchResult>;
    /**
     * Runs Check. Pass the same `idempotencyKey` when retrying the same click, so the store is not
     * charged twice; a fresh key is made when none is given.
     */
    runCheck(subject: CheckSubject, idempotencyKey?: string): Promise<CheckResult>;
    getCustomerProfile(externalId: string): Promise<Profile>;
    getOrderProfile(externalId: string): Promise<Profile>;
    getOverview(): Promise<StoreOverview>;
    listCheckedCustomers(page: number, pageSize: number): Promise<Page<CheckedCustomer>>;
    listSegments(): Promise<SegmentSummary[]>;
    createHook(input: HookInput): Promise<HookCreated>;
    deleteHook(id: string | number): Promise<void>;
    getHookSamples(eventType: string): Promise<unknown>;
}
