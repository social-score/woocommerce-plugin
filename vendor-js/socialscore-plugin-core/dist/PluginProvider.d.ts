import type { ReactElement, ReactNode } from 'react';
import type { PluginHost } from './host.js';
export interface PluginProviderProps {
    host: PluginHost;
    /** SocialScore's id for the connected store (from the connect flow). */
    storeId: string | number;
    children: ReactNode;
}
/** Wrap the screens once; they read the host and the API client from here. */
export declare function PluginProvider({ host, storeId, children }: PluginProviderProps): ReactElement;
