const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

// React and ReactDOM are never bundled: WordPress ships them (`react`, `react-dom` script handles) and the
// dependency extraction plugin maps every import of them, including the ones inside @socialscore/ui and
// @socialscore/plugin-core, to those globals and lists the handles in build/*.asset.php.
// Only `react/jsx-runtime` is not available in WordPress 6.4/6.5, so it resolves to a tiny shim on top of the external `react`.
const jsxShim = path.resolve( __dirname, 'src/js/jsx-runtime-shim.ts' );
const isJsxRuntime = ( request ) => /^react\/jsx(-dev)?-runtime$/.test( request );

module.exports = {
	...defaultConfig,
	entry: {
		dashboard: path.resolve( __dirname, 'src/js/dashboard.tsx' ),
		panel: path.resolve( __dirname, 'src/js/panel.tsx' ),
		'pixel-events': path.resolve( __dirname, 'src/js/pixel-events.ts' ),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve && defaultConfig.resolve.alias ),
			'react/jsx-runtime': jsxShim,
			'react/jsx-dev-runtime': jsxShim,
		},
	},
	plugins: [
		...defaultConfig.plugins.filter( ( plugin ) => ! ( plugin instanceof DependencyExtractionWebpackPlugin ) ),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) => ( isJsxRuntime( request ) ? false : undefined ),
			requestToHandle: ( request ) => ( isJsxRuntime( request ) ? false : undefined ),
		} ),
	],
};
