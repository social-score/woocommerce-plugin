import type { Profile } from './profile.js';
export type CheckSubject = {
    customerExternalId: string;
    orderExternalId?: undefined;
} | {
    orderExternalId: string;
    customerExternalId?: undefined;
};
export type CheckLabel = 'review_before_shipping' | 'possible_business_buyer';
/**
 * "Returns the 7.1 profile summary (health signals, b2b, fraud score) + labels".
 * needs backend: the exact envelope is not pinned down; this assumes `{ profile, labels }`.
 */
export interface CheckResult {
    profile: Profile;
    labels: CheckLabel[];
}
