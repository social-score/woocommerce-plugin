import { createHost, parseProfile, profileUrl, proxyUrl } from '../../src/js/host';

describe('proxyUrl', () => {
	it('joins pretty permalinks', () => {
		expect(proxyUrl('https://shop.test/wp-json/socialscore/v1/proxy', '/api/v2/segments')).toBe(
			'https://shop.test/wp-json/socialscore/v1/proxy/api/v2/segments'
		);
	});

	it('keeps the query string for pretty permalinks', () => {
		expect(proxyUrl('https://shop.test/wp-json/socialscore/v1/proxy/', '/api/v2/stores/4/customers?checked=true&page=1')).toBe(
			'https://shop.test/wp-json/socialscore/v1/proxy/api/v2/stores/4/customers?checked=true&page=1'
		);
	});

	it('works with plain permalinks (?rest_route=)', () => {
		expect(proxyUrl('https://shop.test/?rest_route=/socialscore/v1/proxy', '/api/v2/stores/4/customers?page=2')).toBe(
			'https://shop.test/?rest_route=/socialscore/v1/proxy/api/v2/stores/4/customers&page=2'
		);
	});
});

describe('profile routing', () => {
	it('maps the plugin-core path to the dashboard URL and back', () => {
		const url = profileUrl('https://shop.test/wp-admin/admin.php?page=socialscore', 'profile/customer/guest:abc');
		expect(url).toBe('https://shop.test/wp-admin/admin.php?page=socialscore&profile=customer/guest%3Aabc');
		expect(parseProfile('customer/guest%3Aabc')).toEqual({ kind: 'customer', externalId: 'guest:abc' });
	});

	it('ignores paths it does not know', () => {
		expect(profileUrl('https://shop.test/wp-admin/admin.php?page=socialscore', 'elsewhere')).toBeNull();
		expect(parseProfile('nonsense')).toBeUndefined();
		expect(parseProfile(undefined)).toBeUndefined();
	});
});

describe('createHost', () => {
	const config = { restUrl: 'https://shop.test/wp-json/socialscore/v1/proxy', nonce: 'n0nce', dashboardUrl: 'https://shop.test/wp-admin/admin.php?page=socialscore' };

	afterEach(() => {
		jest.restoreAllMocks();
	});

	it('sends API calls to the WP proxy with the REST nonce and never to the SocialScore API', async () => {
		const fetchMock = jest.fn().mockResolvedValue({ ok: true, status: 200 });
		globalThis.fetch = fetchMock as unknown as typeof fetch;
		await createHost(config).apiFetch('/api/v2/stores/4/checks', { method: 'POST', headers: { 'Idempotency-Key': 'k-1' }, body: '{}' });

		const [url, init] = fetchMock.mock.calls[0];
		expect(url).toBe('https://shop.test/wp-json/socialscore/v1/proxy/api/v2/stores/4/checks');
		expect(new URL(url).host).toBe('shop.test');
		const headers = init.headers as Record<string, string>;
		expect(headers['X-WP-Nonce']).toBe('n0nce');
		expect(headers['Idempotency-Key']).toBe('k-1');
		expect(headers['X-API-KEY']).toBeUndefined();
		expect(init.credentials).toBe('same-origin');
	});

	it('translates by key with placeholders and falls back to the key', () => {
		const host = createHost({ ...config, translations: { 'error.rateLimited': 'Твърде много заявки: {seconds} s' } });
		expect(host.t?.('error.rateLimited', { seconds: 7 })).toBe('Твърде много заявки: 7 s');
		expect(host.t?.('unknown.key')).toBe('unknown.key');
	});

	it('toast writes an accessible status line', () => {
		const root = document.createElement('div');
		createHost({ ...config, toastRoot: root }).toast('Saved');
		expect(root.querySelector('[role="status"]')?.textContent).toBe('Saved');
	});

	it('is the woocommerce platform', () => {
		expect(createHost(config).platform).toBe('woocommerce');
	});
});
