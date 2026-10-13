import { createRoot } from 'react-dom/client';
import { CheckPanel, PluginProvider } from '@socialscore/plugin-core';
import './styles.css';
import { createHost } from './host';

export function mountPanel(root: HTMLElement): void {
	const data = root.dataset;
	const kind = data.kind === 'order' ? 'order' : 'customer';
	const host = createHost({
		restUrl: data.restUrl ?? '',
		nonce: data.nonce ?? '',
		dashboardUrl: data.dashboardUrl ?? '',
		translations: window.socialscoreI18n,
		toastRoot: root,
	});
	createRoot(root).render(
		<PluginProvider host={host} storeId={data.storeId ?? ''}>
			<CheckPanel kind={kind} externalId={data.externalId ?? ''} />
		</PluginProvider>
	);
}

document.querySelectorAll<HTMLElement>('.socialscore-panel-root').forEach(mountPanel);
