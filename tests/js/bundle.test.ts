/**
 * Guards the WordPress.org rule that React comes from WordPress, not from our bundle.
 * The output tests need `npm run build` first (CI and the release flow always build before testing).
 */
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createElement, Fragment } from 'react';
import { jsx, jsxs } from '../../src/js/jsx-runtime-shim';

const build = join(__dirname, '../../build');

describe.each( [ 'dashboard', 'panel' ] )( 'build/%s', ( entry ) => {
	it( 'was built', () => {
		expect( existsSync( join( build, `${ entry }.js` ) ) ).toBe( true );
	} );

	it( 'does not contain the source of React, ReactDOM or the scheduler', () => {
		const code = readFileSync( join( build, `${ entry }.js` ), 'utf8' );
		expect( code ).not.toContain( '__SECRET_INTERNALS_DO_NOT_USE_OR_YOU_WILL_BE_FIRED' );
		expect( code ).not.toContain( 'react.production' );
		expect( code ).not.toMatch( /\b18\.\d+\.\d+\b/ );
		expect( code.length ).toBeLessThan( 120_000 );
	} );

	it( 'takes React from the WordPress globals', () => {
		const code = readFileSync( join( build, `${ entry }.js` ), 'utf8' );
		expect( code ).toContain( 'window.React' );
		expect( code ).toContain( 'window.ReactDOM' );
	} );

	it( 'lists the react and react-dom script handles as dependencies', () => {
		const asset = readFileSync( join( build, `${ entry }.asset.php` ), 'utf8' );
		expect( asset ).toMatch( /'react'/ );
		expect( asset ).toMatch( /'react-dom'/ );
	} );
} );

describe( 'jsx runtime shim', () => {
	it( 'builds the same elements as createElement', () => {
		expect( jsx( 'p', { className: 'a', children: 'x' }, 'k' ) ).toEqual( createElement( 'p', { className: 'a', key: 'k' }, 'x' ) );
		expect( jsxs( 'ul', { children: [ createElement( 'li', { key: 1 } ), createElement( 'li', { key: 2 } ) ] } ) ).toEqual(
			createElement( 'ul', null, createElement( 'li', { key: 1 } ), createElement( 'li', { key: 2 } ) )
		);
		expect( jsx( Fragment, {} ) ).toEqual( createElement( Fragment, {} ) );
	} );
} );
