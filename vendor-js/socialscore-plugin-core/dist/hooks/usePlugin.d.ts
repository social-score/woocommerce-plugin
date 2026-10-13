import type { ApiClient } from '../api/client.js';
import type { PluginContextValue } from '../context.js';
import type { PluginHost } from '../host.js';
import type { MessageKey } from '../messages.js';
export type TFunction = (key: MessageKey, vars?: Record<string, string | number>) => string;
export declare function usePlugin(): PluginContextValue;
export declare const useApi: () => ApiClient;
export declare const useHost: () => PluginHost;
/** Host translation first; the English text when the host has none (or returns the key). */
export declare function useT(): TFunction;
