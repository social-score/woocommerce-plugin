import type { ReactElement } from 'react';
import type { ProfileRef } from '../lib/format.js';
export type DashboardTab = 'overview' | 'profiles' | 'segments' | 'settings';
export interface StoreDashboardProps {
    /** Opens on a profile, for example when the adapter routed "See full profile" here. */
    initialProfile?: ProfileRef;
    initialTab?: DashboardTab;
}
/** The store dashboard (BRD 12.2): Overview, Profiles, Segments and Settings, no iframe. */
export declare function StoreDashboard({ initialProfile, initialTab, }: StoreDashboardProps): ReactElement;
