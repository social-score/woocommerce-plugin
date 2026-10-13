import type { ReactNode } from 'react';
export interface TooltipProps {
    /** The trigger. Give it something focusable (a button, a link) so keyboard users reach it. */
    children: ReactNode;
    content: ReactNode;
    /** Goes on the wrapper. */
    className?: string;
}
/**
 * Shows on hover and on focus, hides on leave, blur or Escape (WCAG 1.4.13). The bubble
 * sits inside the wrapper, so the pointer can move onto it without it closing.
 * The trigger gets `aria-describedby` pointing at the bubble.
 */
export declare function Tooltip({ children, content, className }: TooltipProps): import("react/jsx-runtime").JSX.Element;
