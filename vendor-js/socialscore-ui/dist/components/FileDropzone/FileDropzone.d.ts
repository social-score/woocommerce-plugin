import type { ReactElement, ReactNode } from 'react';
export interface FileDropzoneProps {
    /** What to do, for example "Drag and drop a CSV file with users data". */
    description: ReactNode;
    /** The chosen file, shown under the drop area; `null` for none. */
    file: File | null;
    /** The file that was dropped or picked. When several are dropped, the first one. */
    onFileSelect: (file: File) => void;
    /** The remove button next to the file name was pressed. */
    onRemove: () => void;
    /** Passed to the file input, for example `.csv`. */
    accept?: string;
    /** Why the file cannot be used. Turns the file row red and is announced. */
    error?: ReactNode;
    /** A problem that does not stop the upload. Shown under the file row. */
    warning?: ReactNode;
    /** `tall` makes the drop area 20rem high (the Explore upload form); `default` fits its content. */
    size?: 'default' | 'tall';
    /** Text of the link that opens the file dialog. */
    browseLabel?: string;
    /** Accessible name of the remove button. */
    removeLabel?: string;
    id?: string;
    className?: string;
}
/**
 * A drop area with a keyboard-reachable "upload from computer" link. The file input is
 * visually hidden, not removed, so Tab reaches it and Enter or Space opens the dialog.
 */
export declare function FileDropzone({ description, file, onFileSelect, onRemove, accept, error, warning, size, browseLabel, removeLabel, id, className, }: FileDropzoneProps): ReactElement;
