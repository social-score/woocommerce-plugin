import { createRoot } from 'react-dom/client';
import { ConnectScreen, PluginProvider, StoreDashboard } from '@socialscore/plugin-core';
import './styles.css';
import { createHost, parseProfile } from './host';

export function mount(root: HTMLElement): void {
	const data = root.dataset;
	const host = createHost({
		restUrl: data.restUrl ?? '',
		nonce: data.nonce ?? '',
		dashboardUrl: data.dashboardUrl ?? '',
		translations: window.socialscoreI18n,
		toastRoot: root,
	});
	// The screens read the host from the provider, also the connect screen (no store yet: empty id).
	createRoot(root).render(
		<PluginProvider host={host} storeId={data.storeId ?? ''}>
			{data.connected === '1' ? <StoreDashboard initialProfile={parseProfile(data.profile)} /> : <ConnectScreen connectUrl={data.connectUrl ?? ''} />}
		</PluginProvider>
	);
}

const root = document.getElementById('socialscore-root');
if (root) {
	mount(root);
}
