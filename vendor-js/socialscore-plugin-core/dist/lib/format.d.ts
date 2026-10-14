export type ProfileKind = 'customer' | 'order';
export interface ProfileRef {
    kind: ProfileKind;
    externalId: string;
}
/** The path `host.navigate` gets for "See full profile"; the adapter maps it to the dashboard route. */
export declare function profilePath(ref: ProfileRef): string;
/** `total` is a decimal string such as "59.90" as the API sends money. */
export declare function formatMoney(total: number | string | undefined, currency: string | undefined): string;
export declare function formatDate(value: string | undefined): string;
/** `emailDomain` to "Email domain". */
export declare function humanize(key: string): string;
