/**
 * Storefront add-to-cart events for the SocialScore Pixel: the classic jQuery event, the block cart data store and the
 * Interactivity API cart store (block product buttons).
 * Everything else (product view, checkout start, purchase) is printed by PHP. Nothing here reads consent: the
 * Pixel collects regardless of consent and only records the banner choice.
 */

/** item_id is always the parent product id; the variation id, when there is one, goes in variant_id. */
export interface PixelEvent {
	type: 'add_to_cart';
	data: { item_id: string; quantity: number; price: number; currency: string; variant_id?: string };
}

type Push = (event: PixelEvent) => void;

interface CartItem {
	key: string;
	id: number;
	quantity: number;
	variation?: unknown[];
	prices?: { price?: string; currency_code?: string; currency_minor_unit?: number };
}

interface CartStore {
	getCartData?: () => { items?: CartItem[] };
	hasFinishedResolution?: (selector: string) => boolean;
}

interface CheckoutDispatch {
	setExtensionData?: (namespace: string, data: Record<string, unknown>, replace?: boolean) => void;
	__internalSetExtensionData?: (namespace: string, data: Record<string, unknown>, replace?: boolean) => void;
}

interface WpData {
	select: (store: string) => CartStore | undefined;
	dispatch?: (store: string) => CheckoutDispatch | undefined;
	subscribe: (listener: () => void, store?: string) => () => void;
}

interface JQueryLike {
	(target: unknown): { on: (name: string, handler: (...args: any[]) => void) => unknown };
}

/** The same add reported by two paths (classic event and block store) within this many ms counts once. */
const DEDUPE_MS = 3000;

export function createReporter(push: Push, currency: string, now: () => number = Date.now) {
	const recent = new Map<string, number>();
	return (itemId: string, quantity: number, price: number, code?: string, variantId?: string): void => {
		if (!itemId || !(quantity > 0)) {
			return;
		}
		const at = now();
		const key = itemId + ':' + (variantId ?? '');
		const last = recent.get(key);
		if (last !== undefined && at - last < DEDUPE_MS) {
			return;
		}
		recent.set(key, at);
		const data: PixelEvent['data'] = { item_id: itemId, quantity, price: Number.isFinite(price) ? price : 0, currency: code || currency };
		if (variantId && variantId !== itemId) {
			data.variant_id = variantId;
		}
		push({ type: 'add_to_cart', data });
	};
}

type Report = ReturnType<typeof createReporter>;

/** Price, currency and parent product id (0 when the id is not a variation) of a product or variation id. */
export type Lookup = (id: string) => Promise<{ price: number; code?: string; parent?: number }>;

/** Classic themes: WooCommerce triggers `added_to_cart` on document.body after an AJAX add. */
export function watchClassic(jq: JQueryLike, report: Report): void {
	jq(document.body).on('added_to_cart', (_event: unknown, _fragments: unknown, _hash: unknown, button: any) => {
		if (!button || !button.length) {
			return;
		}
		const id = String(button.attr('data-product_id') ?? '');
		const quantity = parseInt(String(button.attr('data-quantity') ?? '1'), 10) || 1;
		const price = parseFloat(String(button.attr('data-ss-price') ?? ''));
		report(id, quantity, price);
	});
}

/**
 * Cart and Checkout blocks: compare the cart in the `wc/store/cart` store after each change. Returns false when the
 * store is not there (yet).
 * The Store API cart item id is the variation id for a variation. A variation is reported with its parent product id as
 * item_id and its own id as variant_id, like PHP and the classic path, so `lookup` reads the parent from the Store API.
 */
export function watchBlocks(data: WpData, report: Report, lookup?: Lookup): boolean {
	const store = data.select('wc/store/cart');
	if (!store || typeof store.getCartData !== 'function') {
		return false;
	}
	let baseline: Map<string, number> | null = null;
	data.subscribe(() => {
		const current = data.select('wc/store/cart');
		if (!current || !current.getCartData) {
			return;
		}
		// The first load of the cart is the starting point, not an add.
		if (current.hasFinishedResolution && !current.hasFinishedResolution('getCartData')) {
			return;
		}
		const items = current.getCartData().items ?? [];
		const next = new Map<string, number>();
		items.forEach((item) => next.set(item.key, item.quantity));
		if (baseline) {
			items.forEach((item) => {
				const added = item.quantity - (baseline?.get(item.key) ?? 0);
				if (added > 0) {
					const minor = item.prices?.currency_minor_unit ?? 2;
					const price = parseInt(item.prices?.price ?? '0', 10) / Math.pow(10, minor);
					const id = String(item.id);
					if (lookup && Array.isArray(item.variation) && item.variation.length > 0) {
						lookup(id).then(
							(found) => report(String(found.parent || id), added, price, item.prices?.currency_code, id),
							() => report(id, added, price, item.prices?.currency_code)
						);
					} else {
						report(id, added, price, item.prices?.currency_code);
					}
				}
			});
		}
		baseline = next;
	}, 'wc/store/cart');
	return true;
}

