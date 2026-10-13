import type { ReactElement } from 'react';
import type { ProfileRef } from '../lib/format.js';
export interface ProfileViewProps extends ProfileRef {
    onBack?: () => void;
}
/** The 7.1 profile of one customer or order, category by category. A category with no data is not shown (7.2). */
export declare function ProfileView({ kind, externalId, onBack }: ProfileViewProps): ReactElement;
