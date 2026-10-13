import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import { CheckPanel, ConnectScreen, PluginProvider } from '@socialscore/plugin-core';
import { createHost } from '../../src/js/host';

const config = { restUrl: 'https://shop.test/wp-json/socialscore/v1/proxy', nonce: 'n0nce', dashboardUrl: 'https://shop.test/wp-admin/admin.php?page=socialscore' };

function fakeResponse(status: number, body: unknown) {
	return { ok: status >= 200 && status < 300, status, headers: { get: () => null }, text: async () => JSON.stringify(body) } as unknown as Response;
}

describe('shared screens inside the WooCommerce host', () => {
	it('ConnectScreen offers the connect URL', () => {
		const url = 'https://shop.test/wp-admin/admin-post.php?action=socialscore_connect_start&_wpnonce=x';
		const host = { ...createHost(config), navigate: jest.fn() };
		render(
			<PluginProvider host={host} storeId="">
				<ConnectScreen connectUrl={url} />
			</PluginProvider>
		);
		fireEvent.click(screen.getByRole('button'));
		expect(host.navigate).toHaveBeenCalledWith(url);
	});

	it('CheckPanel runs the check through the WP proxy and shows the plan message on 402', async () => {
		const fetchMock = jest.fn().mockResolvedValue(fakeResponse(402, { detail: { code: 'plan_required' } }));
		globalThis.fetch = fetchMock as unknown as typeof fetch;
		const host = createHost({ ...config, translations: window.socialscoreI18n });
		render(
			<PluginProvider host={host} storeId="42">
				<CheckPanel kind="order" externalId="1001" />
			</PluginProvider>
		);
		fireEvent.click(screen.getByRole('button', { name: /check/i }));
		await waitFor(() => expect(fetchMock).toHaveBeenCalled());

		const [url, init] = fetchMock.mock.calls[0];
		expect(url).toBe('https://shop.test/wp-json/socialscore/v1/proxy/api/v2/stores/42/checks');
		expect(init.method).toBe('POST');
		expect(JSON.parse(init.body)).toEqual({ order_external_id: '1001' });
		expect(init.headers['X-WP-Nonce']).toBe('n0nce');
		expect(init.headers['Idempotency-Key']).toBeTruthy();
		expect(await screen.findByText(/paid SocialScore plan/i)).toBeInTheDocument();
	});
});
