import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createReporter, init, syncCheckoutRef, watchBlocks, watchClassic, watchStoreSync, type PixelEvent } from '../../src/js/pixel-events';

function collector() {
	const events: PixelEvent[] = [];
	let clock = 1000;
	const report = createReporter((e) => events.push(e), 'EUR', () => clock);
	return { events, report, tick: (ms: number) => (clock += ms) };
}

/** A fake `wc/store/cart` store: setCart() replaces the cart and notifies the subscriber. */
function fakeData(resolved = true) {
	let items: any[] = [];
	let done = resolved;
	let listener: (() => void) | null = null;
	const store = {
		getCartData: () => ({ items }),
		hasFinishedResolution: () => done,
	};
	return {
		data: {
			select: (name: string) => (name === 'wc/store/cart' ? store : undefined),
			subscribe: (fn: () => void) => {
				listener = fn;
				return () => undefined;
			},
		},
		setCart(next: any[], isDone = true) {
			items = next;
			done = isDone;
			listener?.();
		},
	};
}

const item = (key: string, id: number, quantity: number, price = '1990') => ({ key, id, quantity, prices: { price, currency_code: 'BGN', currency_minor_unit: 2 } });
const variationItem = (key: string, id: number, quantity: number) => ({ ...item(key, id, quantity), variation: [{ attribute: 'Size', value: 'S' }] });
const settleAll = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('classic add to cart', () => {
	function jqStub() {
		let handler: (...a: any[]) => void = () => undefined;
		const jq: any = () => ({ on: (_n: string, h: any) => (handler = h) });
		return { jq, fire: (...a: any[]) => handler(...a) };
	}
	const button = (attrs: Record<string, string>) => ({ length: 1, attr: (k: string) => attrs[k] });

	it('reports the product, quantity and price of the clicked button', () => {
		const { events, report } = collector();
		const { jq, fire } = jqStub();
		watchClassic(jq, report);
		fire({}, {}, 'hash', button({ 'data-product_id': '12', 'data-quantity': '3', 'data-ss-price': '4.5' }));
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '12', quantity: 3, price: 4.5, currency: 'EUR' } }]);
	});

	it('defaults to quantity 1 and price 0, and ignores a call without a button', () => {
		const { events, report } = collector();
		const { jq, fire } = jqStub();
		watchClassic(jq, report);
		fire({}, {}, 'hash', button({ 'data-product_id': '12' }));
		fire({}, {}, 'hash', undefined);
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '12', quantity: 1, price: 0, currency: 'EUR' } }]);
	});
});

describe('block cart store', () => {
	it('does not count the first load of the cart, only later increases', () => {
		const { events, report } = collector();
		const fake = fakeData();
		watchBlocks(fake.data as any, report);
		fake.setCart([item('a', 5, 1)]);
		expect(events).toEqual([]);
		fake.setCart([item('a', 5, 3)]);
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '5', quantity: 2, price: 19.9, currency: 'BGN' } }]);
	});

	it('reports a new line, and nothing for a removal or an unrelated store update', () => {
		const { events, report, tick } = collector();
		const fake = fakeData();
		watchBlocks(fake.data as any, report);
		fake.setCart([item('a', 5, 2)]);
		fake.setCart([item('a', 5, 2), item('b', 8, 1, '500')]);
		expect(events.map((e) => [e.data.item_id, e.data.quantity, e.data.price])).toEqual([['8', 1, 5]]);
		tick(10);
		fake.setCart([item('b', 8, 1, '500')]);
		fake.setCart([item('b', 8, 1, '500')]);
		expect(events).toHaveLength(1);
	});

	it('waits until the cart has loaded', () => {
		const { events, report } = collector();
		const fake = fakeData(false);
		watchBlocks(fake.data as any, report);
		fake.setCart([item('a', 5, 1)], false);
		fake.setCart([item('a', 5, 1)], true);
		expect(events).toEqual([]);
	});

	it('reports a variation with the parent product id as item_id and its own id as variant_id', async () => {
		const { events, report } = collector();
		const fake = fakeData();
		const lookup = jest.fn().mockResolvedValue({ price: 20, parent: 12 });
		watchBlocks(fake.data as any, report, lookup);
		fake.setCart([variationItem('v', 13, 1)]);
		fake.setCart([variationItem('v', 13, 2)]);
		await settleAll();
		expect(lookup).toHaveBeenCalledWith('13');
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '12', quantity: 1, price: 19.9, currency: 'BGN', variant_id: '13' } }]);
	});

	it('does not look anything up for a plain product', () => {
		const { events, report } = collector();
		const fake = fakeData();
		const lookup = jest.fn();
		watchBlocks(fake.data as any, report, lookup);
		fake.setCart([item('a', 5, 1)]);
		fake.setCart([item('a', 5, 2)]);
		expect(lookup).not.toHaveBeenCalled();
		expect(events).toHaveLength(1);
	});

	it('does nothing on a page without the cart store', () => {
		const { events, report } = collector();
		const subscribe = jest.fn();
		watchBlocks({ select: () => undefined, subscribe } as any, report);
		expect(subscribe).not.toHaveBeenCalled();
		expect(events).toEqual([]);
	});
});

