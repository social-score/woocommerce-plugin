/// <reference types="react" />
export type ToastTone = 'info' | 'success' | 'warning' | 'error';
export interface ToastOptions {
    /** Plain text. Never rendered as HTML. */
    message: string;
    title?: string;
    tone?: ToastTone;
    /** Milliseconds before it closes itself. `0` keeps it until dismissed. */
    duration?: number;
    /** Errors default to `assertive`, everything else to `polite`. */
    politeness?: 'polite' | 'assertive';
}
export interface ToastApi {
    show: (options: ToastOptions) => string;
    dismiss: (id: string) => void;
    dismissAll: () => void;
}
export interface ToastItem extends ToastOptions {
    id: string;
}
export declare const ToastContext: import("react").Context<ToastApi | null>;
export declare function useToast(): ToastApi;
