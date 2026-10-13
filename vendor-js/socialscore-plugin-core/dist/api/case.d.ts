/** snake_case keys to camelCase and null values removed, the way the SocialScore client reads the API. */
export declare function fromWire<T>(value: unknown): T;
/** camelCase keys to snake_case for request bodies; undefined values are dropped by JSON.stringify. */
export declare function toWire(value: unknown): unknown;
