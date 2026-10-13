// WordPress 6.4 and 6.5 do not register `react-jsx-runtime`. The shared SocialScore packages and the
// automatic JSX transform import `react/jsx-runtime`, so this small shim provides it on top of the
// `react` that WordPress ships (an external, never bundled). It is plugin source, not a copy of React.
import { createElement, Fragment, type ElementType, type ReactElement } from 'react';

export { Fragment };

type Props = { children?: unknown; [ prop: string ]: unknown };

export function jsx( type: ElementType, props: Props | null, key?: string ): ReactElement {
	const { children, ...rest } = props || {};
	if ( key !== undefined ) {
		rest.key = key;
	}
	return children === undefined ? createElement( type, rest ) : createElement( type, rest, children as never );
}

export function jsxs( type: ElementType, props: Props | null, key?: string ): ReactElement {
	const { children, ...rest } = props || {};
	if ( key !== undefined ) {
		rest.key = key;
	}
	return createElement( type, rest, ...( Array.isArray( children ) ? children : [ children ] ) as never[] );
}

export const jsxDEV = jsx;
