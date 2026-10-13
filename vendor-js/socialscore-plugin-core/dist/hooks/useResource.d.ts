export interface Resource<T> {
    data?: T;
    error?: unknown;
    loading: boolean;
    reload: () => void;
}
/** Runs `load` on mount and whenever its identity changes (wrap it in useCallback). */
export declare function useResource<T>(load: () => Promise<T>): Resource<T>;
