import { type HTMLAttributes, type ReactElement, type ReactNode, type Ref } from 'react';
import type { PortalContainer } from '../../internal/portal.js';
import { type PopoverPlacement } from '../Popover/Popover.js';
export interface MenuProps {
    /** Content of the button that opens the menu. */
    label: ReactNode;
    /** Name for the button when `label` is not text (an icon). */
    'aria-label'?: string;
    children: ReactNode;
    placement?: PopoverPlacement;
    offset?: number;
    matchButtonWidth?: boolean;
    container?: PortalContainer;
    /** Fires whenever the menu opens or closes. */
    onOpenChange?: (open: boolean) => void;
    className?: string;
    /** Class for the menu panel. */
    menuClassName?: string;
    /** Class for the positioned wrapper around the panel (e.g. a z-index). */
    popoverClassName?: string;
}
export declare function Menu({ label, 'aria-label': ariaLabel, children, placement, offset, matchButtonWidth, container, onOpenChange, className, menuClassName, popoverClassName, }: MenuProps): ReactElement;
export interface MenuItemRenderProps extends HTMLAttributes<HTMLElement> {
    ref: Ref<never>;
    role: 'menuitem';
    tabIndex: number;
}
export interface MenuItemProps {
    onSelect?: () => void;
    disabled?: boolean;
    className?: string;
    children: ReactNode;
    /**
     * Render the item as something other than a button, e.g. a router link:
     * `render={(props) => <Link to="/x" {...props} />}`. Spread `props` on the element.
     */
    render?: (props: MenuItemRenderProps) => ReactElement;
}
export declare function MenuItem({ onSelect, disabled, className, children, render, }: MenuItemProps): ReactElement;
