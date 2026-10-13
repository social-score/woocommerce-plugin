import type { ReactElement } from 'react';
import type { ProfileField } from '../types/index.js';
export interface FieldRowProps {
    label: string;
    field: ProfileField;
}
/** One 7.1 field: the value as returned (masked stays masked), its level, source and reason. */
export declare function FieldRow({ label, field }: FieldRowProps): ReactElement;
