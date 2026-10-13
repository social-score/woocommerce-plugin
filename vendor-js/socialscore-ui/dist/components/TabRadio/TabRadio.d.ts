import type { ComponentType, InputHTMLAttributes, ReactNode, SVGProps } from 'react';
export type TabRadioVariant = 'primary' | 'secondary';
export interface TabRadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size' | 'children' | 'checked'> {
    /** Controlled: the selected option of the group is the one that gets `checked`. */
    checked: boolean;
    /** Text on the tab. Defaults to `value`. */
    label?: ReactNode;
    /** `primary` has round ends, `secondary` rounded corners on a tinted track. */
    variant?: TabRadioVariant;
    /** An icon before the text. */
    Icon?: ComponentType<SVGProps<SVGSVGElement>>;
    /** Goes on the label, so a page can set the width. */
    className?: string;
}
/**
 * One option of a segmented control. A native radio, so arrow keys move between
 * the options of a `name` group and screen readers announce them as radios.
 */
export declare const TabRadio: import("react").ForwardRefExoticComponent<TabRadioProps & import("react").RefAttributes<HTMLInputElement>>;
