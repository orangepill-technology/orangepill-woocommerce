/**
 * Webpack config for Orangepill WooCommerce Blocks (PR-WC-BLOCKS-COMPATIBILITY-V1).
 *
 * Extends @wordpress/scripts defaults with a named entry so the output is
 * build/blocks.js (and build/blocks.asset.php) rather than build/index.js.
 * The asset.php file is auto-generated with content-hash version for cache-busting.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// @woocommerce/* packages are provided by WooCommerce as WordPress script handles
// at runtime — they are not installable npm packages. Declare them as externals so
// webpack replaces imports with the global variables WC exposes at page load.
const wcExternals = {
    '@woocommerce/blocks-registry': [ 'wc', 'wcBlocksRegistry' ],
    '@woocommerce/blocks-checkout': [ 'wc', 'blocksCheckout' ],
};

module.exports = {
    ...defaultConfig,
    entry: {
        blocks: './assets/js/blocks/index.jsx',
    },
    externals: {
        ...( defaultConfig.externals || {} ),
        ...wcExternals,
    },
};
