import { Fragment as e, jsx as t, jsxs as n } from "react/jsx-runtime";
import { Children as r, cloneElement as i, createContext as a, forwardRef as o, isValidElement as s, useCallback as c, useContext as l, useEffect as u, useId as d, useLayoutEffect as f, useMemo as p, useRef as m, useState as h } from "react";
import { createPortal as g } from "react-dom";
//#region src/components/Button/Button.tsx
function _({ variant: e, className: n, disabled: r, children: i, ...a }) {
	let o = ["ss-button"];
	return r && e === "link" ? o.push("ss-button--link", "ss-button--link-disabled") : r ? o.push("ss-button--disabled") : e && o.push(`ss-button--${e}`), n && o.push(n), /* @__PURE__ */ t("button", {
		className: o.join(" "),
		disabled: r,
		...a,
		children: i
	});
}
//#endregion
//#region src/tokens.ts
var v = /* @__PURE__ */ "white.black.gray-50.gray-100.gray-200.gray-300.gray-400.gray-500.navy-200.navy-500.navy-700.navy-900.blue-50.blue-100.blue-500.blue-700.blue-800.violet-200.violet-300.violet-400.violet-500.violet-600.violet-700.teal-200.teal-400.teal-700.sage-300.sage-500.red-500.red-600.orange-400.orange-700".split(".");
function y(e) {
	return `var(--ss-color-${e})`;
}
function b(e, t) {
	return typeof window > "u" ? "" : getComputedStyle(t ?? document.documentElement).getPropertyValue(e).trim();
}
function x(e, t) {
	return b(`--ss-color-${e}`, t);
}
//#endregion
//#region src/components/Input/Input.tsx
var S = o(function({ label: e, hint: r, error: i, size: a = "md", endAdornment: o, className: s, id: c, required: l, "aria-describedby": u, ...f }, p) {
	let m = d(), h = c ?? m, g = `${h}-hint`, _ = `${h}-error`, v = !!i, y = [
		u,
		r ? g : void 0,
		v ? _ : void 0
	].filter(Boolean).join(" "), b = ["ss-input"];
	a === "sm" && b.push("ss-input--sm"), v && b.push("ss-input--error");
	let x = /* @__PURE__ */ t("input", {
		ref: p,
		id: h,
		className: b.join(" "),
		required: l,
		"aria-invalid": v || void 0,
		"aria-describedby": y || void 0,
		...f
	});
	return /* @__PURE__ */ n("div", {
		className: s ? `ss-field ${s}` : "ss-field",
		children: [
			e && /* @__PURE__ */ n("label", {
				className: "ss-field-label",
				htmlFor: h,
				children: [e, l && /* @__PURE__ */ t("span", {
					className: "ss-field-required",
					"aria-hidden": "true",
					children: " *"
				})]
			}),
			o ? /* @__PURE__ */ n("div", {
				className: "ss-input-wrap",
				children: [x, /* @__PURE__ */ t("span", {
					className: "ss-input-adornment",
					"aria-hidden": "true",
					children: o
				})]
			}) : x,
			r && /* @__PURE__ */ t("span", {
				id: g,
				className: "ss-field-hint",
				children: r
			}),
			v && /* @__PURE__ */ t("span", {
				id: _,
				className: "ss-field-error",
				role: "alert",
				children: i
			})
		]
	});
});
//#endregion
//#region src/components/Select/Select.tsx
function C({ label: e, value: r, options: i, onChange: a, placeholder: o, emptyText: s = "No options", hint: c, error: l, required: u, disabled: f, size: p = "md", getOptionLabel: g, renderValue: _, name: v, id: y, "aria-label": b, className: x }) {
	let S = d(), C = y ?? S, w = `${C}-label`, T = `${C}-list`, E = `${C}-hint`, D = `${C}-error`, O = (e) => `${C}-option-${e}`, k = (i ?? []).map((e) => {
		let t = typeof e == "string" ? e : e.value;
		return {
			value: t,
			label: (typeof e == "string" ? void 0 : e.label) ?? (g ? g(t) : t),
			disabled: typeof e != "string" && !!e.disabled
		};
	}), A = Array.isArray(r), j = r ?? "", M = (e) => Array.isArray(j) ? j.includes(e.value) : e.value === j, N = k.findIndex(M), [P, F] = h(!1), [I, L] = h(-1), R = m({
		text: "",
		timer: 0
	}), z = k.flatMap((e, t) => e.disabled ? [] : [t]), B = !!l, V = (e) => {
		f || (L(e), F(!0));
	}, H = () => F(!1), U = (e) => {
		let t = k[e];
		t && !t.disabled && (a(t.value), A || F(!1));
	}, W = (e) => {
		if (z.length === 0) return;
		let t = z.indexOf(I);
		if (t === -1) {
			L(e === 1 ? z[0] : z[z.length - 1]);
			return;
		}
		let n = Math.min(Math.max(t + e, 0), z.length - 1);
		L(z[n]);
	}, G = () => N !== -1 && !k[N].disabled ? N : z[0] ?? -1, K = (e) => {
		let t = R.current;
		window.clearTimeout(t.timer), t.text += e.toLowerCase(), t.timer = window.setTimeout(() => {
			t.text = "";
		}, 500);
		let n = (e) => typeof k[e].label == "string" || typeof k[e].label == "number" ? String(k[e].label).toLowerCase().startsWith(t.text) : k[e].value.toLowerCase().startsWith(t.text), r = z.indexOf(I), i = [...z.slice(r + 1), ...z.slice(0, r + 1)].find(n);
		i !== void 0 && (P || F(!0), L(i));
	}, q = (e) => {
		if (!(f || e.altKey || e.ctrlKey || e.metaKey)) switch (e.key) {
			case "ArrowDown":
				e.preventDefault(), P ? W(1) : V(G());
				break;
			case "ArrowUp":
				e.preventDefault(), P ? W(-1) : V(G());
				break;
			case "Home":
				e.preventDefault(), P || F(!0), L(z[0] ?? -1);
				break;
			case "End":
				e.preventDefault(), P || F(!0), L(z[z.length - 1] ?? -1);
				break;
			case "Enter":
			case " ":
				e.preventDefault(), P ? U(I) : V(G());
				break;
			case "Escape":
				P && (e.preventDefault(), H());
				break;
			default: e.key.length === 1 && K(e.key);
		}
	}, J = k.filter(M), Y;
	Y = _ && j.length > 0 ? _(j) : J.length > 0 ? J.map((e, t) => /* @__PURE__ */ n("span", { children: [t > 0 && ", ", e.label] }, e.value)) : /* @__PURE__ */ t("span", {
		className: "ss-select-placeholder",
		children: o
	});
	let ee = [c ? E : void 0, B ? D : void 0].filter(Boolean).join(" "), X = ["ss-select-trigger"];
	return p === "sm" && X.push("ss-select-trigger--sm"), B && X.push("ss-select-trigger--error"), f && X.push("ss-select-trigger--disabled"), /* @__PURE__ */ n("div", {
		className: x ? `ss-field ${x}` : "ss-field",
		children: [
			e && /* @__PURE__ */ n("span", {
				className: "ss-field-label",
				id: w,
				children: [e, u && /* @__PURE__ */ t("span", {
					className: "ss-field-required",
					"aria-hidden": "true",
					children: " *"
				})]
			}),
			/* @__PURE__ */ n("div", {
				className: "ss-select-control",
				children: [/* @__PURE__ */ n("div", {
					id: C,
					role: "combobox",
					className: X.join(" "),
					tabIndex: f ? -1 : 0,
					"aria-haspopup": "listbox",
					"aria-expanded": P,
					"aria-controls": T,
					"aria-activedescendant": P && I >= 0 ? O(I) : void 0,
					"aria-labelledby": e ? w : void 0,
					"aria-label": e ? void 0 : b,
					"aria-required": u || void 0,
					"aria-disabled": f || void 0,
					"aria-invalid": B || void 0,
					"aria-describedby": ee || void 0,
					onClick: () => P ? H() : V(G()),
					onBlur: H,
					onKeyDown: q,
					children: [/* @__PURE__ */ t("span", {
						className: "ss-select-value",
						children: Y
					}), /* @__PURE__ */ t("svg", {
						className: P ? "ss-select-arrow ss-select-arrow--open" : "ss-select-arrow",
						viewBox: "0 0 12 8",
						"aria-hidden": "true",
						focusable: "false",
						children: /* @__PURE__ */ t("path", {
							d: "M1 1.5 6 6.5 11 1.5",
							fill: "none",
							stroke: "currentColor",
							strokeWidth: "1.8"
						})
					})]
				}), /* @__PURE__ */ n("ul", {
					id: T,
					role: "listbox",
					"aria-labelledby": e ? w : void 0,
					"aria-label": e ? void 0 : b,
					"aria-multiselectable": A || void 0,
					className: P ? "ss-select-list ss-select-list--open" : "ss-select-list",
					hidden: !P,
					children: [k.length === 0 && /* @__PURE__ */ t("li", {
						role: "presentation",
						className: "ss-select-empty",
						children: s
					}), k.map((e, n) => {
						let r = ["ss-select-option"];
						return M(e) && r.push("ss-select-option--selected"), n === I && r.push("ss-select-option--active"), e.disabled && r.push("ss-select-option--disabled"), /* @__PURE__ */ t("li", {
							id: O(n),
							role: "option",
							className: r.join(" "),
							"aria-selected": M(e),
							"aria-disabled": e.disabled || void 0,
							onMouseDown: (e) => e.preventDefault(),
							onMouseMove: () => !e.disabled && L(n),
							onClick: () => U(n),
							children: e.label
						}, e.value);
					})]
				})]
			}),
			v && (Array.isArray(j) ? j : [j]).map((e) => /* @__PURE__ */ t("input", {
				type: "hidden",
				name: v,
				value: e
			}, e)),
			c && /* @__PURE__ */ t("span", {
				id: E,
				className: "ss-field-hint",
				children: c
			}),
			B && /* @__PURE__ */ t("span", {
				id: D,
				className: "ss-field-error",
				role: "alert",
				children: l
			})
		]
	});
}
//#endregion
//#region src/components/Checkbox/Checkbox.tsx
var w = o(function({ label: e, hint: r, error: i, className: a, id: o, disabled: s, "aria-describedby": c, ...l }, u) {
	let f = d(), p = o ?? f, m = `${p}-hint`, h = `${p}-error`, g = !!i, _ = [
		c,
		r ? m : void 0,
		g ? h : void 0
	].filter(Boolean).join(" "), v = g ? "ss-checkbox ss-checkbox--error" : "ss-checkbox", y = s ? "ss-check-row ss-check-row--disabled" : "ss-check-row";
	return /* @__PURE__ */ n("div", {
		className: a ? `ss-field ${a}` : "ss-field",
		children: [
			/* @__PURE__ */ n("label", {
				className: y,
				htmlFor: p,
				children: [/* @__PURE__ */ t("input", {
					ref: u,
					id: p,
					type: "checkbox",
					className: v,
					disabled: s,
					"aria-invalid": g || void 0,
					"aria-describedby": _ || void 0,
					...l
				}), e && /* @__PURE__ */ t("span", {
					className: "ss-check-text",
					children: e
				})]
			}),
			r && /* @__PURE__ */ t("span", {
				id: m,
				className: "ss-field-hint",
				children: r
			}),
			g && /* @__PURE__ */ t("span", {
				id: h,
				className: "ss-field-error",
				role: "alert",
				children: i
			})
		]
	});
}), T = o(function({ label: e, hint: r, className: i, id: a, disabled: o, "aria-describedby": s, ...c }, l) {
	let u = d(), f = a ?? u, p = `${f}-hint`, m = [s, r ? p : void 0].filter(Boolean).join(" "), h = o ? "ss-switch-row ss-switch-row--disabled" : "ss-switch-row";
	return /* @__PURE__ */ n("div", {
		className: i ? `ss-field ${i}` : "ss-field",
		children: [/* @__PURE__ */ n("label", {
			className: h,
			htmlFor: f,
			children: [e && /* @__PURE__ */ t("span", {
				className: "ss-switch-text",
				children: e
			}), /* @__PURE__ */ t("input", {
				ref: l,
				id: f,
				type: "checkbox",
				role: "switch",
				className: "ss-switch",
				disabled: o,
				"aria-describedby": m || void 0,
				...c
			})]
		}), r && /* @__PURE__ */ t("span", {
			id: p,
			className: "ss-field-hint",
			children: r
		})]
	});
});
//#endregion
//#region src/components/Tooltip/Tooltip.tsx
function E({ children: e, content: r, className: a }) {
	let o = d(), [c, l] = h(!1), u = (e) => {
		e.key === "Escape" && c && (l(!1), e.stopPropagation());
	}, f = s(e) ? i(e, { "aria-describedby": [e.props["aria-describedby"], o].filter(Boolean).join(" ") }) : /* @__PURE__ */ t("span", {
		tabIndex: 0,
		"aria-describedby": o,
		children: e
	});
	return /* @__PURE__ */ n("span", {
		className: a ? `ss-tooltip ${a}` : "ss-tooltip",
		onMouseEnter: () => l(!0),
		onMouseLeave: () => l(!1),
		onFocus: () => l(!0),
		onBlur: () => l(!1),
		onKeyDown: u,
		children: [f, /* @__PURE__ */ t("span", {
			id: o,
			role: "tooltip",
			className: c ? "ss-tooltip-bubble ss-tooltip-bubble--open" : "ss-tooltip-bubble",
			hidden: !c,
			children: r
		})]
	});
}
//#endregion
//#region src/components/InfoIcon/InfoIcon.tsx
var D = {
	source: "Source",
	confidence: "Confidence",
	reason: "Reason",
	date: "Date"
};
function O({ source: e, confidence: r, reason: i, date: a, label: o = "More information", labels: s, className: c }) {
	let l = {
		...D,
		...s
	}, u = a instanceof Date ? a.toISOString().slice(0, 10) : a;
	return /* @__PURE__ */ t(E, {
		className: c,
		content: /* @__PURE__ */ t("dl", {
			className: "ss-info-list",
			children: [
				["source", e],
				["confidence", r],
				["reason", i],
				["date", u]
			].filter(([, e]) => e != null && e !== "").map(([e, r]) => /* @__PURE__ */ n("div", {
				className: "ss-info-row",
				children: [/* @__PURE__ */ t("dt", {
					className: "ss-info-term",
					children: l[e]
				}), /* @__PURE__ */ t("dd", {
					className: "ss-info-desc",
					children: r
				})]
			}, e))
		}),
		children: /* @__PURE__ */ t("button", {
			type: "button",
			className: "ss-info-icon",
			"aria-label": o,
			children: /* @__PURE__ */ n("svg", {
				viewBox: "0 0 24 24",
				width: "24",
				height: "24",
				"aria-hidden": "true",
				focusable: "false",
				children: [
					/* @__PURE__ */ t("circle", {
						cx: "12",
						cy: "12",
						r: "10",
						fill: "none",
						stroke: "currentColor",
						strokeWidth: "2"
					}),
					/* @__PURE__ */ t("path", {
						d: "M12 11v6",
						stroke: "currentColor",
						strokeWidth: "2",
						strokeLinecap: "round"
					}),
					/* @__PURE__ */ t("circle", {
						cx: "12",
						cy: "7.5",
						r: "1.25",
						fill: "currentColor"
					})
				]
			})
		})
	});
}
//#endregion
//#region src/components/Badge/Badge.tsx
var k = {
	strong: "Strong",
	medium: "Medium",
	weak: "Weak"
};
function A({ variant: e = "neutral", className: r, children: i, onRemove: a, removeLabel: o = "Remove", ...s }) {
	let c = ["ss-badge", `ss-badge--${e}`];
	r && c.push(r);
	let l = (e) => {
		e.stopPropagation(), a?.();
	};
	return /* @__PURE__ */ n("span", {
		className: c.join(" "),
		...s,
		children: [
			e !== "neutral" && /* @__PURE__ */ t("span", {
				className: `ss-badge-dot ss-badge-dot--${e}`,
				"aria-hidden": "true"
			}),
			/* @__PURE__ */ t("span", {
				className: "ss-badge-text",
				children: i ?? k[e]
			}),
			a && /* @__PURE__ */ t("button", {
				type: "button",
				className: "ss-badge-remove",
				"aria-label": o,
				onClick: l,
				children: /* @__PURE__ */ t("svg", {
					viewBox: "0 0 16 16",
					width: "12",
					height: "12",
					"aria-hidden": "true",
					focusable: "false",
					children: /* @__PURE__ */ t("path", {
						d: "M3.5 3.5l9 9m0-9l-9 9",
						stroke: "currentColor",
						strokeWidth: "1.8",
						strokeLinecap: "round"
					})
				})
			})
		]
	});
}
//#endregion
//#region src/components/Card/Card.tsx
function j(e) {
	return r.toArray(e).some((e) => e !== "");
}
function M({ title: e, headingLevel: r = 2, when: i = !0, hideWhenEmpty: a = !0, children: o, className: s }) {
	let c = d();
	if (!i || a && !j(o)) return null;
	let l = `h${r}`, u = ["ss-card"];
	return s && u.push(s), /* @__PURE__ */ n("section", {
		className: u.join(" "),
		"aria-labelledby": e ? c : void 0,
		children: [e && /* @__PURE__ */ t(l, {
			id: c,
			className: "ss-card-title",
			children: e
		}), o]
	});
}
//#endregion
//#region src/components/Spinner/Spinner.tsx
function N() {
	return typeof window < "u" && typeof window.matchMedia == "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}
function P({ label: e = "Loading...", className: r }) {
	let i = ["ss-spinner"];
	return r && i.push(r), /* @__PURE__ */ n("div", {
		role: "status",
		className: i.join(" "),
		children: [/* @__PURE__ */ t("svg", {
			"aria-hidden": "true",
			className: "ss-spinner-svg",
			viewBox: "0 0 100 101",
			fill: "none",
			children: /* @__PURE__ */ n("g", { children: [
				/* @__PURE__ */ t("path", {
					className: "ss-spinner-track",
					d: "M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z"
				}),
				/* @__PURE__ */ t("path", {
					className: "ss-spinner-arc",
					d: "M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z"
				}),
				!N() && /* @__PURE__ */ t("animateTransform", {
					attributeName: "transform",
					type: "rotate",
					from: "0 50 50.5",
					to: "360 50 50.5",
					dur: "1s",
					repeatCount: "indefinite"
				})
			] })
		}), /* @__PURE__ */ t("span", {
			className: "ss-visually-hidden",
			children: e
		})]
	});
}
//#endregion
//#region src/components/ChartFrame/ChartFrame.tsx
function F({ title: e, headingLevel: r = 3, source: i, loading: a = !1, error: o, onRetry: s, empty: c = !1, emptyState: l, loadingLabel: u = "Loading chart…", minHeight: f, description: p, children: m, className: h }) {
	let g = d(), v = d(), y = `h${r}`, b = ["ss-chart-frame"];
	h && b.push(h);
	let x = o != null && o !== !1, S;
	return S = a ? /* @__PURE__ */ t("div", {
		className: "ss-chart-state",
		children: /* @__PURE__ */ t(P, { label: u })
	}) : x ? /* @__PURE__ */ n("div", {
		className: "ss-chart-state",
		role: "alert",
		children: [/* @__PURE__ */ t("p", {
			className: "ss-chart-message ss-chart-message-error",
			children: o
		}), s && /* @__PURE__ */ t(_, {
			variant: "outlined-primary",
			className: "ss-chart-retry",
			onClick: s,
			children: "Retry"
		})]
	}) : c ? /* @__PURE__ */ t("div", {
		className: "ss-chart-state",
		children: l ?? /* @__PURE__ */ t("p", {
			className: "ss-chart-message",
			children: "No data to show yet."
		})
	}) : m, /* @__PURE__ */ n("section", {
		className: b.join(" "),
		"aria-labelledby": e ? g : void 0,
		"aria-describedby": p ? v : void 0,
		"aria-busy": a || void 0,
		children: [
			e && /* @__PURE__ */ t(y, {
				id: g,
				className: "ss-chart-title",
				children: e
			}),
			p && /* @__PURE__ */ t("p", {
				id: v,
				className: "ss-visually-hidden",
				children: p
			}),
			/* @__PURE__ */ t("div", {
				className: "ss-chart-body",
				style: f === void 0 ? void 0 : { minHeight: f },
				children: S
			}),
			i && /* @__PURE__ */ n("p", {
				className: "ss-chart-source",
				children: ["Source: ", i]
			})
		]
	});
}
//#endregion
//#region src/components/EmptyState/EmptyState.tsx
function I({ title: e, description: r, icon: i, action: a, headingLevel: o = 3, className: s }) {
	let c = `h${o}`, l = ["ss-empty"];
	return s && l.push(s), /* @__PURE__ */ n("div", {
		className: l.join(" "),
		children: [
			i && /* @__PURE__ */ t("div", {
				className: "ss-empty-icon",
				"aria-hidden": "true",
				children: i
			}),
			/* @__PURE__ */ t(c, {
				className: "ss-empty-title",
				children: e
			}),
			r && /* @__PURE__ */ t("p", {
				className: "ss-empty-description",
				children: r
			}),
			a && /* @__PURE__ */ t("div", {
				className: "ss-empty-action",
				children: a
			})
		]
	});
}
//#endregion
//#region src/components/LevelIndicator/levels.ts
var L = [40, 70], R = {
	strong: "Strong",
	medium: "Medium",
	weak: "Weak"
};
function z(e, t = L, n = !1) {
	let r = "strong";
	return e < t[0] ? r = "weak" : e < t[1] && (r = "medium"), n ? r === "weak" ? "strong" : r === "strong" ? "weak" : "medium" : r;
}
//#endregion
//#region src/components/LevelIndicator/LevelIndicator.tsx
function B({ value: e, thresholds: r = L, inverted: i = !1, level: a, label: o, valueLabel: s, showLevelName: c = !1, levelNames: l = R, className: u }) {
	let f = d(), p = Number.isFinite(e) ? Math.min(100, Math.max(0, e)) : 0, m = a ?? z(p, r, i), h = ["ss-level"];
	return u && h.push(u), /* @__PURE__ */ n("div", {
		className: h.join(" "),
		children: [
			o && /* @__PURE__ */ t("span", {
				id: f,
				className: "ss-level-label",
				children: o
			}),
			/* @__PURE__ */ t("div", {
				role: "progressbar",
				className: "ss-level-track",
				"aria-valuemin": 0,
				"aria-valuemax": 100,
				"aria-valuenow": p,
				"aria-valuetext": a ? `${p}%` : `${p}%, ${l[m]}`,
				"aria-labelledby": o ? f : void 0,
				"aria-label": o ? void 0 : "Score",
				children: /* @__PURE__ */ t("div", {
					className: `ss-level-fill ss-level-fill-${m}`,
					style: { width: `${p}%` }
				})
			}),
			s,
			c && /* @__PURE__ */ t("span", {
				className: `ss-level-name ss-level-name-${m}`,
				children: l[m]
			})
		]
	});
}
//#endregion
//#region src/classes.ts
function V(...e) {
	return e.filter(Boolean).join(" ");
}
//#endregion
//#region src/components/LockedSection/LockedSection.tsx
function H(e) {
	e && e.setAttribute("inert", "");
}
function U({ title: r, description: i, requiredPlan: a, upgradeLabel: o = "Upgrade plan", upgradeHref: s, onUpgrade: c, preview: l, headingLevel: u = 3, className: f }) {
	let p = d(), m = `h${u}`, h = ["ss-locked"];
	l && h.push("ss-locked-with-preview"), f && h.push(f);
	let g = /* @__PURE__ */ n("div", {
		className: V("ss-locked-panel", !!l && "ss-locked-panel-raised"),
		children: [
			/* @__PURE__ */ t("svg", {
				"aria-hidden": "true",
				focusable: "false",
				className: "ss-locked-icon",
				viewBox: "0 0 24 24",
				fill: "currentColor",
				children: /* @__PURE__ */ t("path", { d: "M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V11a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 1 1 6 0v3H9Z" })
			}),
			/* @__PURE__ */ t(m, {
				id: p,
				className: "ss-locked-title",
				children: r
			}),
			a && /* @__PURE__ */ n("p", {
				className: "ss-locked-plan",
				children: [
					"Available on the ",
					a,
					" plan"
				]
			}),
			i && /* @__PURE__ */ t("p", {
				className: "ss-locked-description",
				children: i
			}),
			/* @__PURE__ */ t("div", {
				className: "ss-locked-action",
				children: s ? /* @__PURE__ */ t("a", {
					className: "ss-button ss-button--contained-primary ss-locked-button",
					href: s,
					children: o
				}) : /* @__PURE__ */ t(_, {
					variant: "contained-primary",
					className: "ss-locked-button",
					onClick: c,
					children: o
				})
			})
		]
	});
	return /* @__PURE__ */ t("section", {
		className: h.join(" "),
		"aria-labelledby": p,
		children: l ? /* @__PURE__ */ n(e, { children: [/* @__PURE__ */ t("div", {
			className: "ss-locked-preview",
			"aria-hidden": "true",
			ref: H,
			children: l
		}), /* @__PURE__ */ t("div", {
			className: "ss-locked-scrim",
			children: g
		})] }) : g
	});
}
//#endregion
//#region src/components/Pagination/page-window.ts
var W = 3;
function G(e, t) {
	let n = Math.min(W, t), r = Math.max(1, Math.min(e - 1, t - n + 1));
	return Array.from({ length: n }, (e, t) => r + t);
}
//#endregion
//#region src/components/Pagination/Pagination.tsx
function K({ direction: e }) {
	return /* @__PURE__ */ t("svg", {
		"aria-hidden": "true",
		focusable: "false",
		className: `ss-pagination-icon ss-pagination-icon-${e}`,
		viewBox: "0 0 14 8",
		fill: "none",
		children: /* @__PURE__ */ t("path", {
			d: "M13.6666 6.66667L6.99992 0L0.333252 6.66667L1.51659 7.85L6.99992 2.36667L12.4833 7.85L13.6666 6.66667Z",
			fill: "currentColor"
		})
	});
}
function q({ currentPage: e, totalRecords: r, pageSize: i, onPageChange: a, label: o = "Pagination", className: s }) {
	let c = Math.max(1, Math.ceil(r / i)), l = ["ss-pagination"];
	s && l.push(s);
	let u = e <= 1, d = e >= c;
	return /* @__PURE__ */ n("nav", {
		"aria-label": o,
		className: l.join(" "),
		children: [
			/* @__PURE__ */ t("button", {
				type: "button",
				className: "ss-pagination-item",
				"aria-label": "Previous page",
				disabled: u,
				onClick: () => a(e - 1),
				children: /* @__PURE__ */ t(K, { direction: "previous" })
			}),
			(r > 0 ? G(e, c) : []).map((n) => {
				let r = n === e;
				return /* @__PURE__ */ t("button", {
					type: "button",
					className: V("ss-pagination-item", r && "ss-pagination-item-current"),
					"aria-label": `Page ${n}`,
					"aria-current": r ? "page" : void 0,
					onClick: () => {
						r || a(n);
					},
					children: n
				}, n);
			}),
			/* @__PURE__ */ t("button", {
				type: "button",
				className: "ss-pagination-item",
				"aria-label": "Next page",
				disabled: d,
				onClick: () => a(e + 1),
				children: /* @__PURE__ */ t(K, { direction: "next" })
			})
		]
	});
}
//#endregion
//#region src/components/Table/Table.tsx
function J(e, t) {
	return e.value ? e.value(t) : t[e.key];
}
function Y(e) {
	return e == null || e === "";
}
function ee(e, t) {
	return typeof e == "number" && typeof t == "number" ? e - t : String(e).localeCompare(String(t), void 0, {
		numeric: !0,
		sensitivity: "base"
	});
}
function X(e, t, n) {
	let r = n === "asc" ? 1 : -1;
	return e.map((e, t) => ({
		row: e,
		index: t
	})).sort((e, n) => {
		if (t.compare) return r * t.compare(e.row, n.row) || e.index - n.index;
		let i = J(t, e.row), a = J(t, n.row);
		return Y(i) || Y(a) ? Number(Y(i)) - Number(Y(a)) || e.index - n.index : r * ee(i, a) || e.index - n.index;
	}).map(({ row: e }) => e);
}
function te(e, t, n) {
	if (e.filter) return e.filter(t, n);
	let r = J(e, t);
	return String(r ?? "").toLowerCase().includes(n.toLowerCase());
}
function ne(e, t) {
	return !e || e.key !== t ? {
		key: t,
		direction: "asc"
	} : e.direction === "asc" ? {
		key: t,
		direction: "desc"
	} : null;
}
var re = {
	asc: "ascending",
	desc: "descending"
};
function ie({ direction: e }) {
	let r = (n) => /* @__PURE__ */ t("svg", {
		"aria-hidden": "true",
		focusable: "false",
		viewBox: "0 0 8.3333197 4.1666999",
		className: V("ss-table-sort-icon", `ss-table-sort-icon-${n}`, e === (n === "up" ? "asc" : "desc") && "ss-table-sort-icon-active"),
		children: /* @__PURE__ */ t("path", {
			d: "M 0,0 4.16672,4.1667 8.33332,0 Z",
			fill: "currentColor"
		})
	});
	return /* @__PURE__ */ n("span", {
		className: "ss-table-sort-icons",
		children: [r("up"), r("down")]
	});
}
function ae({ columns: e, rows: r, getRowKey: i, caption: a, sort: o, defaultSort: s = null, onSortChange: c, manualSort: l = !1, filters: u, groupBy: d, renderGroupHeader: f, pageSize: m, page: g, defaultPage: _ = 1, onPageChange: v, totalRecords: y, empty: b, fixed: x = !1, stripes: S = "surface-first", className: C }) {
	let [w, T] = h(s), [E, D] = h(_), O = o === void 0 ? w : o, k = y !== void 0, A = p(() => {
		let t = r;
		if (u) {
			let n = e.filter((e) => !Y(u[e.key]));
			n.length > 0 && (t = t.filter((e) => n.every((t) => te(t, e, u[t.key]))));
		}
		if (O && !l) {
			let n = e.find((e) => e.key === O.key);
			n && (t = X(t, n, O.direction));
		}
		return t;
	}, [
		r,
		e,
		u,
		O,
		l
	]), j = k ? y : A.length, M = m ? Math.max(1, Math.ceil(j / m)) : 1, N = Math.min(Math.max(1, g ?? E), M), P = p(() => !m || k ? A : A.slice((N - 1) * m, N * m), [
		A,
		m,
		k,
		N
	]), F = p(() => {
		if (!d) return [{
			name: null,
			rows: P,
			start: 0
		}];
		let e = [], t = /* @__PURE__ */ new Map();
		P.forEach((n) => {
			let r = d(n);
			t.has(r) || (t.set(r, []), e.push(r)), t.get(r)?.push(n);
		});
		let n = 0;
		return e.map((e) => {
			let r = t.get(e) ?? [], i = {
				name: e,
				rows: r,
				start: n
			};
			return n += r.length, i;
		});
	}, [d, P]), I = (e) => {
		let t = ne(O, e.key);
		o === void 0 && T(t), c?.(t, e.key);
	}, L = (e) => {
		g === void 0 && D(e), v?.(e);
	}, R = ["ss-table"];
	R.push(x ? "ss-table-fixed" : "ss-table-auto"), C && R.push(C);
	let z = (e) => {
		if (S === "none") return "ss-table-row";
		let t = e % 2 == 0;
		return `ss-table-row ${(S === "surface-first" ? t : !t) ? "ss-table-row-surface" : "ss-table-row-subtle"}`;
	};
	return /* @__PURE__ */ n("div", {
		className: "ss-table-wrap",
		children: [/* @__PURE__ */ n("table", {
			className: R.join(" "),
			children: [
				/* @__PURE__ */ t("caption", {
					className: "ss-visually-hidden",
					children: a
				}),
				/* @__PURE__ */ t("thead", { children: /* @__PURE__ */ t("tr", { children: e.map((e) => {
					let r = O?.key === e.key ? O.direction : null;
					return /* @__PURE__ */ t("th", {
						scope: "col",
						className: "ss-table-th",
						"aria-sort": e.sortable ? r ? re[r] : "none" : void 0,
						children: e.sortable ? /* @__PURE__ */ n("button", {
							type: "button",
							className: "ss-table-sort",
							onClick: () => I(e),
							children: [/* @__PURE__ */ t("span", {
								className: "ss-table-th-label",
								children: e.header
							}), /* @__PURE__ */ t(ie, { direction: r })]
						}) : /* @__PURE__ */ t("div", {
							className: "ss-table-th-content",
							children: /* @__PURE__ */ t("span", {
								className: "ss-table-th-label",
								children: e.header
							})
						})
					}, e.key);
				}) }) }),
				P.length === 0 ? /* @__PURE__ */ t("tbody", { children: b !== void 0 && /* @__PURE__ */ t("tr", { children: /* @__PURE__ */ t("td", {
					colSpan: e.length,
					className: "ss-table-empty",
					children: b
				}) }) }) : F.map((r) => /* @__PURE__ */ n("tbody", { children: [r.name !== null && /* @__PURE__ */ t("tr", {
					className: "ss-table-group-row",
					children: /* @__PURE__ */ t("th", {
						scope: "rowgroup",
						colSpan: e.length,
						className: "ss-table-group",
						children: f ? f(r.name, r.rows) : r.name
					})
				}), r.rows.map((n, a) => {
					let o = r.start + a;
					return /* @__PURE__ */ t("tr", {
						className: z(o),
						children: e.map((e) => /* @__PURE__ */ t("td", {
							className: V("ss-table-td", e.align && e.align !== "center" && `ss-table-td-${e.align}`),
							children: e.render ? e.render(n, o) : String(J(e, n) ?? "")
						}, e.key))
					}, i ? i(n, o) : o);
				})] }, r.name ?? "__all"))
			]
		}), m !== void 0 && /* @__PURE__ */ t(q, {
			currentPage: N,
			totalRecords: j,
			pageSize: m,
			onPageChange: L
		})]
	});
}
//#endregion
//#region src/components/Tabs/Tabs.tsx
var oe = a(null);
function se(e) {
	let t = l(oe);
	if (!t) throw Error(`${e} must be rendered inside <Tabs>`);
	return t;
}
var ce = (e) => e.replace(/[^A-Za-z0-9_-]/g, "_"), le = (e, t) => `${e}-tab-${ce(t)}`, ue = (e, t) => `${e}-panel-${ce(t)}`;
function de({ value: e, defaultValue: n = "", onValueChange: r, orientation: i = "horizontal", activation: a = "automatic", className: o, children: s }) {
	let l = d(), [u, f] = h(n), m = e ?? u, g = c((t) => {
		e === void 0 && f(t), r?.(t);
	}, [e, r]), _ = p(() => ({
		baseId: l,
		value: m,
		select: g,
		orientation: i,
		activation: a
	}), [
		l,
		m,
		g,
		i,
		a
	]), v = ["ss-tabs", `ss-tabs--${i}`];
	return o && v.push(o), /* @__PURE__ */ t(oe.Provider, {
		value: _,
		children: /* @__PURE__ */ t("div", {
			className: v.join(" "),
			children: s
		})
	});
}
function fe({ className: e, onKeyDown: n, ...r }) {
	let { orientation: i, select: a, activation: o } = se("TabList"), s = (e) => {
		if (n?.(e), e.defaultPrevented) return;
		let t = Array.from(e.currentTarget.querySelectorAll("[role=\"tab\"]:not([disabled])")), r = t.indexOf(document.activeElement);
		if (r === -1) return;
		let s = i === "horizontal" ? "ArrowLeft" : "ArrowUp", c = i === "horizontal" ? "ArrowRight" : "ArrowDown", l = null;
		if (e.key === c ? l = (r + 1) % t.length : e.key === s ? l = (r - 1 + t.length) % t.length : e.key === "Home" ? l = 0 : e.key === "End" && (l = t.length - 1), l === null) return;
		e.preventDefault();
		let u = t[l];
		u.focus(), o === "automatic" && a(u.dataset.value ?? "");
	}, c = ["ss-tablist", `ss-tablist--${i}`];
	return e && c.push(e), /* @__PURE__ */ t("div", {
		role: "tablist",
		"aria-orientation": i,
		className: c.join(" "),
		onKeyDown: s,
		...r
	});
}
function pe({ value: e, className: n, onClick: r, disabled: i, children: a, ...o }) {
	let s = se("Tab"), c = s.value === e, l = ["ss-tab"];
	return c && l.push("ss-tab--selected"), n && l.push(n), /* @__PURE__ */ t("button", {
		type: "button",
		role: "tab",
		id: le(s.baseId, e),
		"data-value": e,
		"aria-selected": c,
		"aria-controls": c ? ue(s.baseId, e) : void 0,
		tabIndex: c ? 0 : -1,
		disabled: i,
		className: l.join(" "),
		onClick: (t) => {
			r?.(t), t.defaultPrevented || s.select(e);
		},
		...o,
		children: a
	});
}
function me({ value: e, className: n, children: r, ...i }) {
	let a = se("TabPanel");
	if (a.value !== e) return null;
	let o = ["ss-tabpanel"];
	return n && o.push(n), /* @__PURE__ */ t("div", {
		role: "tabpanel",
		id: ue(a.baseId, e),
		"aria-labelledby": le(a.baseId, e),
		tabIndex: 0,
		className: o.join(" "),
		...i,
		children: r
	});
}
//#endregion
//#region src/internal/layers.ts
var Z = [], he = a(null), ge = (e, t) => {
	for (let n = e.parent; n; n = n.parent) if (n === t) return !0;
	return !1;
};
function _e(e, t) {
	let n = l(he), r = m();
	if (!r.current) {
		let e = {
			parent: n,
			getElement: () => t.current
		};
		r.current = {
			layer: e,
			isTop: () => {
				if (!Z.includes(e)) return !1;
				let t = Z.filter((e) => !Z.some((t) => ge(t, e)));
				return t[t.length - 1] === e;
			},
			owns: (t) => Z.some((n) => (n === e || ge(n, e)) && !!n.getElement()?.contains(t))
		};
	}
	r.current.layer.parent = n;
	let { layer: i } = r.current;
	return u(() => {
		if (e) return Z.push(i), () => {
			Z.splice(Z.indexOf(i), 1);
		};
	}, [e, i]), r.current;
}
//#endregion
//#region src/internal/portal.ts
function ve(e) {
	return typeof document > "u" ? null : (typeof e == "function" ? e() : e) ?? document.body;
}
var ye = "a[href], area[href], button:not([disabled]), input:not([disabled]):not([type=\"hidden\"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex=\"-1\"]), [contenteditable=\"true\"]";
function Q(e) {
	return Array.from(e.querySelectorAll(ye)).filter((e) => !e.hasAttribute("hidden") && e.getAttribute("aria-hidden") !== "true");
}
var $ = /* @__PURE__ */ new WeakMap();
function be(e) {
	let t = e.body, n = $.get(t);
	if (!n) {
		n = {
			count: 0,
			overflow: t.style.overflow,
			paddingRight: t.style.paddingRight
		};
		let r = (e.defaultView?.innerWidth ?? 0) - e.documentElement.clientWidth;
		if (r > 0) {
			let n = parseFloat(e.defaultView?.getComputedStyle(t).paddingRight ?? "0") || 0;
			t.style.paddingRight = `${n + r}px`;
		}
		t.style.overflow = "hidden", $.set(t, n);
	}
	n.count += 1;
	let r = !1;
	return () => {
		if (r) return;
		r = !0;
		let e = $.get(t);
		e && (--e.count, e.count === 0 && (t.style.overflow = e.overflow, t.style.paddingRight = e.paddingRight, $.delete(t)));
	};
}
//#endregion
//#region src/components/Modal/Modal.tsx
function xe({ open: e, onClose: r, title: i, "aria-label": a, role: o = "dialog", container: s, initialFocusRef: c, closeOnBackdropClick: l = !0, footer: f, className: p, children: h }) {
	let _ = d(), v = m(null), y = m(r);
	y.current = r;
	let b = m(c);
	b.current = c;
	let x = e ? ve(s) : null, S = _e(!!x, v);
	if (u(() => {
		if (!x) return;
		let e = v.current;
		if (!e) return;
		let t = e.ownerDocument, n = t.activeElement instanceof HTMLElement ? t.activeElement : null, r = be(t);
		(b.current?.current ?? Q(e)[0] ?? e).focus();
		let i = (t) => {
			S.isTop() && t.target instanceof Node && !S.owns(t.target) && (Q(e)[0] ?? e).focus();
		};
		return t.addEventListener("focusin", i), () => {
			t.removeEventListener("focusin", i), r(), n && n.isConnected && n.focus({ preventScroll: !0 });
		};
	}, [x, S]), !x) return null;
	let C = (e) => {
		if (!S.isTop()) return;
		if (e.key === "Escape") {
			e.stopPropagation(), e.preventDefault(), y.current();
			return;
		}
		if (e.key !== "Tab") return;
		let t = v.current;
		if (!t) return;
		let n = Q(t);
		if (n.length === 0) {
			e.preventDefault(), t.focus();
			return;
		}
		let r = n[0], i = n[n.length - 1], a = t.ownerDocument.activeElement;
		e.shiftKey && (a === r || a === t) ? (e.preventDefault(), i.focus()) : !e.shiftKey && a === i && (e.preventDefault(), r.focus());
	}, w = (e) => {
		S.isTop() && l && e.target === e.currentTarget && y.current();
	}, T = ["ss-modal"];
	return p && T.push(p), g(/* @__PURE__ */ t(he.Provider, {
		value: S.layer,
		children: /* @__PURE__ */ t("div", {
			className: "ss-modal-backdrop",
			onMouseDown: w,
			children: /* @__PURE__ */ n("div", {
				ref: v,
				role: o,
				"aria-modal": "true",
				"aria-labelledby": i ? _ : void 0,
				"aria-label": i ? void 0 : a,
				tabIndex: -1,
				className: T.join(" "),
				onKeyDown: C,
				children: [
					i ? /* @__PURE__ */ t("h2", {
						id: _,
						className: "ss-modal-title",
						children: i
					}) : null,
					/* @__PURE__ */ t("div", {
						className: "ss-modal-body",
						children: h
					}),
					f ? /* @__PURE__ */ t("div", {
						className: "ss-modal-footer",
						children: f
					}) : null
				]
			})
		})
	}), x);
}
//#endregion
//#region src/components/Toast/toast-context.ts
var Se = a(null);
function Ce() {
	let e = l(Se);
	if (!e) throw Error("useToast must be used inside <ToastProvider>");
	return e;
}
//#endregion
//#region src/components/Toast/Toast.tsx
function we({ toast: e, duration: r, dismissLabel: i, onDismiss: a }) {
	let [o, s] = h(!1), c = m(r), l = m(!1), d = m(!1);
	u(() => {
		if (r <= 0 || o) return;
		let t = Date.now(), n = setTimeout(() => a(e.id), c.current);
		return () => {
			clearTimeout(n), c.current = Math.max(0, c.current - (Date.now() - t));
		};
	}, [
		o,
		r,
		e.id,
		a
	]);
	let f = () => s(l.current || d.current), p = ["ss-toast", `ss-toast--${e.tone ?? "info"}`].join(" ");
	return /* @__PURE__ */ n("div", {
		className: p,
		onMouseEnter: () => {
			l.current = !0, f();
		},
		onMouseLeave: () => {
			l.current = !1, f();
		},
		onFocus: () => {
			d.current = !0, f();
		},
		onBlur: () => {
			d.current = !1, f();
		},
		children: [/* @__PURE__ */ n("div", {
			className: "ss-toast-content",
			children: [e.title ? /* @__PURE__ */ t("p", {
				className: "ss-toast-title",
				children: e.title
			}) : null, /* @__PURE__ */ t("p", {
				className: "ss-toast-message",
				children: e.message
			})]
		}), /* @__PURE__ */ t("button", {
			type: "button",
			className: "ss-toast-close",
			"aria-label": i,
			onClick: () => a(e.id),
			children: /* @__PURE__ */ t("span", {
				"aria-hidden": "true",
				children: "×"
			})
		})]
	});
}
var Te = 0;
function Ee({ container: e, defaultDuration: r = 5e3, max: i = 5, dismissLabel: a = "Dismiss notification", children: o }) {
	let [s, l] = h([]), [d, f] = h(null);
	u(() => {
		f(ve(e));
	}, [e]);
	let m = c((e) => {
		l((t) => t.filter((t) => t.id !== e));
	}, []), _ = c(() => l([]), []), v = c((e) => {
		Te += 1;
		let t = `ss-toast-${Te}`;
		return l((n) => [...n, {
			...e,
			id: t
		}].slice(-i)), t;
	}, [i]), y = p(() => ({
		show: v,
		dismiss: m,
		dismissAll: _
	}), [
		v,
		m,
		_
	]), b = s.filter((e) => (e.politeness ?? (e.tone === "error" ? "assertive" : "polite")) === "polite"), x = s.filter((e) => !b.includes(e)), S = (e) => /* @__PURE__ */ t(we, {
		toast: e,
		duration: e.duration ?? r,
		dismissLabel: a,
		onDismiss: m
	}, e.id);
	return /* @__PURE__ */ n(Se.Provider, {
		value: y,
		children: [o, d ? g(/* @__PURE__ */ n("div", {
			className: "ss-toast-viewport",
			children: [/* @__PURE__ */ t("div", {
				role: "status",
				"aria-live": "polite",
				"aria-atomic": "false",
				className: "ss-toast-region",
				children: b.map(S)
			}), /* @__PURE__ */ t("div", {
				role: "alert",
				"aria-live": "assertive",
				"aria-atomic": "false",
				className: "ss-toast-region",
				children: x.map(S)
			})]
		}), d) : null]
	});
}
//#endregion
//#region src/components/Chat/Chat.tsx
function De(e, t) {
	let n = e instanceof Date ? e : new Date(e);
	return Number.isNaN(n.getTime()) ? "" : n.toLocaleTimeString(t, {
		hour: "2-digit",
		minute: "2-digit"
	});
}
function Oe({ role: e, text: r, timestamp: i, author: a, locale: o, className: s }) {
	let c = ["ss-chat-bubble", `ss-chat-bubble--${e}`];
	s && c.push(s);
	let l = i === void 0 ? null : new Date(i), u = l !== null && !Number.isNaN(l.getTime());
	return /* @__PURE__ */ n("div", {
		className: c.join(" "),
		children: [
			a ? /* @__PURE__ */ n("span", {
				className: "ss-chat-sr",
				children: [a, ": "]
			}) : null,
			/* @__PURE__ */ t("p", {
				className: "ss-chat-text",
				children: r
			}),
			u && i !== void 0 ? /* @__PURE__ */ t("time", {
				className: "ss-chat-time",
				dateTime: l.toISOString(),
				children: De(i, o)
			}) : null
		]
	});
}
function ke({ label: e = "Assistant is typing", className: n }) {
	let [r, i] = h(0);
	u(() => {
		if (typeof window.matchMedia == "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
		let e = setInterval(() => i((e) => (e + 1) % 3), 400);
		return () => clearInterval(e);
	}, []);
	let a = [
		"ss-chat-bubble",
		"ss-chat-bubble--assistant",
		"ss-chat-typing"
	];
	return n && a.push(n), /* @__PURE__ */ t("div", {
		role: "status",
		"aria-label": e,
		className: a.join(" "),
		children: [
			0,
			1,
			2
		].map((e) => /* @__PURE__ */ t("span", {
			"aria-hidden": "true",
			className: e === r ? "ss-chat-dot ss-chat-dot--active" : "ss-chat-dot"
		}, e))
	});
}
function Ae({ messages: e, typing: r = !1, label: i = "Conversation", userLabel: a = "You", assistantLabel: o = "MarSel", typingLabel: s = `${o} is typing`, locale: c, className: l }) {
	let d = m(null), f = m(!0);
	u(() => {
		let e = d.current;
		e && f.current && (e.scrollTop = e.scrollHeight);
	}, [e, r]);
	let p = ["ss-chat-thread"];
	return l && p.push(l), /* @__PURE__ */ n("div", {
		ref: d,
		role: "log",
		"aria-label": i,
		"aria-live": "polite",
		"aria-relevant": "additions",
		tabIndex: 0,
		className: p.join(" "),
		onScroll: (e) => {
			let t = e.currentTarget;
			f.current = t.scrollHeight - t.scrollTop - t.clientHeight < 24;
		},
		children: [e.map((e) => /* @__PURE__ */ t(Oe, {
			role: e.role,
			text: e.text,
			timestamp: e.timestamp,
			author: e.role === "user" ? a : o,
			locale: c
		}, e.id)), r ? /* @__PURE__ */ t(ke, { label: s }) : null]
	});
}
//#endregion
//#region src/components/Popover/Popover.tsx
var je = typeof document > "u" ? u : f;
function Me({ open: e, onClose: n, anchorRef: r, placement: i = "bottom-start", offset: a = 4, matchAnchorWidth: o = !1, container: s, role: c = "dialog", "aria-label": l, "aria-labelledby": d, id: f, autoFocus: p = !0, returnFocus: h = !0, className: _, children: v }) {
	let y = m(null), b = m(n);
	b.current = n;
	let x = m(!1), S = e ? ve(s) : null, C = _e(!!S, y);
	if (je(() => {
		let e = y.current, t = r.current;
		if (!S || !e || !t) return;
		let n = e.ownerDocument.defaultView ?? window, s = () => {
			let r = t.getBoundingClientRect();
			o && (e.style.width = `${r.width}px`);
			let s = e.offsetWidth, c = e.offsetHeight, l = i.endsWith("end") ? r.right - s : r.left, u = i.startsWith("top") ? r.top - c - a : r.bottom + a;
			e.style.left = `${Math.max(0, Math.min(l, n.innerWidth - s))}px`, e.style.top = `${Math.max(0, u)}px`;
		}, c = (r) => {
			if (r.target instanceof Node && e.contains(r.target)) return;
			let i = t.getBoundingClientRect();
			if (i.bottom < 0 || i.top > n.innerHeight || i.right < 0 || i.left > n.innerWidth) {
				x.current = !0, b.current();
				return;
			}
			s();
		};
		return s(), n.addEventListener("resize", c), n.addEventListener("scroll", c, !0), () => {
			n.removeEventListener("resize", c), n.removeEventListener("scroll", c, !0);
		};
	}, [
		S,
		r,
		i,
		a,
		o
	]), u(() => {
		let e = y.current;
		if (!S || !e) return;
		let t = r.current, n = e.ownerDocument, i = n.activeElement instanceof HTMLElement && n.activeElement !== n.body ? n.activeElement : null;
		x.current = !1, p && (Q(e)[0] ?? e).focus({ preventScroll: !0 });
		let a = (e) => {
			let n = e.target;
			!n || C.owns(n) || t?.contains(n) || b.current();
		}, o = (e) => {
			e.key === "Escape" && !e.defaultPrevented && C.isTop() && b.current();
		};
		return n.addEventListener("mousedown", a), n.addEventListener("touchstart", a), n.addEventListener("keydown", o), () => {
			n.removeEventListener("mousedown", a), n.removeEventListener("touchstart", a), n.removeEventListener("keydown", o);
			let e = n.activeElement;
			h && !x.current && (!e || e === n.body) && (i && i.isConnected ? i : t)?.focus({ preventScroll: !0 });
		};
	}, [
		S,
		r,
		p,
		h,
		C
	]), !S) return null;
	let w = (e) => {
		if (e.key === "Escape" && C.isTop()) {
			e.stopPropagation(), e.preventDefault(), b.current();
			return;
		}
		if (e.key !== "Tab" || e.defaultPrevented) return;
		let t = y.current, n = r.current;
		if (!t || !n) return;
		let i = Q(t), a = t.ownerDocument.activeElement;
		if (!(e.shiftKey ? a === t || a === i[0] : a === t || a === i[i.length - 1])) return;
		e.preventDefault();
		let o = Q(C.layer.parent?.getElement() ?? t.ownerDocument.body).filter((e) => !t.contains(e)), s = o.indexOf(n), c = e.shiftKey ? n : o[s + 1] ?? (C.layer.parent ? o[0] : void 0) ?? n;
		b.current(), c.focus({ preventScroll: !0 });
	}, T = ["ss-popover"];
	return _ && T.push(_), g(/* @__PURE__ */ t(he.Provider, {
		value: C.layer,
		children: /* @__PURE__ */ t("div", {
			ref: y,
			id: f,
			role: c,
			"aria-label": l,
			"aria-labelledby": d,
			tabIndex: -1,
			className: T.join(" "),
			onKeyDown: w,
			children: v
		})
	}), S);
}
//#endregion
//#region src/components/Menu/Menu.tsx
var Ne = a(null), Pe = (e) => Array.from(e.querySelectorAll("[role=\"menuitem\"]:not([aria-disabled=\"true\"])"));
function Fe({ label: r, "aria-label": i, children: a, placement: o = "bottom-start", offset: s, matchButtonWidth: l, container: f, onOpenChange: p, className: g, menuClassName: _, popoverClassName: v }) {
	let y = d(), b = d(), x = m(null), S = m(null), [C, w] = h(!1), T = m(p);
	T.current = p;
	let E = c((e) => {
		w(e), T.current?.(e);
	}, []), [D, O] = h("first"), k = m({
		text: "",
		timer: 0
	}), A = c(() => E(!1), [E]), j = (e) => {
		O(e), E(!0);
	};
	u(() => {
		if (!C || !S.current) return;
		let e = Pe(S.current);
		(D === "last" ? e[e.length - 1] : e[0])?.focus();
	}, [C, D]), u(() => () => window.clearTimeout(k.current.timer), []);
	let M = (e) => {
		e.key === "ArrowDown" ? (e.preventDefault(), j("first")) : e.key === "ArrowUp" && (e.preventDefault(), j("last"));
	}, N = (e) => {
		let t = Pe(e.currentTarget);
		if (t.length === 0) return;
		let n = t.indexOf(document.activeElement), r;
		if (e.key === "ArrowDown") r = t[(n + 1) % t.length];
		else if (e.key === "ArrowUp") r = t[(n - 1 + t.length) % t.length];
		else if (e.key === "Home") r = t[0];
		else if (e.key === "End") r = t[t.length - 1];
		else if (e.key === "Tab") {
			e.preventDefault(), A();
			return;
		} else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
			if (e.key === " " && k.current.text === "") return;
			window.clearTimeout(k.current.timer), k.current.text += e.key.toLowerCase(), k.current.timer = window.setTimeout(() => k.current.text = "", 500);
			let i = k.current.text, a = [...i].every((e) => e === i[0]), o = a ? n + 1 : Math.max(n, 0), s = a ? i[0] : i;
			for (let e = 0; e < t.length; e += 1) {
				let n = t[(o + e) % t.length];
				if ((n.textContent ?? "").trim().toLowerCase().startsWith(s)) {
					r = n;
					break;
				}
			}
			if (!r) return;
		} else return;
		e.preventDefault(), r?.focus();
	}, P = ["ss-menu-button"];
	g && P.push(g);
	let F = ["ss-menu"];
	return _ && F.push(_), /* @__PURE__ */ n(e, { children: [/* @__PURE__ */ t("button", {
		ref: x,
		id: y,
		type: "button",
		"aria-haspopup": "menu",
		"aria-expanded": C,
		"aria-controls": C ? b : void 0,
		"aria-label": i,
		className: P.join(" "),
		onClick: () => C ? A() : j("first"),
		onKeyDown: M,
		children: r
	}), /* @__PURE__ */ t(Me, {
		open: C,
		onClose: A,
		anchorRef: x,
		placement: o,
		offset: s,
		matchAnchorWidth: l,
		container: f,
		role: "none",
		autoFocus: !1,
		className: v,
		children: /* @__PURE__ */ t(Ne.Provider, {
			value: { close: A },
			children: /* @__PURE__ */ t("div", {
				ref: S,
				id: b,
				role: "menu",
				"aria-labelledby": i ? void 0 : y,
				"aria-label": i,
				className: F.join(" "),
				onKeyDown: N,
				children: a
			})
		})
	})] });
}
function Ie({ onSelect: e, disabled: n, className: r, children: i, render: a }) {
	let o = l(Ne);
	if (!o) throw Error("MenuItem must be rendered inside <Menu>");
	let s = ["ss-menu-item"];
	n && s.push("ss-menu-item--disabled"), r && s.push(r);
	let c = {
		role: "menuitem",
		tabIndex: -1,
		"aria-disabled": n || void 0,
		className: s.join(" "),
		children: i,
		onClick: () => {
			n || (e?.(), o.close());
		}
	};
	if (a) {
		let e = (e) => {
			e.key === " " && (e.preventDefault(), e.currentTarget.click());
		};
		return a({
			...c,
			onKeyDown: e
		});
	}
	return /* @__PURE__ */ t("button", {
		type: "button",
		...c
	});
}
//#endregion
//#region src/components/Accordion/Accordion.tsx
function Le({ title: e, headingLevel: r = 2, children: i, collapsedLabel: a = "Read more", expandedLabel: o = "Read less", defaultExpanded: s = !1, className: c }) {
	let [l, u] = h(s), f = d(), p = `h${r}`, m = ["ss-accordion"];
	return c && m.push(c), /* @__PURE__ */ n("div", {
		className: m.join(" "),
		children: [
			/* @__PURE__ */ t(p, {
				className: "ss-accordion-title",
				children: e
			}),
			/* @__PURE__ */ t("div", {
				id: f,
				className: l ? "ss-accordion-body ss-accordion-body--expanded" : "ss-accordion-body",
				children: /* @__PURE__ */ t("p", {
					className: "ss-accordion-text",
					children: i
				})
			}),
			/* @__PURE__ */ n("button", {
				type: "button",
				className: "ss-accordion-toggle",
				"aria-expanded": l,
				"aria-controls": f,
				onClick: () => u(!l),
				children: [/* @__PURE__ */ t("span", {
					className: "ss-accordion-icon",
					"aria-hidden": "true",
					children: /* @__PURE__ */ t("svg", {
						viewBox: "0 0 8 8",
						width: "12",
						height: "12",
						focusable: "false",
						children: /* @__PURE__ */ t("path", {
							d: "M0 4h8M4 0v8",
							stroke: "currentColor",
							strokeWidth: "1.33",
							strokeLinecap: "butt",
							fill: "none"
						})
					})
				}), l ? o : a]
			})
		]
	});
}
//#endregion
//#region src/components/ColoredIcon/ColoredIcon.tsx
function Re({ icon: e, size: n = "sm", color: r = "violet-400", className: i }) {
	let a = ["ss-colored-icon", `ss-colored-icon--${n}`];
	return i && a.push(i), /* @__PURE__ */ t("span", {
		className: a.join(" "),
		style: { backgroundColor: y(r) },
		"aria-hidden": "true",
		children: /* @__PURE__ */ t(e, {
			className: `ss-colored-icon-svg ss-colored-icon-svg--${n}`,
			focusable: "false"
		})
	});
}
//#endregion
//#region src/components/ContactBadge/ContactBadge.tsx
function ze({ icon: e, label: r, text: i, className: a }) {
	let o = ["ss-contact-badge"];
	return a && o.push(a), /* @__PURE__ */ n("div", {
		className: o.join(" "),
		children: [/* @__PURE__ */ t("div", {
			className: "ss-contact-badge-icon",
			children: e
		}), /* @__PURE__ */ n("div", {
			className: "ss-contact-badge-body",
			children: [/* @__PURE__ */ t("span", {
				className: "ss-contact-badge-label",
				children: r
			}), /* @__PURE__ */ t("span", {
				className: "ss-contact-badge-text",
				children: i
			})]
		})]
	});
}
//#endregion
//#region src/components/LabeledStack/LabeledStack.tsx
function Be({ values: e, colors: r, tones: i, renderValueFns: a = [], className: o }) {
	let s = ["ss-labeled-stack"];
	return o && s.push(o), /* @__PURE__ */ t("div", {
		className: s.join(" "),
		children: e.map((e, o) => /* @__PURE__ */ t("div", {
			className: `ss-labeled-stack-tile ss-labeled-stack-tile--${i[o]}`,
			style: { backgroundColor: y(r[o]) },
			children: a[o] ? a[o](e) : /* @__PURE__ */ n("span", {
				className: "ss-labeled-stack-value",
				children: [e, "%"]
			})
		}, o))
	});
}
//#endregion
//#region src/components/FileDropzone/FileDropzone.tsx
function Ve(e) {
	return /* @__PURE__ */ t("svg", {
		viewBox: "0 0 20 20",
		...e,
		children: /* @__PURE__ */ t("path", {
			d: "M8.75 15V4.8125L5.5 8.0625L3.75 6.25L10 0L16.25 6.25L14.5 8.0625L11.25 4.8125V15H8.75ZM2.5 20C1.8125 20 1.22396 19.7552 0.734375 19.2656C0.244792 18.776 0 18.1875 0 17.5V13.75H2.5V17.5H17.5V13.75H20V17.5C20 18.1875 19.7552 18.776 19.2656 19.2656C18.776 19.7552 18.1875 20 17.5 20H2.5Z",
			fill: "currentColor"
		})
	});
}
var He = (e) => e?.[0];
function Ue({ description: e, file: r, onFileSelect: i, onRemove: a, accept: o, error: s, warning: c, size: l = "default", browseLabel: u = "Upload from computer", removeLabel: f = "Remove file", id: p, className: g }) {
	let _ = d(), v = p ?? _, y = `${v}-error`, b = `${v}-warning`, x = m(null), [S, C] = h(!1), [w, T] = h(!1), E = (e) => {
		e.preventDefault(), e.stopPropagation();
	}, D = (e) => {
		E(e), C(!1);
		let t = He(e.dataTransfer?.files);
		t && i(t);
	}, O = (e) => {
		E(e), C(!0);
	}, k = (e) => {
		E(e), C(!1);
	}, A = (e) => {
		let t = He(e.target.files);
		t && i(t);
	}, j = () => {
		a(), x.current && (x.current.value = "");
	}, M = [r && s ? y : null, r && c ? b : null].filter(Boolean).join(" ") || void 0, N = ["ss-dropzone"];
	g && N.push(g);
	let P = ["ss-dropzone-area"];
	l === "tall" && P.push("ss-dropzone-area--tall"), S && P.push("ss-dropzone-area--dragging");
	let F = ["ss-dropzone-file"];
	return s ? F.push("ss-dropzone-file--error") : c && F.push("ss-dropzone-file--warning"), /* @__PURE__ */ n("div", {
		className: N.join(" "),
		children: [/* @__PURE__ */ n("div", {
			className: P.join(" "),
			onDrop: D,
			onDragOver: O,
			onDragLeave: k,
			children: [
				/* @__PURE__ */ t(Re, { icon: Ve }),
				/* @__PURE__ */ t("div", {
					className: "ss-dropzone-description",
					children: e
				}),
				/* @__PURE__ */ n("div", {
					className: "ss-dropzone-browse",
					children: [
						/* @__PURE__ */ t("span", { children: "Or" }),
						/* @__PURE__ */ t("label", {
							className: w ? "ss-dropzone-link ss-dropzone-link--focus" : "ss-dropzone-link",
							htmlFor: v,
							children: u
						}),
						/* @__PURE__ */ t("input", {
							ref: x,
							id: v,
							className: "ss-dropzone-input",
							type: "file",
							accept: o,
							"aria-describedby": M,
							onChange: A,
							onFocus: (e) => T(e.target.matches(":focus-visible")),
							onBlur: () => T(!1)
						})
					]
				})
			]
		}), r && /* @__PURE__ */ n("div", {
			className: "ss-dropzone-result",
			children: [
				/* @__PURE__ */ n("div", {
					className: F.join(" "),
					children: [/* @__PURE__ */ n("div", {
						className: "ss-dropzone-file-info",
						children: [/* @__PURE__ */ t("svg", {
							className: "ss-dropzone-file-icon",
							viewBox: "0 0 22 26",
							"aria-hidden": "true",
							focusable: "false",
							children: /* @__PURE__ */ t("path", {
								d: "M19.805 20.5833H7.68971C7.10039 20.5833 6.60156 20.3791 6.19323 19.9708C5.7849 19.5624 5.58073 19.0636 5.58073 18.4743V2.85898C5.58073 2.26966 5.7849 1.77083 6.19323 1.3625C6.60156 0.954167 7.10039 0.75 7.68971 0.75H15.7891L21.914 6.87494V18.4743C21.914 19.0636 21.7098 19.5624 21.3015 19.9708C20.8932 20.3791 20.3943 20.5833 19.805 20.5833ZM14.9141 7.74994V2.49997H7.68971C7.59996 2.49997 7.51768 2.53736 7.44288 2.61215C7.36809 2.68695 7.3307 2.76923 7.3307 2.85898V18.4743C7.3307 18.5641 7.36809 18.6463 7.44288 18.7211C7.51768 18.7959 7.59996 18.8333 7.68971 18.8333H19.805C19.8948 18.8333 19.9771 18.7959 20.0519 18.7211C20.1266 18.6463 20.164 18.5641 20.164 18.4743V7.74994H14.9141ZM3.02305 25.2499C2.43372 25.2499 1.9349 25.0458 1.52656 24.6374C1.11823 24.2291 0.914062 23.7303 0.914062 23.141V7.75H2.66403V23.141C2.66403 23.2307 2.70142 23.313 2.77621 23.3878C2.85101 23.4626 2.93329 23.5 3.02305 23.5H14.914V25.2499L3.02305 25.2499Z",
								fill: "currentColor"
							})
						}), /* @__PURE__ */ t("span", {
							className: "ss-dropzone-file-name",
							children: r.name
						})]
					}), /* @__PURE__ */ t("button", {
						type: "button",
						className: "ss-dropzone-remove",
						"aria-label": f,
						onClick: j,
						children: /* @__PURE__ */ t("svg", {
							viewBox: "0 0 14 14",
							width: "14",
							height: "14",
							"aria-hidden": "true",
							focusable: "false",
							children: /* @__PURE__ */ t("path", {
								d: "M1.4 14L0 12.6L5.6 7L0 1.4L1.4 0L7 5.6L12.6 0L14 1.4L8.4 7L14 12.6L12.6 14L7 8.4L1.4 14Z",
								fill: "currentColor"
							})
						})
					})]
				}),
				s && /* @__PURE__ */ t("div", {
					id: y,
					className: "ss-dropzone-message ss-dropzone-message--error",
					role: "alert",
					children: s
				}),
				c && /* @__PURE__ */ t("div", {
					id: b,
					className: "ss-dropzone-message ss-dropzone-message--warning",
					role: "status",
					children: c
				})
			]
		})]
	});
}
//#endregion
//#region src/components/Textarea/Textarea.tsx
var We = o(function({ label: e, hint: r, error: i, className: a, id: o, required: s, "aria-describedby": c, ...l }, u) {
	let f = d(), p = o ?? f, m = `${p}-hint`, h = `${p}-error`, g = !!i, _ = [
		c,
		r ? m : void 0,
		g ? h : void 0
	].filter(Boolean).join(" ");
	return /* @__PURE__ */ n("div", {
		className: a ? `ss-field ${a}` : "ss-field",
		children: [
			e && /* @__PURE__ */ n("label", {
				className: "ss-field-label",
				htmlFor: p,
				children: [e, s && /* @__PURE__ */ t("span", {
					className: "ss-field-required",
					"aria-hidden": "true",
					children: " *"
				})]
			}),
			/* @__PURE__ */ t("textarea", {
				ref: u,
				id: p,
				className: g ? "ss-textarea ss-textarea--error" : "ss-textarea",
				required: s,
				"aria-invalid": g || void 0,
				"aria-describedby": _ || void 0,
				...l
			}),
			r && /* @__PURE__ */ t("span", {
				id: m,
				className: "ss-field-hint",
				children: r
			}),
			g && /* @__PURE__ */ t("span", {
				id: h,
				className: "ss-field-error",
				role: "alert",
				children: i
			})
		]
	});
}), Ge = o(function({ label: e, variant: r = "primary", Icon: i, className: a, value: o, checked: s, disabled: c, ...l }, u) {
	let d = ["ss-radio-tab", `ss-radio-tab--${r}`];
	s && d.push("ss-radio-tab--checked"), c && d.push("ss-radio-tab--disabled"), a && d.push(a);
	let f = ["ss-radio-tab-face", `ss-radio-tab-face--${r}`];
	return s && f.push("ss-radio-tab-face--checked"), /* @__PURE__ */ n("label", {
		className: d.join(" "),
		children: [/* @__PURE__ */ t("input", {
			ref: u,
			type: "radio",
			className: `ss-radio-tab-input ss-radio-tab-input--${r}`,
			value: o,
			checked: s,
			disabled: c,
			...l
		}), /* @__PURE__ */ n("span", {
			className: f.join(" "),
			children: [i && /* @__PURE__ */ t(i, {
				className: "ss-radio-tab-icon",
				"aria-hidden": "true"
			}), e ?? o]
		})]
	});
}), Ke = a(null);
function qe({ name: e, legend: r, value: i, onValueChange: a, disabled: o, className: s, children: c, ...l }) {
	let u = d(), f = s ? `ss-radio-group ${s}` : "ss-radio-group";
	return /* @__PURE__ */ t(Ke.Provider, {
		value: {
			name: e ?? u,
			value: i,
			onValueChange: a,
			disabled: o
		},
		children: /* @__PURE__ */ n("fieldset", {
			className: f,
			disabled: o,
			...l,
			children: [r && /* @__PURE__ */ t("legend", {
				className: "ss-radio-legend",
				children: r
			}), c]
		})
	});
}
var Je = o(function({ label: e, className: r, disabled: i, value: a, checked: o, onChange: s, name: c, ...u }, d) {
	let f = l(Ke), p = i ?? f?.disabled, m = ["ss-radio-row"];
	p && m.push("ss-radio-row--disabled"), r && m.push(r);
	let h = f !== null && typeof a == "string";
	return /* @__PURE__ */ n("label", {
		className: m.join(" "),
		children: [/* @__PURE__ */ t("input", {
			ref: d,
			type: "radio",
			className: "ss-radio",
			name: c ?? f?.name,
			value: a,
			checked: o ?? (h && f.value !== void 0 ? f.value === a : void 0),
			onChange: (e) => {
				s?.(e), h && f.onValueChange?.(a);
			},
			disabled: p,
			...u
		}), e && /* @__PURE__ */ t("span", {
			className: "ss-radio-text",
			children: e
		})]
	});
});
//#endregion
export { Le as Accordion, A as Badge, _ as Button, M as Card, F as ChartFrame, Oe as ChatBubble, Ae as ChatThread, w as Checkbox, Re as ColoredIcon, ze as ContactBadge, R as DEFAULT_LEVEL_NAMES, L as DEFAULT_LEVEL_THRESHOLDS, I as EmptyState, Ue as FileDropzone, O as InfoIcon, S as Input, Be as LabeledStack, B as LevelIndicator, U as LockedSection, Fe as Menu, Ie as MenuItem, xe as Modal, q as Pagination, Me as Popover, Je as Radio, qe as RadioGroup, C as Select, P as Spinner, T as Switch, pe as Tab, fe as TabList, me as TabPanel, Ge as TabRadio, ae as Table, de as Tabs, We as Textarea, Ee as ToastProvider, E as Tooltip, ke as TypingIndicator, z as getLevel, G as pageWindow, v as paletteColors, x as paletteValue, y as paletteVar, b as tokenValue, Ce as useToast };

//# sourceMappingURL=index.js.map