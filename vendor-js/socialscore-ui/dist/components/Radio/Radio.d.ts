import type { FieldsetHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';
export interface RadioGroupProps extends Omit<FieldsetHTMLAttributes<HTMLFieldSetElement>, 'onChange' | 'children'> {
    /** The group's name. Defaults to a generated one. */
    name?: string;
    legend?: ReactNode;
    /** Controlled selected value. */
    value?: string;
    onValueChange?: (value: string) => void;
    children: ReactNode;
}
/** A fieldset of radios that share a name, so arrow keys move between them natively. */
export declare function RadioGroup({ name, legend, value, onValueChange, disabled, className, children, ...rest }: RadioGroupProps): import("react/jsx-runtime").JSX.Element;
export interface RadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size' | 'children'> {
    /** Text next to the circle. Without it, give the input an `aria-label`. */
    label?: ReactNode;
    /** Goes on the label row, so a page can set spacing. */
    className?: string;
}
/** A native radio. Inside a `RadioGroup` it takes the group's name and selection. */
export declare const Radio: import("react").ForwardRefExoticComponent<RadioProps & import("react").RefAttributes<HTMLInputElement>>;
