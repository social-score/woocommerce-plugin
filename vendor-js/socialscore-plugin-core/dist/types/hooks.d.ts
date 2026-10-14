/** Zapier-style REST hooks (`/api/v2/hooks`). Company keys only: a store key is refused. */
export interface HookInput {
    targetUrl: string;
    eventType: string;
}
export interface HookCreated {
    id: number;
    /** Signing secret for Standard Webhooks verification. Shown by the API on every subscribe. */
    secret: string;
}
