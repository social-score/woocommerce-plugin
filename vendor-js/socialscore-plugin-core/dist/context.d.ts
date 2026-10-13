/// <reference types="react" />
import type { ApiClient } from './api/client.js';
import type { PluginHost } from './host.js';
export interface PluginContextValue {
    host: PluginHost;
    api: ApiClient;
    storeId: string;
}
export declare const PluginContext: import("react").Context<PluginContextValue | null>;
