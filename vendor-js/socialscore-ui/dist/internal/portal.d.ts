/** Where an overlay mounts. Defaults to `document.body`; a plugin passes its own root. */
export type PortalContainer = HTMLElement | null | (() => HTMLElement | null);
export declare function resolveContainer(container?: PortalContainer): HTMLElement | null;
export declare const FOCUSABLE = "a[href], area[href], button:not([disabled]), input:not([disabled]):not([type=\"hidden\"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex=\"-1\"]), [contenteditable=\"true\"]";
export declare function focusableIn(root: HTMLElement): HTMLElement[];
export declare function lockScroll(doc: Document): () => void;
