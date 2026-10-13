const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	testMatch: [ '<rootDir>/tests/js/**/*.test.[jt]s?(x)' ],
	// The release layout in dist/ holds a second package.json.
	modulePathIgnorePatterns: [ '<rootDir>/dist/' ],
	// The shared packages are ESM.
	transformIgnorePatterns: [ '/node_modules/(?!@socialscore)' ],
	moduleNameMapper: {
		...( base.moduleNameMapper || {} ),
		'\\.css$': require.resolve( '@wordpress/jest-preset-default/scripts/style-mock.js' ),
	},
};
