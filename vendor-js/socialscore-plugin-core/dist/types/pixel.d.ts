/** `human`, `lurker` (a visit with no mouse, key or scroll), `bad_bot` or `ai_agent`. */
export type SessionClassName = 'human' | 'lurker' | 'bad_bot' | 'ai_agent';
/**
 * The worst visit of a customer (last 30 days) or the visit that bought an order, as the Pixel
 * classified it. Nothing personal and no URL. The API answers 404 when there is none.
 */
export interface SessionAnswer {
    class: SessionClassName;
    botScore: number;
    /** True when any of the visits came from a device farm. */
    deviceFarm: boolean;
    lastSeenAt: string;
    /** Pages read in that visit. */
    pages: number;
}
/** `GET` and `PATCH` of `/stores/{id}/pixel`. `siteId` is absent until the Pixel was first switched on. */
export interface PixelState {
    enabled: boolean;
    siteId?: string;
    snippetUrl: string;
}
