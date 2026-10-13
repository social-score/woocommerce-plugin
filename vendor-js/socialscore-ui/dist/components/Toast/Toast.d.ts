import { type ReactElement, type ReactNode } from 'react';
import { type PortalContainer } from '../../internal/portal.js';
export interface ToastProviderProps {
    /** Where the toast stack mounts. Default `document.body`. */
    container?: PortalContainer;
    /** Default auto-dismiss time in ms. */
    defaultDuration?: number;
    /** Oldest toasts are dropped beyond this. */
    max?: number;
    /** Label for the close button. */
    dismissLabel?: string;
    children?: ReactNode;
}
export declare function ToastProvider({ container, defaultDuration, max, dismissLabel, children, }: ToastProviderProps): ReactElement;
