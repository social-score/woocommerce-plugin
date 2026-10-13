import type { Platform } from './types/index.js';
/**
 * What a platform adapter (WooCommerce, Shopify) gives the shared screens. Screens keep no browser storage and
 * never reach into the parent window, so they work in a Shopify embedded iframe and in WP admin.
 */
export interface PluginHost {
    /**
     * `fetch` for the SocialScore public API. `path` is the API path (`/api/v2/...`); the adapter adds
     * the base URL and the authentication (WP REST proxy with nonce, or Shopify session token).
     */
    apiFetch(path: string, init?: RequestInit): Promise<Response>;
    /**
     * Moves the admin to another place. Gets an absolute URL (the connect flow) or a path such as
     * `profile/customer/42` (see `profilePath`) that the adapter maps to its own route.
     */
    navigate(to: string): void;
    toast(message: string): void;
    platform: Platform;
    /**
     * Translation by message key (`messages` lists them), with `{name}` placeholders in `vars`.
     * Leave it out, or return the key unchanged, to get the English text.
     */
    t?(key: string, vars?: Record<string, string | number>): string;
}
