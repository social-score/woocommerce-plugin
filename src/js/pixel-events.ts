/**
 * Storefront add-to-cart events for the SocialScore Pixel: the classic jQuery event, the block cart data store and the
 * Interactivity API cart store (block product buttons).
 * Everything else (product view, checkout start, purchase) is printed by PHP. Nothing here reads consent: the
 * Pixel script holds or drops events by itself.
 */

export interface PixelEvent {
	type: 'add_to_cart';
	data: { item_id: string; quantity: number; price: number; currency: string };
}

type Push = (event: PixelEvent) => void;

interface CartItem {
	key: string;
	id: number;
	quantity: number;
	prices?: { price?: string; currency_code?: string; currency_minor_unit?: number };
}

interface CartStore {
	getCartData?: () => { items?: CartItem[] };
	hasFinishedResolution?: (selector: string) => boolean;
}

interface WpData {
	select: (store: string) => CartStore | undefined;
	subscribe: (listener: () => void, store?: string) => () => void;
}

interface JQueryLike {
	(target: unknown): { on: (name: string, handler: (...args: any[]) => void) => unknown };
}

/** The same add reported by two paths (classic event and block store) within this many ms counts once. */
const DEDUPE_MS = 3000;

export function createReporter(push: Push, currency: string, now: () => number = Date.now) {
	const recent = new Map<string, number>();
	return (itemId: string, quantity: number, price: number, code?: string): void => {
		if (!itemId || !(quantity > 0)) {
			return;
		}
		const at = now();
		const last = recent.get(itemId);
		if (last !== undefined && at - last < DEDUPE_MS) {
			return;
		}
		recent.set(itemId, at);
		push({
			type: 'add_to_cart',
			data: { item_id: itemId, quantity, price: Number.isFinite(price) ? price : 0, currency: code || currency },
		});
	};
}

type Report = ReturnType<typeof createReporter>;

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
 * The id reported is the Store API cart item id: the variation id for a variation, where PHP and the classic path
 * report the parent product id.
 */
export function watchBlocks(data: WpData, report: Report): boolean {
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
					report(String(item.id), added, parseInt(item.prices?.price ?? '0', 10) / Math.pow(10, minor), item.prices?.currency_code);
				}
			});
		}
		baseline = next;
	}, 'wc/store/cart');
	return true;
}

type Lookup = (id: string) => Promise<{ price: number; code?: string }>;

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
				(found) => report(key, 1, found.price, found.code),
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
			}));
}

export function init(win: any): void {
	try {
		const queue: any[] = (win.ssPixel = win.ssPixel || []);
		const report = createReporter((event) => queue.push(event), win.socialscorePixel?.currency ?? '');
		// jQuery and wp.data are not dependencies of this script (they would load on every page): use them when the
		// shop has loaded them, and look again once the page has finished loading.
		let classic = false;
		let blocks = false;
		const attach = () => {
			if (!classic && typeof win.jQuery === 'function') {
				classic = true;
				watchClassic(win.jQuery, report);
			}
			if (!blocks && win.wp?.data) {
				blocks = watchBlocks(win.wp.data, report);
			}
		};
		attach();
		if (typeof win.addEventListener === 'function') {
			if (!classic || !blocks) {
				win.addEventListener('load', () => {
					try {
						attach();
					} catch (_) {
						/* never throw into the shop page */
					}
				});
			}
			const base = win.socialscorePixel?.storeApi;
			if (typeof base === 'string' && typeof win.fetch === 'function') {
				watchStoreSync(win, report, storeApiLookup(win, base));
			}
		}
	} catch (_) {
		/* never throw into the shop page */
	}
}

if (typeof window !== 'undefined' && !(window as any).__ssPixelNoAutoInit) {
	init(window);
}
