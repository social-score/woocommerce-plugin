/**
 * `human`, `lurker` (a visit with no mouse, key or scroll), `info_bot` (a declared good bot such as
 * Googlebot), `ai_agent` or `bad_bot`.
 */
export type SessionClassName = 'human' | 'lurker' | 'info_bot' | 'ai_agent' | 'bad_bot';
export type AgentSystem = 'chatgpt' | 'claude' | 'perplexity' | 'gemini' | 'copilot' | 'other';
export type AgentPurpose = 'scan' | 'lookup' | 'shop';
/**
 * The worst visit of a customer (last 30 days) or the visit that bought an order, as the Pixel
 * classified it. Nothing personal and no URL. The API answers 404 when there is none.
 */
export interface SessionAnswer {
    class: SessionClassName;
    /** Stable codes of what the Pixel saw. Absent on a session stored before contract v4. */
    signals?: string[];
    agentSystem?: AgentSystem;
    agentPurpose?: AgentPurpose;
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