describe('block product buttons (Interactivity API cart store)', () => {
	const sync = (target: EventTarget, detail: any) => target.dispatchEvent(new CustomEvent('wc-blocks_store_sync_required', { detail }));
	const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

	it('reports a product added by the Interactivity API with the price looked up from the Store API', async () => {
		const { events, report } = collector();
		const target = new EventTarget();
		const lookup = jest.fn().mockResolvedValue({ price: 19.9, code: 'BGN' });
		watchStoreSync(target, report, lookup);
		sync(target, { type: 'from_iAPI', quantityChanges: { productsPendingAdd: [42] } });
		await settle();
		expect(lookup).toHaveBeenCalledWith('42');
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '42', quantity: 1, price: 19.9, currency: 'BGN' } }]);
	});

	it('reports a variation added by the Interactivity API under its parent id', async () => {
		const { events, report } = collector();
		const target = new EventTarget();
		watchStoreSync(target, report, () => Promise.resolve({ price: 22, code: 'BGN', parent: 12 }));
		sync(target, { type: 'from_iAPI', quantityChanges: { productsPendingAdd: [14] } });
		await settle();
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '12', quantity: 1, price: 22, currency: 'BGN', variant_id: '14' } }]);
	});

	it('still reports the add when the price cannot be read', async () => {
		const { events, report } = collector();
		const target = new EventTarget();
		watchStoreSync(target, report, () => Promise.reject(new Error('offline')));
		sync(target, { type: 'from_iAPI', quantityChanges: { productsPendingAdd: [7] } });
		await settle();
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '7', quantity: 1, price: 0, currency: 'EUR' } }]);
	});

	it('ignores syncs that are not adds, and syncs from the data store (watchBlocks counts those)', async () => {
		const { events, report } = collector();
		const target = new EventTarget();
		const lookup = jest.fn().mockResolvedValue({ price: 1 });
		watchStoreSync(target, report, lookup);
		sync(target, { type: 'from_iAPI', quantityChanges: { cartItemsPendingQuantity: ['k'] } });
		sync(target, { type: 'from_iAPI', quantityChanges: { cartItemsPendingDelete: ['k'] } });
		sync(target, { type: 'from_@wordpress/data', quantityChanges: { productsPendingAdd: [5] } });
		sync(target, undefined);
		await settle();
		expect(lookup).not.toHaveBeenCalled();
		expect(events).toEqual([]);
	});

	it('init listens for the sync event on the window and reads prices from the configured Store API', async () => {
		const target = new EventTarget() as any;
		target.ssPixel = [];
		target.socialscorePixel = { currency: 'EUR', storeApi: 'https://shop.test/?rest_route=/wc/store/v1/' };
		target.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve({ prices: { price: '1250', currency_minor_unit: 2, currency_code: 'EUR' } }) });
		init(target);
		sync(target, { type: 'from_iAPI', quantityChanges: { productsPendingAdd: [9] } });
		await settle();
		expect(target.fetch).toHaveBeenCalledWith('https://shop.test/?rest_route=/wc/store/v1/products/9', { credentials: 'same-origin' });
		expect(target.ssPixel).toEqual([{ type: 'add_to_cart', data: { item_id: '9', quantity: 1, price: 12.5, currency: 'EUR' } }]);
	});
});

describe('one add seen by both paths', () => {
	it('counts once within three seconds and again after', () => {
		const { events, report, tick } = collector();
		report('5', 1, 2);
		tick(500);
		report('5', 1, 2);
		expect(events).toHaveLength(1);
		tick(3000);
		report('5', 1, 2);
		expect(events).toHaveLength(2);
		report('6', 1, 2);
		expect(events).toHaveLength(3);
	});

	it('does not merge two variations of one product, and counts the same variation once', () => {
		const { events, report } = collector();
		report('12', 1, 20, 'EUR', '13');
		report('12', 1, 22, 'EUR', '14');
		report('12', 1, 20, 'EUR', '13');
		expect(events.map((e) => e.data.variant_id)).toEqual(['13', '14']);
	});

	it('ignores an empty id or a zero quantity and turns a bad price into 0', () => {
		const { events, report } = collector();
		report('', 1, 1);
		report('7', 0, 1);
		report('9', 1, NaN);
		expect(events).toEqual([{ type: 'add_to_cart', data: { item_id: '9', quantity: 1, price: 0, currency: 'EUR' } }]);
	});
});

