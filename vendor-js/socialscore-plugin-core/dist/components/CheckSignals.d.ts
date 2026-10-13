import type { ReactElement } from 'react';
import type { Profile } from '../types/index.js';
/** The health signals of BRD 12.3 for one profile. Masked or missing values say so. */
export declare function CheckSignals({ profile }: {
    profile: Profile;
}): ReactElement | null;
