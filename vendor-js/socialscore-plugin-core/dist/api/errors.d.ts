/** A non-2xx answer from the public API. */
export declare class ApiError extends Error {
    readonly status: number;
    /** The API's error code, for example `plan_required`. */
    readonly code?: string;
    /** Seconds from the Retry-After header of a 429. */
    readonly retryAfter?: number;
    constructor(status: number, code?: string, message?: string, retryAfter?: number);
}
