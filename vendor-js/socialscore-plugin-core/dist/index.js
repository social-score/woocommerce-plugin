import { createContext as e, useCallback as t, useContext as n, useEffect as r, useMemo as i, useRef as a, useState as o } from "react";
import { Fragment as s, jsx as c, jsxs as l } from "react/jsx-runtime";
import { Badge as u, Button as d, Card as f, Checkbox as p, EmptyState as m, LevelIndicator as h, Spinner as g, Tab as _, TabList as v, TabPanel as y, Table as b, Tabs as x } from "@socialscore/ui";
//#region src/api/case.ts
var S = (e) => e.replace(/_([a-z0-9])/g, (e, t) => t.toUpperCase()), C = (e) => e.replace(/[A-Z]/g, (e) => `_${e.toLowerCase()}`);
function w(e) {
	return typeof e == "object" && !!e && !Array.isArray(e);
}
function T(e) {
	if (Array.isArray(e)) return e.map((e) => T(e));
	if (!w(e)) return e;
	let t = {};
	return Object.entries(e).forEach(([e, n]) => {
		n !== null && (t[S(e)] = T(n));
	}), t;
}
function E(e) {
	if (Array.isArray(e)) return e.map(E);
	if (!w(e)) return e;
	let t = {};
	return Object.entries(e).forEach(([e, n]) => {
		t[C(e)] = E(n);
	}), t;
}
//#endregion
//#region src/api/errors.ts
var D = class extends Error {
	constructor(e, t, n, r) {
		super(n ?? t ?? `Request failed with status ${e}`), this.name = "ApiError", this.status = e, this.code = t, this.retryAfter = r;
	}
};
//#endregion
//#region src/api/client.ts
function O(e) {
	return new Map(e.map((e) => [e.externalId, e.id]));
}
function k() {
	let e = globalThis.crypto;
	return e && typeof e.randomUUID == "function" ? e.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}
