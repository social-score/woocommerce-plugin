const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

// React ships inside our bundle: the shared SocialScore packages need `react/jsx-runtime`, which older
// WordPress versions do not expose, and WP 6.4 is our minimum.
module.exports = {
	...defaultConfig,
	entry: {
		dashboard: path.resolve( __dirname, 'src/js/dashboard.tsx' ),
		panel: path.resolve( __dirname, 'src/js/panel.tsx' ),
	},
	plugins: [
		...defaultConfig.plugins.filter( ( plugin ) => ! ( plugin instanceof DependencyExtractionWebpackPlugin ) ),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) => ( /^react(-dom)?(\/|$)/.test( request ) ? false : undefined ),
			requestToHandle: ( request ) => ( /^react(-dom)?(\/|$)/.test( request ) ? false : undefined ),
		} ),
	],
};
