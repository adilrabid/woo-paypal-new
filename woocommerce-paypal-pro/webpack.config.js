const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
const WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const RtlCssPlugin = require('rtlcss-webpack-plugin');

const wcDepMap = {
    '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
    '@woocommerce/settings': ['wc', 'wcSettings']
};

const wcHandleMap = {
    '@woocommerce/blocks-registry': 'wc-blocks-registry',
    '@woocommerce/settings': 'wc-settings'
};

const requestToExternal = (request) => {
    if (wcDepMap[request]) {
        return wcDepMap[request];
    }
};

const requestToHandle = (request) => {
    if (wcHandleMap[request]) {
        return wcHandleMap[request];
    }
};

// Export configuration.
module.exports = {
    ...defaultConfig,
    entry: {
        'paypal-pro': path.resolve(__dirname, 'src', 'blocks', 'wcpprog-payment-gateway-integration', 'paypal-pro', 'index.js'),
        'paypal-ppcp': path.resolve(__dirname, 'src', 'blocks', 'wcpprog-payment-gateway-integration', 'paypal-ppcp', 'index.js'),
    },
    output: {
        path: path.resolve(__dirname, 'block-integration'),
        filename: path.join('[name]', 'index.js'),
    },
    plugins: [
        ...defaultConfig.plugins.filter(
            (plugin) =>
                plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
                plugin.constructor.name !== 'MiniCssExtractPlugin' &&
                plugin.constructor.name !== 'RtlCssPlugin'
        ),

        // Custom CSS output path
        new MiniCssExtractPlugin({
            filename: '[name]/index.css',
        }),

        new RtlCssPlugin({
            filename: '[name]/index-rtl.css',
        }),

        new WooCommerceDependencyExtractionWebpackPlugin({
            requestToExternal,
            requestToHandle
        })
    ]
};
