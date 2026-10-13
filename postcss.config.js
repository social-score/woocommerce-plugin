/**
 * Scopes every rule of the shared stylesheets under `.socialscore-admin` so nothing leaks into WP admin:
 * `:root` token blocks become the root class, all other selectors are prefixed with it.
 */
const ROOT = '.socialscore-admin';

const scope = () => ( {
	postcssPlugin: 'socialscore-scope',
	Rule( rule ) {
		let parent = rule.parent;
		while ( parent ) {
			if ( parent.type === 'atrule' && /keyframes$/.test( parent.name ) ) {
				return;
			}
			parent = parent.parent;
		}
		if ( rule.__socialscoreScoped ) {
			return;
		}
		rule.__socialscoreScoped = true;
		rule.selectors = rule.selectors.map( ( selector ) => {
			if ( selector === ':root' || selector === 'html' || selector === 'body' ) {
				return ROOT;
			}
			if ( selector.startsWith( ROOT ) ) {
				return selector;
			}
			return `${ ROOT } ${ selector.replace( /^:root\s+/, '' ) }`;
		} );
	},
} );
scope.postcss = true;

module.exports = { plugins: [ scope ] };
