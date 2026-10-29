const path = require( 'path' );

// Resolve @wordpress/scripts from TC's own node_modules first,
// then fall back to HT's sibling node_modules for local development.
let wpScriptsConfig;
try {
	wpScriptsConfig = require.resolve( '@wordpress/scripts/config/webpack.config' );
} catch ( e ) {
	// Fallback to sibling HT install during local development.
	wpScriptsConfig = path.resolve( __dirname, '../hypertaxonomy/node_modules/@wordpress/scripts/config/webpack.config' );
}
const defaultConfig = require( wpScriptsConfig );

// Add an entry here for each new block.
const blocks = [
	'peer-posts',
	'peer-browse',
];

// Each block gets its own config so output goes to its own build/ directory.
// JS: build/index.js  |  CSS: build/{block-name}.css  |  Asset: build/index.asset.php
module.exports = blocks.map( ( block ) => ( {
	...defaultConfig,
	entry: {
		[ block ]: path.resolve( __dirname, `blocks/${ block }/src/index.js` ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, `blocks/${ block }/build` ),
		filename: 'index.js',
	},
} ) );