async function A(e) {
	let t = await e.text();
	if (t) try {
		return JSON.parse(t);
	} catch {
		return;
	}
}
function ee(e, t) {
	let n = t && typeof t == "object" ? t : {}, r = n.detail && typeof n.detail == "object" ? n.detail : n, i = typeof r.code == "string" ? r.code : typeof n.detail == "string" ? n.detail : void 0, a = typeof r.message == "string" ? r.message : void 0, o = e.headers.get("Retry-After"), s = o !== null && /^\d+$/.test(o) ? Number(o) : void 0;
	return new D(e.status, i, a, s);
}
var j = class {
	constructor(e, t) {
		this.host = e, this.storePath = `/api/v2/stores/${encodeURIComponent(String(t))}`;
	}
	async request(e, t, n = {}) {
		let r = {
			Accept: "application/json",
			...n.headers
		}, i = {
			method: e,
			headers: r
		};
		n.body !== void 0 && (r["Content-Type"] = "application/json", i.body = JSON.stringify(E(n.body)));
		let a = await this.host.apiFetch(t, i), o = await A(a);
		if (!a.ok) throw ee(a, o);
		return T(o);
	}
	getSettings() {
		return this.request("GET", `${this.storePath}/settings`);
	}
	updateSettings(e) {
		return this.request("PATCH", `${this.storePath}/settings`, { body: e });
	}
	getCostEstimate() {
		return this.request("GET", `${this.storePath}/settings/estimate?trigger=every_account`);
	}
	uninstall(e = {}) {
		return this.request("DELETE", e.redact ? `${this.storePath}?redact=true` : this.storePath);
	}
	pushCustomers(e) {
		return this.request("POST", `${this.storePath}/customers:batch`, { body: { customers: e } });
	}
	pushOrders(e) {
		return this.request("POST", `${this.storePath}/orders:batch`, { body: { orders: e } });
	}
	runCheck(e, t = k()) {
		let n = e.orderExternalId === void 0 ? { customerExternalId: e.customerExternalId } : { orderExternalId: e.orderExternalId };
		return this.request("POST", `${this.storePath}/checks`, {
			body: n,
			headers: { "Idempotency-Key": t }
		});
	}
	getCustomerProfile(e) {
		return this.request("GET", `${this.storePath}/customers/${encodeURIComponent(e)}/profile`);
	}
	getOrderProfile(e) {
		return this.request("GET", `${this.storePath}/orders/${encodeURIComponent(e)}/profile`);
	}
	getOverview() {
		return this.request("GET", `${this.storePath}/overview`);
	}
	listCheckedCustomers(e, t) {
		return this.request("GET", `${this.storePath}/customers?checked=true&page=${e}&page_size=${t}`);
	}
	async listSegments() {
		return (await this.request("GET", "/api/v2/segments")).items;
	}
	exportCustomer(e) {
		return this.request("GET", `${this.storePath}/customers/${encodeURIComponent(e)}/export`);
	}
	redactCustomer(e) {
		return this.request("DELETE", `${this.storePath}/customers/${encodeURIComponent(e)}`);
	}
	createHook(e) {
		return this.request("POST", "/api/v2/hooks", { body: e });
	}
	deleteHook(e) {
		return this.request("DELETE", `/api/v2/hooks/${encodeURIComponent(String(e))}`);
	}
	getHookSamples(e) {
		return this.request("GET", `/api/v2/hooks/samples/${encodeURIComponent(e)}`);
	}
}, M = e(null);
//#endregion
//#region src/PluginProvider.tsx
function N({ host: e, storeId: t, children: n }) {
	let r = String(t), a = i(() => ({
		host: e,
		storeId: r,
		api: new j(e, r)
	}), [e, r]);
	return /* @__PURE__ */ c(M.Provider, {
		value: a,
		children: n
	});
}
//#endregion
//#region src/messages.ts
var P = {
	"common.loading": "Loading...",
	"common.retry": "Try again",
	"common.back": "Back",
	"common.save": "Save changes",
	"common.cancel": "Cancel",
	"common.none": "None",
	"common.yes": "Yes",
	"common.no": "No",
	"error.generic": "Something went wrong. Try again in a moment.",
	"error.planRequired": "Checks need a paid SocialScore plan. Upgrade in SocialScore to use them.",
	"error.rateLimited": "Too many requests. Try again in {seconds} seconds.",
	"error.rateLimitedNoWait": "Too many requests. Try again in a moment.",
	"error.unauthorized": "Your SocialScore connection has expired. Connect the store again.",
	"level.strong": "Strong",
	"level.medium": "Medium",
	"level.weak": "Weak",
	"label.review_before_shipping": "Review before shipping",
	"label.possible_business_buyer": "Possible business buyer",
	"check.title": "SocialScore",
	"check.button": "Check",
	"check.checking": "Checking...",
	"check.done": "Check finished",
	"check.digitalPresence": "Digital presence",
	"check.emailQuality": "Email quality",
	"check.phoneQuality": "Phone quality",
	"check.fraudScore": "Fraud score",
	"check.businessBuyer": "Business buyer",
	"check.businessBuyerYes": "Business email",
	"check.businessBuyerNo": "Not detected",
	"check.noSignals": "No signals available for this profile yet.",
	"check.replayWithoutProfile": "This is a repeat of an earlier check and your organization stores no profiles. Check again to see the signals.",
	"check.seeFullProfile": "See full profile",
	"dashboard.title": "SocialScore",
	"dashboard.tabs": "SocialScore sections",
	"dashboard.tab.overview": "Overview",
	"dashboard.tab.profiles": "Profiles",
	"dashboard.tab.segments": "Segments",
	"dashboard.tab.settings": "Settings",
	"overview.checkedCustomers": "Checked customers",
	"overview.checkedOrders": "Checked orders",
	"overview.businessBuyers": "Business buyers",
	"overview.riskyOrders": "Risky orders",
	"overview.riskyOrdersEmpty": "No risky orders so far.",
	"overview.riskyOrdersCaption": "Orders to review before shipping",
	"overview.fewerThanFive": "Fewer than 5",
	"overview.column.order": "Order",
	"overview.column.placed": "Placed",
	"overview.column.total": "Total",
	"overview.column.labels": "Labels",
	"overview.viewOrder": "View profile of order {id}",
	"profiles.caption": "Checked customers",
	"profiles.empty": "No customers have been checked yet.",
	"profiles.column.customer": "Customer",
	"profiles.column.email": "Email",
	"profiles.column.phone": "Phone",
	"profiles.column.fraud": "Fraud risk",
	"profiles.column.presence": "Digital presence",
	"profiles.column.labels": "Labels",
	"profiles.column.checked": "Checked",
	"profiles.open": "Open profile of {id}",
	"segments.title": "Segments and audiences",
	"segments.empty": "No segments yet.",
	"segments.caption": "Segments",
	"segments.column.name": "Segment",
	"segments.column.size": "People",
	"segments.sizeBand.small": "Fewer than 5",
	"segments.editInSocialScore": "This list is read-only here. Create and edit segments and audiences in SocialScore.",
	"profile.title": "Profile",
	"profile.loading": "Loading profile...",
	"profile.empty": "No profile data is available for this record yet.",
	"profile.masked": "Hidden for your role",
	"profile.confidence": "Confidence: {level}",
	"profile.source": "Source: {source}",
	"profile.reason": "Why: {reason}",
	"profile.collectedAt": "Collected {date}",
	"profile.noValue": "No value",
	"profile.category.identity": "Identity",
	"profile.category.contact": "Contact",
	"profile.category.digitalFootprint": "Digital footprint",
	"profile.category.interests": "Interests",
	"profile.category.professional": "Professional",
	"profile.category.b2b": "Business buyer",
	"profile.category.healthSignals": "Health signals",
	"profile.category.segments": "Segments",
	"profile.category.ownData": "Own data",
	"settings.title": "Automatic enrichment",
	"settings.intro": "Choose when SocialScore checks customers and orders automatically.",
	"settings.status": "Connection status",
	"settings.status.active": "Connected",
	"settings.status.disconnected": "Disconnected",
	"settings.status.uninstalled": "Uninstalled",
	"settings.disconnectedNote": "The SocialScore member who connected this store is no longer active, so customer details are hidden. Connect the store again.",
	"settings.trigger.onNewAccount": "On a new account",
	"settings.trigger.everyAccount": "For every account (including existing ones)",
	"settings.trigger.orderNewCustomer": "On an order from a new customer",
	"settings.trigger.orderAnyCustomer": "On an order from any customer",
	"settings.trigger.manualOnly": "Manual only (Check button)",
	"settings.costPreview": "Turning this on will run up to {count} Explores. {reused} customers already have a valid check and cost nothing.",
	"settings.costLoading": "Counting how many checks this will run...",
	"settings.costConfirm": "Turn on for every account",
	"settings.saved": "Settings saved",
	"settings.saveFailed": "Settings were not saved.",
	"connect.title": "Connect your store to SocialScore",
	"connect.description.woocommerce": "Log in to SocialScore and approve access. Your WooCommerce store is then linked to your organization.",
	"connect.description.shopify": "Log in to SocialScore and approve access. Your Shopify store is then linked to your organization.",
	"connect.button": "Connect to SocialScore"
};
//#endregion
//#region src/lib/format.ts
function F(e) {
	return `profile/${e.kind}/${encodeURIComponent(e.externalId)}`;
}
function te(e, t) {
	if (e === void 0) return "";
	let n = Number(e);
	if (!t || Number.isNaN(n)) return t ? `${e} ${t}` : String(e);
	try {
		return new Intl.NumberFormat(void 0, {
			style: "currency",
			currency: t
		}).format(n);
	} catch {
		return `${e} ${t}`;
	}
}
function I(e) {
	if (!e) return "";
	let t = new Date(e);
	return Number.isNaN(t.getTime()) ? e : t.toLocaleDateString();
}
function L(e) {
	let t = e.replace(/([A-Z])/g, " $1").toLowerCase().trim();
	return t.charAt(0).toUpperCase() + t.slice(1);
}
//#endregion
//#region src/hooks/usePlugin.ts
function R() {
	let e = n(M);
	if (!e) throw Error("Plugin screens must be rendered inside <PluginProvider>");
	return e;
}
var z = () => R().api, B = () => R().host;
function V(e, t) {
	return t ? e.replace(/\{(\w+)\}/g, (e, n) => n in t ? String(t[n]) : e) : e;
}
function H() {
	let { host: e } = R();
	return t((t, n) => {
		let r = e.t?.(t, n);
		return r && r !== t ? r : V(P[t], n);
	}, [e]);
}
//#endregion
//#region src/components/CheckLabels.tsx
function U({ labels: e }) {
	let t = H();
	return e.length === 0 ? null : /* @__PURE__ */ c("ul", {
		className: "ss-pc-labels",
		children: e.map((e) => /* @__PURE__ */ c("li", { children: /* @__PURE__ */ c(u, {
			variant: e === "review_before_shipping" ? "warning" : "neutral",
			children: t(`label.${e}`)
		}) }, e))
	});
}
//#endregion
//#region src/components/CheckSignals.tsx
function W({ profile: e }) {
	let t = H(), n = e.categories.healthSignals?.fields, r = e.categories.b2b?.fields.isBusinessEmail, i = [
		{
			key: "presence",
			label: t("check.digitalPresence"),
			field: n?.socialScore
		},
		{
			key: "email",
			label: t("check.emailQuality"),
			field: n?.emailScore
		},
		{
			key: "phone",
			label: t("check.phoneQuality"),
			field: n?.phoneScore
		},
		{
			key: "fraud",
			label: t("check.fraudScore"),
			field: n?.fraudScore,
			inverted: !0
		}
	].filter((e) => e.field);
	return i.length === 0 && !r ? /* @__PURE__ */ c("p", {
		className: "ss-pc-muted",
		children: t("check.noSignals")
	}) : /* @__PURE__ */ l("ul", {
		className: "ss-pc-signals",
		children: [i.map(({ key: e, label: n, field: r, inverted: i }) => /* @__PURE__ */ c("li", {
			className: "ss-pc-signal",
			children: r?.value === void 0 ? /* @__PURE__ */ l(s, { children: [/* @__PURE__ */ c("span", {
				className: "ss-pc-signal-label",
				children: n
			}), /* @__PURE__ */ c("span", {
				className: "ss-pc-muted",
				children: r?.masked ? t("profile.masked") : t("profile.noValue")
			})] }) : /* @__PURE__ */ c(h, {
				label: n,
				value: r.value,
				inverted: i,
				showLevelName: !0,
				valueLabel: /* @__PURE__ */ c("span", {
					className: "ss-pc-signal-value",
					children: Math.round(r.value)
				})
			})
		}, e)), r && /* @__PURE__ */ l("li", {
			className: "ss-pc-signal",
			children: [/* @__PURE__ */ c("span", {
				className: "ss-pc-signal-label",
				children: t("check.businessBuyer")
			}), /* @__PURE__ */ c("span", { children: r.value === void 0 ? r.masked ? t("profile.masked") : t("profile.noValue") : r.value ? t("check.businessBuyerYes") : t("check.businessBuyerNo") })]
		})]
	});
}
//#endregion
//#region src/hooks/useErrorText.ts
function G() {
	let e = H();
	return (t) => {
		if (t instanceof D) {
			if (t.status === 402 || t.code === "plan_required") return e("error.planRequired");
			if (t.status === 429) return t.retryAfter === void 0 ? e("error.rateLimitedNoWait") : e("error.rateLimited", { seconds: t.retryAfter });
			if (t.status === 401) return e("error.unauthorized");
		}
		return e("error.generic");
	};
}
//#endregion
//#region src/components/ErrorNote.tsx
function K({ error: e, onRetry: t }) {
	let n = H(), r = G()(e);
	return /* @__PURE__ */ l("div", {
		role: "alert",
		className: "ss-pc-error",
		children: [/* @__PURE__ */ c("p", {
			className: "ss-pc-error-text",
			children: r
		}), t && /* @__PURE__ */ c(d, {
			variant: "outlined-primary",
			onClick: t,
			children: n("common.retry")
		})]
	});
}
//#endregion
//#region src/screens/CheckPanel.tsx
function ne({ kind: e, externalId: t }) {
	let n = z(), r = B(), i = H(), [u, p] = o(!1), [m, h] = o(), [_, v] = o(), y = a(void 0);
	return /* @__PURE__ */ c(f, {
		title: i("check.title"),
		headingLevel: 3,
		hideWhenEmpty: !1,
		children: /* @__PURE__ */ l("div", {
			className: "ss-pc-panel",
			children: [
				m && /* @__PURE__ */ l(s, { children: [
					/* @__PURE__ */ c(U, { labels: m.labels }),
					m.profile && /* @__PURE__ */ c(W, { profile: m.profile }),
					m.replayWithoutProfile && /* @__PURE__ */ c("p", {
						className: "ss-pc-muted",
						children: i("check.replayWithoutProfile")
					})
				] }),
				_ !== void 0 && /* @__PURE__ */ c(K, { error: _ }),
				u && /* @__PURE__ */ c(g, { label: i("check.checking") }),
				/* @__PURE__ */ l("div", {
					className: "ss-pc-actions",
					children: [/* @__PURE__ */ c(d, {
						variant: "contained-primary",
						disabled: u,
						onClick: async () => {
							p(!0), v(void 0), y.current ??= k();
							try {
								let r = e === "order" ? { orderExternalId: t } : { customerExternalId: t };
								h(await n.runCheck(r, y.current)), y.current = void 0;
							} catch (e) {
								v(e);
							} finally {
								p(!1);
							}
						},
						children: i("check.button")
					}), m && /* @__PURE__ */ c(d, {
						variant: "link",
						onClick: () => r.navigate(F({
							kind: e,
							externalId: t
						})),
						children: i("check.seeFullProfile")
					})]
				})
			]
		})
	});
}
function re(e) {
	return /* @__PURE__ */ c(ne, { ...e }, `${e.kind}:${e.externalId}`);
}
//#endregion
//#region src/screens/ConnectScreen.tsx
function ie({ connectUrl: e }) {
	let t = B(), n = H();
	return /* @__PURE__ */ l(f, {
		title: n("connect.title"),
		headingLevel: 2,
		children: [/* @__PURE__ */ c("p", {
			className: "ss-pc-text",
			children: n(`connect.description.${t.platform}`)
		}), /* @__PURE__ */ c(d, {
			variant: "contained-primary",
			onClick: () => t.navigate(e),
			children: n("connect.button")
		})]
	});
}
//#endregion
//#region src/components/FieldRow.tsx
function ae(e, t, n) {
	return typeof e == "boolean" ? e ? t : n : typeof e == "object" ? JSON.stringify(e) : String(e);
}
function oe({ label: e, field: t }) {
	let n = H(), r = t.confidenceLevel, i = [
		t.sourceDetail ? `${L(t.source)}: ${t.sourceDetail}` : n("profile.source", { source: L(t.source) }),
		t.reason ? n("profile.reason", { reason: t.reason }) : "",
		t.collectedAt ? n("profile.collectedAt", { date: I(t.collectedAt) }) : ""
	].filter(Boolean);
	return /* @__PURE__ */ l("div", {
		className: "ss-pc-field",
		children: [/* @__PURE__ */ c("dt", {
			className: "ss-pc-field-label",
			children: e
		}), /* @__PURE__ */ l("dd", {
			className: "ss-pc-field-body",
			children: [
				/* @__PURE__ */ c("span", {
					className: "ss-pc-field-value",
					children: t.masked ? n("profile.masked") : t.value === void 0 ? n("profile.noValue") : ae(t.value, n("common.yes"), n("common.no"))
				}),
				r && /* @__PURE__ */ c(u, {
					variant: r,
					"aria-label": n("profile.confidence", { level: n(`level.${r}`) }),
					children: n(`level.${r}`)
				}),
				i.length > 0 && /* @__PURE__ */ c("span", {
					className: "ss-pc-field-meta",
					children: i.join(" · ")
				})
			]
		})]
	});
}
//#endregion
//#region src/hooks/useResource.ts
function q(e) {
	let [n, i] = o({ loading: !0 }), [a, s] = o(0);
	r(() => {
		let t = !1;
		return i((e) => ({
			data: e.data,
			loading: !0
		})), e().then((e) => {
			t || i({
				data: e,
				loading: !1
			});
		}, (e) => {
			t || i({
				error: e,
				loading: !1
			});
		}), () => {
			t = !0;
		};
	}, [e, a]);
	let c = t(() => s((e) => e + 1), []);
	return {
		...n,
		reload: c
	};
}
//#endregion
//#region src/lib/profile.ts
function J(e) {
	return typeof e == "object" && !!e && "source" in e && "reason" in e && "masked" in e;
}
//#endregion
//#region src/screens/ProfileView.tsx
var se = [
	"identity",
	"contact",
	"digitalFootprint",
	"interests",
	"professional",
	"b2b",
	"healthSignals",
	"segments",
	"ownData"
];
function ce(e) {
	let t = [];
	return Object.entries(e).forEach(([e, n]) => {
		(Array.isArray(n) ? n : [n]).forEach((n, r) => {
			if (J(n)) {
				t.push({
					key: `${e}-${r}`,
					label: L(e),
					field: n
				});
				return;
			}
			if (typeof n != "object" || !n) return;
			let i = n, a = [
				i.platform,
				i.category,
				i.segmentLabel,
				i.title
			].find((e) => typeof e == "string");
			Object.entries(i).forEach(([n, i]) => {
				J(i) && t.push({
					key: `${e}-${r}-${n}`,
					label: a ?? L(n),
					field: i
				});
			});
		});
	}), t;
}
function Y({ kind: e, externalId: n, onBack: r }) {
	let i = z(), a = H(), { data: o, error: s, loading: u, reload: p } = q(t(() => e === "order" ? i.getOrderProfile(n) : i.getCustomerProfile(n), [
		i,
		e,
		n
	])), h = o?.categories ?? {}, _ = se.map((e) => {
		let t = h[e];
		return {
			name: e,
			rows: t && t.status === "available" ? ce(t.fields) : []
		};
	}).filter((e) => e.rows.length > 0);
	return /* @__PURE__ */ l("div", {
		className: "ss-pc-profile",
		children: [
			/* @__PURE__ */ l("div", {
				className: "ss-pc-profile-head",
				children: [r && /* @__PURE__ */ c(d, {
					variant: "link",
					onClick: r,
					children: a("common.back")
				}), /* @__PURE__ */ c("h2", {
					className: "ss-pc-heading",
					children: a("profile.title")
				})]
			}),
			u && !o && /* @__PURE__ */ c(g, { label: a("profile.loading") }),
			s !== void 0 && /* @__PURE__ */ c(K, {
				error: s,
				onRetry: p
			}),
			o && _.length === 0 && /* @__PURE__ */ c(m, { title: a("profile.empty") }),
			_.map(({ name: e, rows: t }) => /* @__PURE__ */ c(f, {
				title: a(`profile.category.${e}`),
				headingLevel: 3,
				children: /* @__PURE__ */ c("dl", {
					className: "ss-pc-fields",
					children: t.map((e) => /* @__PURE__ */ c(oe, {
						label: e.label,
						field: e.field
					}, e.key))
				})
			}, e))
		]
	});
}
//#endregion
//#region src/screens/SettingsScreen.tsx
var X = [
	"onNewAccount",
	"everyAccount",
	"orderNewCustomer",
	"orderAnyCustomer",
	"manualOnly"
], le = {
	active: "success",
	disconnected: "warning",
	uninstalled: "error"
};
function Z() {
	let e = z(), n = B(), i = H(), { data: a, error: m, loading: h, reload: _ } = q(t(() => e.getSettings(), [e])), [v, y] = o(), [b, x] = o(!1), [S, C] = o(), [w, T] = o();
	r(() => {
		a && y(a.settings);
	}, [a]);
	let E = () => {
		T("loading"), e.getCostEstimate().then(T, () => T("failed"));
	}, D = (e, t) => {
		if (v) {
			if (e === "everyAccount" && t) {
				E();
				return;
			}
			y({
				...v,
				[e]: t
			});
		}
	}, O = async () => {
		if (!a || !v) return;
		let t = {};
		X.forEach((e) => {
			v[e] !== a.settings[e] && (t[e] = v[e]);
		}), x(!0), C(void 0);
		try {
			await e.updateSettings(t), n.toast(i("settings.saved")), _();
		} catch (e) {
			C(e);
		} finally {
			x(!1);
		}
	};
	if (m !== void 0) return /* @__PURE__ */ c(K, {
		error: m,
		onRetry: _
	});
	if (!v || !a) return h ? /* @__PURE__ */ c(g, { label: i("common.loading") }) : /* @__PURE__ */ c("div", {});
	let k = a.status, A = X.some((e) => v[e] !== a.settings[e]);
	return /* @__PURE__ */ l(f, {
		title: i("settings.title"),
		headingLevel: 2,
		children: [
			/* @__PURE__ */ c("p", {
				className: "ss-pc-text",
				children: i("settings.intro")
			}),
			k && /* @__PURE__ */ l("p", {
				className: "ss-pc-status",
				children: [/* @__PURE__ */ l("span", { children: [i("settings.status"), ": "] }), /* @__PURE__ */ c(u, {
					variant: le[k],
					children: i(`settings.status.${k}`)
				})]
			}),
			k === "disconnected" && /* @__PURE__ */ c("p", {
				className: "ss-pc-text",
				children: i("settings.disconnectedNote")
			}),
			/* @__PURE__ */ l("fieldset", {
				className: "ss-pc-triggers",
				children: [/* @__PURE__ */ c("legend", {
					className: "ss-visually-hidden",
					children: i("settings.title")
				}), X.map((e) => /* @__PURE__ */ c(p, {
					label: i(`settings.trigger.${e}`),
					checked: v[e],
					onChange: (t) => D(e, t.target.checked)
				}, e))]
			}),
			w && /* @__PURE__ */ l("div", {
				className: "ss-pc-cost",
				role: "status",
				children: [
					w === "loading" && /* @__PURE__ */ c("p", { children: i("settings.costLoading") }),
					w === "failed" && /* @__PURE__ */ c(K, {
						error: void 0,
						onRetry: E
					}),
					typeof w == "object" && /* @__PURE__ */ l(s, { children: [/* @__PURE__ */ c("p", { children: i("settings.costPreview", {
						count: w.exploresMax,
						reused: w.reusedKnown
					}) }), /* @__PURE__ */ l("div", {
						className: "ss-pc-actions",
						children: [/* @__PURE__ */ c(d, {
							variant: "contained-primary",
							onClick: () => {
								y({
									...v,
									everyAccount: !0
								}), T(void 0);
							},
							children: i("settings.costConfirm")
						}), /* @__PURE__ */ c(d, {
							variant: "outlined-primary",
							onClick: () => T(void 0),
							children: i("common.cancel")
						})]
					})] })
				]
			}),
			S !== void 0 && /* @__PURE__ */ c(K, { error: S }),
			/* @__PURE__ */ c("div", {
				className: "ss-pc-actions",
				children: /* @__PURE__ */ c(d, {
					variant: "contained-primary",
					disabled: !A || b,
					onClick: O,
					children: i("common.save")
				})
			})
		]
	});
}
//#endregion
//#region src/screens/StoreDashboard.tsx
var ue = [
	"overview",
	"profiles",
	"segments",
	"settings"
];
function Q({ level: e }) {
	let t = H();
	return e ? /* @__PURE__ */ c(u, {
		variant: e,
		children: t(`level.${e}`)
	}) : null;
}
function de({ openProfile: e }) {
	let n = z(), r = H(), { data: i, error: a, loading: o, reload: s } = q(t(() => n.getOverview(), [n]));
	if (a !== void 0) return /* @__PURE__ */ c(K, {
		error: a,
		onRetry: s
	});
	if (!i) return o ? /* @__PURE__ */ c(g, { label: r("common.loading") }) : /* @__PURE__ */ c("div", {});
	let u = (e) => e ?? r("overview.fewerThanFive"), p = [
		{
			key: "externalId",
			header: r("overview.column.order"),
			render: (t) => /* @__PURE__ */ c(d, {
				variant: "link",
				"aria-label": r("overview.viewOrder", { id: t.externalId }),
				onClick: () => e({
					kind: "order",
					externalId: t.externalId
				}),
				children: t.externalId
			})
		},
		{
			key: "placedAt",
			header: r("overview.column.placed"),
			render: (e) => I(e.placedAt)
		},
		{
			key: "total",
			header: r("overview.column.total"),
			align: "end",
			render: (e) => te(e.total, e.currency)
		},
		{
			key: "labels",
			header: r("overview.column.labels"),
			render: (e) => /* @__PURE__ */ c(U, { labels: e.labels })
		}
	];
	return /* @__PURE__ */ l("div", {
		className: "ss-pc-overview",
		children: [/* @__PURE__ */ l("div", {
			className: "ss-pc-stats",
			children: [
				/* @__PURE__ */ c(f, {
					title: r("overview.checkedCustomers"),
					headingLevel: 3,
					children: /* @__PURE__ */ c("p", {
						className: "ss-pc-stat",
						children: u(i.checkedCustomers)
					})
				}),
				/* @__PURE__ */ c(f, {
					title: r("overview.checkedOrders"),
					headingLevel: 3,
					children: /* @__PURE__ */ c("p", {
						className: "ss-pc-stat",
						children: u(i.checkedOrders)
					})
				}),
				/* @__PURE__ */ c(f, {
					title: r("overview.businessBuyers"),
					headingLevel: 3,
					children: /* @__PURE__ */ c("p", {
						className: "ss-pc-stat",
						children: u(i.businessBuyers)
					})
				})
			]
		}), /* @__PURE__ */ c(f, {
			title: r("overview.riskyOrders"),
			headingLevel: 3,
			hideWhenEmpty: !1,
			children: /* @__PURE__ */ c(b, {
				caption: r("overview.riskyOrdersCaption"),
				columns: p,
				rows: i.riskyOrders,
				getRowKey: (e) => e.externalId,
				empty: r("overview.riskyOrdersEmpty")
			})
		})]
	});
}
var $ = 20;
function fe({ openProfile: e }) {
	let n = z(), r = H(), [i, a] = o(1), { data: s, error: l, loading: u, reload: f } = q(t(() => n.listCheckedCustomers(i, $), [n, i]));
	if (l !== void 0) return /* @__PURE__ */ c(K, {
		error: l,
		onRetry: f
	});
	if (!s) return u ? /* @__PURE__ */ c(g, { label: r("common.loading") }) : /* @__PURE__ */ c("div", {});
	let p = [
		{
			key: "externalId",
			header: r("profiles.column.customer"),
			render: (t) => /* @__PURE__ */ c(d, {
				variant: "link",
				"aria-label": r("profiles.open", { id: t.externalId }),
				onClick: () => e({
					kind: "customer",
					externalId: t.externalId
				}),
				children: t.externalId
			})
		},
		{
			key: "email",
			header: r("profiles.column.email"),
			render: (e) => /* @__PURE__ */ c(Q, { level: e.health.email })
		},
		{
			key: "phone",
			header: r("profiles.column.phone"),
			render: (e) => /* @__PURE__ */ c(Q, { level: e.health.phone })
		},
		{
			key: "fraudLevel",
			header: r("profiles.column.fraud"),
			render: (e) => /* @__PURE__ */ c(Q, { level: e.health.fraudLevel })
		},
		{
			key: "digitalPresence",
			header: r("profiles.column.presence"),
			render: (e) => /* @__PURE__ */ c(Q, { level: e.health.digitalPresence })
		},
		{
			key: "labels",
			header: r("profiles.column.labels"),
			render: (e) => /* @__PURE__ */ c(U, { labels: e.labels })
		},
		{
			key: "checkedAt",
			header: r("profiles.column.checked"),
			render: (e) => I(e.checkedAt)
		}
	];
	return /* @__PURE__ */ c(b, {
		caption: r("profiles.caption"),
		columns: p,
		rows: s.items,
		getRowKey: (e) => e.externalId,
		pageSize: $,
		page: i,
		onPageChange: a,
		totalRecords: s.total,
		empty: /* @__PURE__ */ c(m, { title: r("profiles.empty") })
	});
}
function pe() {
	let e = z(), n = H(), { data: r, error: i, loading: a, reload: o } = q(t(() => e.listSegments(), [e]));
	if (i !== void 0) return /* @__PURE__ */ c(K, {
		error: i,
		onRetry: o
	});
	if (!r) return a ? /* @__PURE__ */ c(g, { label: n("common.loading") }) : /* @__PURE__ */ c("div", {});
	let s = [{
		key: "name",
		header: n("segments.column.name")
	}, {
		key: "sizeBand",
		header: n("segments.column.size"),
		align: "end",
		render: (e) => e.sizeBand ?? n("segments.sizeBand.small")
	}];
	return /* @__PURE__ */ l("div", { children: [/* @__PURE__ */ c("p", {
		className: "ss-pc-text",
		children: n("segments.editInSocialScore")
	}), /* @__PURE__ */ c(b, {
		caption: n("segments.caption"),
		columns: s,
		rows: r,
		getRowKey: (e) => e.id,
		empty: /* @__PURE__ */ c(m, { title: n("segments.empty") })
	})] });
}
function me({ initialProfile: e, initialTab: t = "overview" }) {
	let n = H(), [r, i] = o(t), [a, s] = o(e);
	return a ? /* @__PURE__ */ c(Y, {
		kind: a.kind,
		externalId: a.externalId,
		onBack: () => s(void 0)
	}) : /* @__PURE__ */ l("div", {
		className: "ss-pc-dashboard",
		children: [/* @__PURE__ */ c("h1", {
			className: "ss-pc-heading",
			children: n("dashboard.title")
		}), /* @__PURE__ */ l(x, {
			value: r,
			onValueChange: i,
			children: [
				/* @__PURE__ */ c(v, {
					"aria-label": n("dashboard.tabs"),
					children: ue.map((e) => /* @__PURE__ */ c(_, {
						value: e,
						children: n(`dashboard.tab.${e}`)
					}, e))
				}),
				/* @__PURE__ */ c(y, {
					value: "overview",
					children: /* @__PURE__ */ c(de, { openProfile: s })
				}),
				/* @__PURE__ */ c(y, {
					value: "profiles",
					children: /* @__PURE__ */ c(fe, { openProfile: s })
				}),
				/* @__PURE__ */ c(y, {
					value: "segments",
					children: /* @__PURE__ */ c(pe, {})
				}),
				/* @__PURE__ */ c(y, {
					value: "settings",
					children: /* @__PURE__ */ c(Z, {})
				})
			]
		})]
	});
}
//#endregion
export { j as ApiClient, D as ApiError, re as CheckPanel, ie as ConnectScreen, N as PluginProvider, Y as ProfileView, Z as SettingsScreen, me as StoreDashboard, O as batchIdMap, P as messages, F as profilePath };

//# sourceMappingURL=index.js.map