describe('init', () => {
	it('pushes to the ssPixel queue and never throws without jQuery or wp.data', () => {
		const win: any = { socialscorePixel: { currency: 'EUR' } };
		expect(() => init(win)).not.toThrow();
		expect(Array.isArray(win.ssPixel)).toBe(true);
		const fake = fakeData();
		const win2: any = { ssPixel: [], wp: { data: fake.data }, socialscorePixel: { currency: 'EUR' } };
		init(win2);
		fake.setCart([item('a', 5, 1)]);
		fake.setCart([item('a', 5, 2)]);
		expect(win2.ssPixel).toHaveLength(1);
		expect(win2.ssPixel[0].type).toBe('add_to_cart');
	});

	it('waits for jQuery and wp.data that load after the bundle, without forcing them as dependencies', () => {
		const target = new EventTarget() as any;
		target.socialscorePixel = { currency: 'EUR' };
		init(target);
		const fake = fakeData();
		target.wp = { data: fake.data };
		let handler: (...a: any[]) => void = () => undefined;
		target.jQuery = () => ({ on: (_n: string, h: any) => (handler = h) });
		target.dispatchEvent(new Event('load'));
		fake.setCart([item('a', 5, 1)]);
		fake.setCart([item('a', 5, 2)]);
		handler({}, {}, 'hash', { length: 1, attr: (k: string) => ({ 'data-product_id': '88' } as any)[k] });
		expect(target.ssPixel.map((e: any) => e.data.item_id)).toEqual(['5', '88']);
	});

	it('does not throw when the queue is broken', () => {
		expect(() => init({ ssPixel: 5, wp: null })).not.toThrow();
	});
});

describe('checkout reference for the Checkout block', () => {
	const REF = '3f2b8c1e-5d4a-4b7e-9c3d-1a2b3c4d5e6f';

	it('hands the reference to the checkout store as extension data of the socialscore namespace', () => {
		const calls: any[] = [];
		const data: any = { dispatch: (store: string) => (store === 'wc/store/checkout' ? { setExtensionData: (...a: any[]) => calls.push(a) } : undefined) };
		expect(syncCheckoutRef(data, REF)).toBe(true);
		expect(calls).toEqual([['socialscore', { ss_checkout_ref: REF }]]);
	});

	it('falls back to the older action name and reports a missing store', () => {
		const calls: any[] = [];
		const old: any = { dispatch: () => ({ __internalSetExtensionData: (...a: any[]) => calls.push(a) }) };
		expect(syncCheckoutRef(old, REF)).toBe(true);
		expect(calls).toHaveLength(1);
		expect(syncCheckoutRef({ dispatch: () => undefined } as any, REF)).toBe(false);
		expect(syncCheckoutRef({} as any, REF)).toBe(false);
	});

	it('init sends it once the checkout store exists, and sends nothing when the page has no reference', () => {
		const calls: any[] = [];
		const target = new EventTarget() as any;
		target.socialscorePixel = { currency: 'EUR', checkoutRef: REF };
		init(target);
		target.wp = { data: { select: () => undefined, subscribe: () => () => undefined, dispatch: () => ({ setExtensionData: (...a: any[]) => calls.push(a) }) } };
		target.dispatchEvent(new Event('load'));
		expect(calls).toEqual([['socialscore', { ss_checkout_ref: REF }]]);

		const none: any[] = [];
		const bare: any = { ssPixel: [], socialscorePixel: { currency: 'EUR' }, wp: { data: { select: () => undefined, subscribe: () => () => undefined, dispatch: () => ({ setExtensionData: (...a: any[]) => none.push(a) }) } } };
		init(bare);
		expect(none).toEqual([]);
	});
});

describe('build/pixel-events', () => {
	const file = join(__dirname, '../../build/pixel-events.js');
	it('was built, small, and uses no React', () => {
		expect(existsSync(file)).toBe(true);
		const code = readFileSync(file, 'utf8');
		expect(code.length).toBeLessThan(6000);
		expect(code).not.toContain('react');
		expect(existsSync(join(__dirname, '../../build/pixel-events.asset.php'))).toBe(true);
	});
});
