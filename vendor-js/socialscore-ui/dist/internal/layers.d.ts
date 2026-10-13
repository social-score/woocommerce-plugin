import { type RefObject } from 'react';
/**
 * Overlays (Modal, Popover, Menu) portal to a container outside the tree that opened
 * them, so the DOM cannot say which one is on top or who belongs to whom. React context
 * can: an overlay rendered inside another one's children registers as its child layer.
 * Only the topmost layer traps focus or closes on Escape; focus and presses inside a
 * descendant layer count as inside the ancestor.
 */
export interface Layer {
    parent: Layer | null;
    getElement: () => HTMLElement | null;
}
export declare const LayerContext: import("react").Context<Layer | null>;
export interface LayerHandle {
    layer: Layer;
    /** No other open layer sits above this one. */
    isTop: () => boolean;
    /** The node is inside this layer or inside a layer opened from it. */
    owns: (node: Node) => boolean;
}
/** Call before any effect that moves focus, so the layer is registered when focus lands. */
export declare function useLayer(active: boolean, elementRef: RefObject<HTMLElement>): LayerHandle;
