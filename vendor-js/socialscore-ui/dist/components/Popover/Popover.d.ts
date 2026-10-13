import { type ReactElement, type ReactNode, type RefObject } from 'react';
import { type PortalContainer } from '../../internal/portal.js';
export type PopoverPlacement = 'bottom-start' | 'bottom-end' | 'top-start' | 'top-end';
export interface PopoverProps {
    open: boolean;
    /** Called on Escape and on a press outside the popover and its anchor. */
    onClose: () => void;
    /** The element the popover is placed against. */
    anchorRef: RefObject<HTMLElement>;
    placement?: PopoverPlacement;
    /** Gap in px between anchor and popover. May be negative to overlap. */
    offset?: number;
    matchAnchorWidth?: boolean;
    /** Where to mount. Default `document.body`. The popover is `position: fixed`. */
    container?: PortalContainer;
    /** `dialog` needs a label. Menus pass `none` and put their own role inside. */
    role?: string;
    'aria-label'?: string;
    'aria-labelledby'?: string;
    id?: string;
    /** Move focus into the popover on open (first focusable, else the popover). */
    autoFocus?: boolean;
    /** On close, give focus back to whatever had it before opening. */
    returnFocus?: boolean;
    className?: string;
    children?: ReactNode;
}
export declare function Popover({ open, onClose, anchorRef, placement, offset, matchAnchorWidth, container, role, 'aria-label': ariaLabel, 'aria-labelledby': ariaLabelledBy, id, autoFocus, returnFocus, className, children, }: PopoverProps): ReactElement | null;
