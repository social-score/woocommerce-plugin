import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createReporter, init, watchBlocks, watchClassic, type PixelEvent } from '../../src/js/pixel-events';

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

	it('does nothing on a page without the cart store', () => {
		const { events, report } = collector();
		const subscribe = jest.fn();
		watchBlocks({ select: () => undefined, subscribe } as any, report);
		expect(subscribe).not.toHaveBeenCalled();
		expect(events).toEqual([]);
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

	it('does not throw when the queue is broken', () => {
		expect(() => init({ ssPixel: 5, wp: null })).not.toThrow();
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
