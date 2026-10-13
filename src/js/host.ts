import type { PluginHost } from '@socialscore/plugin-core';

export interface HostConfig {
	/** `rest_url('socialscore/v1/proxy')`: the WP REST proxy, never the SocialScore API itself. */
	restUrl: string;
	/** `wp_create_nonce('wp_rest')`. */
	nonce: string;
	/** Admin URL of the SocialScore page. */
	dashboardUrl: string;
	translations?: Record<string, string>;
	/** Where `toast` writes; created lazily. */
	toastRoot?: HTMLElement | null;
}

/** Joins the proxy URL and an API path, also when permalinks are plain (`?rest_route=`). */
export function proxyUrl(restUrl: string, path: string): string {
	const clean = path.startsWith('/') ? path : `/${path}`;
	if (restUrl.includes('?')) {
		return restUrl + encodeURI(clean).replace('?', '&');
	}
	return restUrl.replace(/\/$/, '') + clean;
}

/** `profile/customer/42` to the dashboard URL that opens that profile. */
export function profileUrl(dashboardUrl: string, to: string): string | null {
	const match = /^profile\/(customer|order)\/(.+)$/.exec(to);
	if (!match) {
		return null;
	}
	const sep = dashboardUrl.includes('?') ? '&' : '?';
	return `${dashboardUrl}${sep}profile=${match[1]}/${encodeURIComponent(match[2])}`;
}

export function parseProfile(value: string | undefined): { kind: 'customer' | 'order'; externalId: string } | undefined {
	const match = /^(customer|order)\/(.+)$/.exec(value ?? '');
	return match ? { kind: match[1] as 'customer' | 'order', externalId: decodeURIComponent(match[2]) } : undefined;
}

/** Flattens any `HeadersInit` into a plain object. */
function toRecord(init: HeadersInit | undefined): Record<string, string> {
	const out: Record<string, string> = {};
	if (!init) {
		return out;
	}
	if (Array.isArray(init)) {
		init.forEach(([key, value]) => {
			out[key] = value;
		});
	} else if (typeof (init as Headers).forEach === 'function') {
		(init as Headers).forEach((value, key) => {
			out[key] = value;
		});
	} else {
		Object.assign(out, init);
	}
	return out;
}

export function createHost(config: HostConfig): PluginHost {
	const translations = config.translations ?? {};
	return {
		platform: 'woocommerce',
		apiFetch(path, init = {}) {
			const headers = { ...toRecord(init.headers), 'X-WP-Nonce': config.nonce };
			return fetch(proxyUrl(config.restUrl, path), { ...init, headers, credentials: 'same-origin' });
		},
		navigate(to) {
			if (/^https?:\/\//.test(to)) {
				window.location.assign(to);
				return;
			}
			const url = profileUrl(config.dashboardUrl, to);
			if (url) {
				window.location.assign(url);
			}
		},
		toast(message) {
			const root = config.toastRoot ?? document.body;
			let node = root.querySelector<HTMLElement>('.socialscore-toast');
			if (!node) {
				node = document.createElement('div');
				node.className = 'socialscore-toast';
				node.setAttribute('role', 'status');
				root.appendChild(node);
			}
			node.textContent = message;
			window.setTimeout(() => {
				if (node && node.textContent === message) {
					node.textContent = '';
				}
			}, 5000);
		},
		t(key, vars) {
			const text = translations[key] ?? key;
			return vars ? text.replace(/\{(\w+)\}/g, (whole, name) => (name in vars ? String(vars[name]) : whole)) : text;
		},
	};
}

declare global {
	interface Window {
		socialscoreI18n?: Record<string, string>;
	}
}