/**
 * Checkout block: hands the server-made checkout reference to the block as Store API extension data, so it is posted
 * back with the order. Returns false when the checkout store is not there (yet). Older WooCommerce names the action
 * __internalSetExtensionData.
 */
export function syncCheckoutRef(data: WpData, ref: string): boolean {
	const actions = typeof data.dispatch === 'function' ? data.dispatch('wc/store/checkout') : undefined;
	const set = actions?.setExtensionData ?? actions?.__internalSetExtensionData;
	if (!actions || typeof set !== 'function') {
		return false;
	}
	set.call(actions, 'socialscore', { ss_checkout_ref: ref });
	return true;
}

/**
 * Block product buttons (Interactivity API): WooCommerce fires no jQuery event and no data-store change there. The
 * only signal with a product is `wc-blocks_store_sync_required` on window, which names the product ids added but
 * not the quantity (reported as 1) or the price, which `lookup` reads.
 */
export function watchStoreSync(target: EventTarget, report: Report, lookup: Lookup): void {
	target.addEventListener('wc-blocks_store_sync_required', (event: any) => {
		const detail = event?.detail;
		const ids = detail?.type === 'from_iAPI' ? detail.quantityChanges?.productsPendingAdd : null;
		if (!Array.isArray(ids)) {
			return;
		}
		ids.forEach((id: unknown) => {
			const key = String(id);
			lookup(key).then(
				(found) => report(String(found.parent || key), 1, found.price, found.code, found.parent ? key : undefined),
				() => report(key, 1, 0)
			);
		});
	});
}

/** Price from the Store API product route; `base` ends with a slash, also with plain permalinks (`?rest_route=`). */
function storeApiLookup(win: any, base: string): Lookup {
	return (id) =>
		win.fetch(base + 'products/' + id, { credentials: 'same-origin' })
			.then((response: any) => response.json())
			.then((product: any) => ({
				price: parseInt(product?.prices?.price ?? '0', 10) / Math.pow(10, product?.prices?.currency_minor_unit ?? 2),
				code: product?.prices?.currency_code,
				parent: Number(product?.parent) || 0,
			}));
}

export function init(win: any): void {
	try {
		const queue: any[] = (win.ssPixel = win.ssPixel || []);
		const report = createReporter((event) => queue.push(event), win.socialscorePixel?.currency ?? '');
		const base = win.socialscorePixel?.storeApi;
		const lookup = typeof base === 'string' && typeof win.fetch === 'function' ? storeApiLookup(win, base) : undefined;
		// jQuery and wp.data are not dependencies of this script (they would load on every page): use them when the
		// shop has loaded them, and look again once the page has finished loading.
		let classic = false;
		let blocks = false;
		const ref = win.socialscorePixel?.checkoutRef;
		let refSent = typeof ref !== 'string' || ref === '';
		const attach = () => {
			if (!refSent && win.wp?.data) {
				refSent = syncCheckoutRef(win.wp.data, ref);
			}
			if (!classic && typeof win.jQuery === 'function') {
				classic = true;
				watchClassic(win.jQuery, report);
			}
			if (!blocks && win.wp?.data) {
				blocks = watchBlocks(win.wp.data, report, lookup);
			}
		};
		attach();
		if (typeof win.addEventListener === 'function') {
			if (!classic || !blocks || !refSent) {
				win.addEventListener('load', () => {
					try {
						attach();
					} catch (_) {
						/* never throw into the shop page */
					}
				});
			}
			if (lookup) {
				watchStoreSync(win, report, lookup);
			}
		}
	} catch (_) {
		/* never throw into the shop page */
	}
}

if (typeof window !== 'undefined' && !(window as any).__ssPixelNoAutoInit) {
	init(window);
}
