import type { ReactElement } from 'react';
export interface ErrorNoteProps {
    error: unknown;
    onRetry?: () => void;
}
export declare function ErrorNote({ error, onRetry }: ErrorNoteProps): ReactElement;
