/**
 * Build configuration for Scrobbled Blocks.
 *
 * Extends the @wordpress/scripts default config with one change: JSX is compiled
 * with the classic runtime instead of the automatic one.
 *
 * @wordpress/babel-preset-default compiles JSX with React's automatic runtime,
 * which makes the bundle depend on the `react-jsx-runtime` script handle. That
 * handle was only registered in WordPress core from 6.6. On anything older,
 * WordPress silently skips enqueuing a script whose dependency it cannot
 * resolve, so the blocks register server-side but never call registerBlockType()
 * in the browser -- the editor then reports "Your site does not support this
 * block" while the front end, being server-rendered, looks fine.
 *
 * The plugin supports WordPress 6.0 and newer, so the build must not rely on
 * 6.6+ infrastructure. Compiling JSX to wp.element.createElement() calls keeps
 * the output working on every supported version. wp-element has shipped with
 * core since 5.0 and is already a declared dependency of both blocks, because
 * their edit.js files import hooks from @wordpress/element.
 *
 * Verify after building: build/<block>/index.asset.php should list wp-element
 * and must not list react-jsx-runtime.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const JSX_TRANSFORM = [
	require.resolve( '@babel/plugin-transform-react-jsx' ),
	{
		runtime: 'classic',
		pragma: 'window.wp.element.createElement',
		pragmaFrag: 'window.wp.element.Fragment',
	},
];

/**
 * Add the classic JSX transform to every babel-loader in a rule.
 *
 * Babel runs plugins before presets, so the JSX is already lowered by the time
 * @wordpress/babel-preset-default's automatic transform would have run.
 *
 * @param {Object} rule A webpack module rule.
 * @return {Object} The rule, with babel-loader configured for the classic runtime.
 */
const useClassicJsxRuntime = ( rule ) => {
	const entries = Array.isArray( rule.use ) ? rule.use : [ rule.use ];

	if ( ! entries.some( ( u ) => u && u.loader && u.loader.includes( 'babel-loader' ) ) ) {
		return rule;
	}

	return {
		...rule,
		use: entries.map( ( u ) => {
			if ( ! u || ! u.loader || ! u.loader.includes( 'babel-loader' ) ) {
				return u;
			}

			return {
				...u,
				options: {
					...u.options,
					plugins: [ ...( u.options?.plugins ?? [] ), JSX_TRANSFORM ],
				},
			};
		} ),
	};
};

const configure = ( config ) => ( {
	...config,
	module: {
		...config.module,
		rules: config.module.rules.map( useClassicJsxRuntime ),
	},
} );

module.exports = Array.isArray( defaultConfig )
	? defaultConfig.map( configure )
	: configure( defaultConfig );
