import { type ButtonHTMLAttributes, type HTMLAttributes, type ReactElement, type ReactNode } from 'react';
export type TabsOrientation = 'horizontal' | 'vertical';
export interface TabsProps {
    /** Controlled selected tab. */
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    orientation?: TabsOrientation;
    /** `automatic`: arrow keys select as they move focus. `manual`: Enter/Space selects. */
    activation?: 'automatic' | 'manual';
    className?: string;
    children: ReactNode;
}
export declare function Tabs({ value, defaultValue, onValueChange, orientation, activation, className, children, }: TabsProps): ReactElement;
export type TabListProps = HTMLAttributes<HTMLDivElement>;
/** Give it an `aria-label` or `aria-labelledby`. */
export declare function TabList({ className, onKeyDown, ...rest }: TabListProps): ReactElement;
export interface TabProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'value'> {
    value: string;
}
export declare function Tab({ value, className, onClick, disabled, children, ...rest }: TabProps): import("react/jsx-runtime").JSX.Element;
export interface TabPanelProps extends HTMLAttributes<HTMLDivElement> {
    value: string;
}
/** Only the selected panel is mounted. */
export declare function TabPanel({ value, className, children, ...rest }: TabPanelProps): import("react/jsx-runtime").JSX.Element | null;
