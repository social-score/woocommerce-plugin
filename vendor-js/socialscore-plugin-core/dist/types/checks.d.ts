import type { Profile } from './profile.js';
export type CheckSubject = {
    customerExternalId: string;
    orderExternalId?: undefined;
} | {
    orderExternalId: string;
    customerExternalId?: undefined;
};
export type CheckLabel = 'review_before_shipping' | 'possible_business_buyer';
/** Answer of `POST .../checks`. */
export interface CheckResult {
    storeId: number;
    customerExternalId: string;
    orderExternalId?: string;
    transactionId?: number;
    /** False when a still valid profile was reused at no charge. */
    charged: boolean;
    labels: CheckLabel[];
    /** Missing on the retry of an Idempotency-Key of an organization that stores no profiles. */
    profile?: Profile;
    /** True on that retry: run the check again, with a new key, to get the profile. */
    replayWithoutProfile?: boolean;
}
