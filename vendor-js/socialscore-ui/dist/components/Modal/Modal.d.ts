import { type ReactElement, type ReactNode, type RefObject } from 'react';
import { type PortalContainer } from '../../internal/portal.js';
export interface ModalProps {
    open: boolean;
    /** Called on Escape and on a backdrop click. The parent decides whether to close. */
    onClose: () => void;
    /** Visible heading; the dialog is labelled by it. Without it pass `aria-label`. */
    title?: ReactNode;
    'aria-label'?: string;
    /** Use `alertdialog` for confirmations that need an answer. */
    role?: 'dialog' | 'alertdialog';
    /** Where to mount. Default `document.body`. */
    container?: PortalContainer;
    /** Element to focus on open. Default: the first focusable element, else the dialog. */
    initialFocusRef?: RefObject<HTMLElement>;
    closeOnBackdropClick?: boolean;
    /** Bottom row for actions, e.g. buttons. */
    footer?: ReactNode;
    className?: string;
    children?: ReactNode;
}
export declare function Modal({ open, onClose, title, 'aria-label': ariaLabel, role, container, initialFocusRef, closeOnBackdropClick, footer, className, children, }: ModalProps): ReactElement | null;
