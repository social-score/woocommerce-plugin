/** Zapier-style REST hooks (design-events.md, `/api/v2/hooks`). */
export interface HookInput {
    targetUrl: string;
    eventType: string;
}
export interface HookCreated {
    id: string | number;
}
