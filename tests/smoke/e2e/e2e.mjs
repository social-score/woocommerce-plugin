// Browser check of the Pixel add-to-cart events: runs in the Playwright image against the smoke stack.
// Usage: node e2e.mjs <label> (label only names the run: the active theme decides classic or block).
// It reads window.ssPixel, which stays a plain array because the Pixel script itself is blocked here.
import { chromium } from 'playwright';

const base = 'http://wordpress';
const label = process.argv[2] || 'run';
const failures = [];
const check = (ok, msg) => {
	console.log((ok ? 'ok - ' : 'FAIL - ') + `[${label}] ` + msg);
	if (!ok) failures.push(msg);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await chromium.launch();
const fresh = async () => {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.route('https://pixel.socialscore.io/**', (route) => route.abort());
	page.on('pageerror', (e) => console.log('pageerror: ' + e.message));
	return page;
};
const adds = (page) => page.evaluate(() => JSON.parse(JSON.stringify((window.ssPixel || []).filter((e) => e && e.type === 'add_to_cart'))));
const ids = JSON.parse(process.env.E2E_IDS || '{}');

// 1. A simple product added from the shop page (AJAX loop button on a classic theme, product button on a block theme).
{
	const page = await fresh();
	await page.goto(base + '/shop/', { waitUntil: 'load' });
	const card = page.locator('li.product, li.wc-block-product, li.wp-block-post').filter({ hasText: 'E2E mug' }).first();
	const button = card.locator('a.add_to_cart_button, button.wp-block-button__link, .wp-block-button__link').first();
	await button.click();
	await sleep(2500);
	let seen = await adds(page);
	console.log(JSON.stringify(seen));
	check(seen.length === 1, 'shop page add: exactly one add_to_cart (got ' + seen.length + ')');
	if (seen[0]) {
		check(seen[0].data.item_id === String(ids.mug), 'shop page add: item_id is the product id (got ' + seen[0].data.item_id + ')');
		check(seen[0].data.quantity === 1 && seen[0].data.price === 12.5, 'shop page add: quantity 1, price 12.5 (got ' + seen[0].data.quantity + ', ' + seen[0].data.price + ')');
	}
	await sleep(1500);
	await button.click();
	await sleep(2500);
	seen = await adds(page);
	check(seen.length === 2, 'a second add after the dedupe window is a second event (got ' + seen.length + ')');
	await page.context().close();
}

// 2. A variation added from its product page.
{
	const page = await fresh();
	await page.goto(base + '/?p=' + ids.tee, { waitUntil: 'load' });
	await page.locator('select[name="attribute_size"]').selectOption('S');
	await sleep(1000);
	await page.locator('.single_add_to_cart_button, .wp-block-button__link:has-text("Add to cart")').first().click();
	await sleep(3500);
	let seen = await adds(page);
	console.log('variation: ' + JSON.stringify(seen));
	check(seen.length === 1, 'variation add: exactly one add_to_cart (got ' + seen.length + ')');
	if (seen[0]) {
		check(seen[0].data.item_id === String(ids.tee), 'variation add: item_id is the parent product id (got ' + seen[0].data.item_id + ')');
		check(seen[0].data.variant_id === String(ids.variations[0]), 'variation add: variant_id is the variation id (got ' + seen[0].data.variant_id + ')');
		check(seen[0].data.price === 20, 'variation add: price is the variation price (got ' + seen[0].data.price + ')');
	}
	// The same variation raised in the block cart: still the parent id, with the variation id apart.
	await page.goto(base + '/cart/', { waitUntil: 'load' });
	const plus = page.locator('button.wc-block-components-quantity-selector__button--plus');
	await plus.first().waitFor({ timeout: 8000 }).catch(() => undefined);
	if (await plus.count()) {
		const before = (await adds(page)).length;
		await plus.first().click();
		await sleep(4000);
		seen = await adds(page);
		console.log('variation cart plus: ' + JSON.stringify(seen.slice(before)));
		check(seen.length - before === 1, 'block cart plus on a variation: one add_to_cart (got ' + (seen.length - before) + ')');
		const last = seen[seen.length - 1];
		check(last && last.data.item_id === String(ids.tee) && last.data.variant_id === String(ids.variations[0]), 'block cart plus on a variation: parent id and variant id (got ' + JSON.stringify(last && last.data) + ')');
	}
	await page.context().close();
}

// 3. Cart page: raise the quantity (block cart) after a simple add.
{
	const page = await fresh();
	await page.goto(base + '/shop/', { waitUntil: 'load' });
	const card = page.locator('li.product, li.wc-block-product, li.wp-block-post').filter({ hasText: 'E2E mug' }).first();
	await card.locator('a.add_to_cart_button, button.wp-block-button__link, .wp-block-button__link').first().click();
	await sleep(3500);
	await page.goto(base + '/cart/', { waitUntil: 'load' });
	await sleep(1500);
	const plus = page.locator('button.wc-block-components-quantity-selector__button--plus');
	await plus.first().waitFor({ timeout: 8000 }).catch(() => undefined);
	if (await plus.count()) {
		const before = (await adds(page)).length;
		await plus.first().click();
		await sleep(3500);
		const seen = await adds(page);
		console.log('cart plus: ' + JSON.stringify(seen));
		check(seen.length - before === 1, 'block cart quantity plus: one add_to_cart (got ' + (seen.length - before) + ')');
	} else {
		console.log('classic cart: no quantity buttons to click, skipped');
	}
	// Checkout page: one begin_checkout, from the form hook (classic) or the page (block).
	await page.goto(base + '/checkout/', { waitUntil: 'load' });
	await sleep(1500);
	const begins = await page.evaluate(() => (window.ssPixel || []).filter((e) => e && e.type === 'begin_checkout').length);
	check(begins === 1, 'checkout page: exactly one begin_checkout (got ' + begins + ')');
	await page.context().close();
}

await browser.close();
if (failures.length) {
	console.log('E2E FAILED: ' + failures.length);
	process.exit(1);
}
console.log('E2E OK');
