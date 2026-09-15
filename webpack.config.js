const path = require( 'path' );
const webpack = require( 'webpack' );

/**
 * The picker bundles only its own code. React, ReactDOM, and the WordPress
 * packages come from the script registry via the wp-element / wp-i18n
 * dependencies declared in class-mediagraph-editor.php, so the plugin can
 * never ship a second copy of React into the editor.
 *
 * JSX compiles with the classic pragma (see babel.config.js) and ProvidePlugin
 * injects createElement / Fragment, so JSX files need no boilerplate imports.
 */
module.exports = ( env, argv ) => ( {
	entry: {
		'mediagraph-picker.bundle': './admin/js/src/index.jsx',
		'mediagraph-blocks.bundle': './admin/js/src/blocks/index.jsx',
	},
	output: {
		path: path.resolve( __dirname, 'admin/js/dist' ),
		filename: '[name].js',
		clean: true,
	},
	module: {
		rules: [
			{
				test: /\.(js|jsx)$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							[ '@babel/preset-env', { targets: { browsers: [ '> 1%', 'not dead' ] } } ],
							[
								'@babel/preset-react',
								{
									runtime: 'classic',
									pragma: 'createElement',
									pragmaFrag: 'Fragment',
								},
							],
						],
					},
				},
			},
		],
	},
	plugins: [
		new webpack.ProvidePlugin( {
			createElement: [ '@wordpress/element', 'createElement' ],
			Fragment: [ '@wordpress/element', 'Fragment' ],
		} ),
	],
	resolve: {
		extensions: [ '.js', '.jsx' ],
	},
	externals: {
		react: 'React',
		'react-dom': 'ReactDOM',
		'@wordpress/element': 'wp.element',
		'@wordpress/i18n': 'wp.i18n',
		'@wordpress/components': 'wp.components',
		'@wordpress/data': 'wp.data',
		'@wordpress/hooks': 'wp.hooks',
		'@wordpress/compose': 'wp.compose',
		'@wordpress/blocks': 'wp.blocks',
		'@wordpress/block-editor': 'wp.blockEditor',
		jquery: 'jQuery',
	},
	devtool: argv && argv.mode === 'production' ? false : 'source-map',
	performance: {
		hints: false,
	},
} );
