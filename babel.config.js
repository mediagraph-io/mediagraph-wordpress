/**
 * Babel config, shared by webpack and Jest.
 *
 * The classic JSX runtime is used deliberately. The automatic runtime resolves
 * `@wordpress/element/jsx-runtime`, which WordPress only exposes as a script
 * handle from 6.6 onwards; the classic pragma keeps the bundle working on 6.0.
 *
 * `createElement` and `Fragment` are injected by webpack's ProvidePlugin, so
 * individual JSX files do not have to import them.
 */
module.exports = {
	presets: [
		[ '@babel/preset-env', { targets: { node: 'current' } } ],
		[
			'@babel/preset-react',
			{
				runtime: 'classic',
				pragma: 'createElement',
				pragmaFrag: 'Fragment',
			},
		],
	],
};
