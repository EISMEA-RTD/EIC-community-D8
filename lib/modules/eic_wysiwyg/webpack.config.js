/**
 * @file
 * Builds this module's CKEditor 5 plugins into js/build.
 *
 * A copy of web/core/modules/ckeditor5/webpack.config.js, with the
 * DllReferencePlugin manifest retargeted at this module's own node_modules.
 * The manifest ships only inside the npm "ckeditor5" package -- it is not part
 * of web/core/assets/vendor/ckeditor5 -- which is why this module carries a
 * dev-only npm dependency. Nothing at runtime needs node.
 *
 * The output filename must equal the plugin directory name so that the
 * "ddcPopover.DdcPopover" id in eic_wysiwyg.ckeditor5.yml resolves.
 */

const path = require('path');
const fs = require('fs');
const webpack = require('webpack');
const TerserPlugin = require('terser-webpack-plugin');

function getDirectories(srcpath) {
  return fs
    .readdirSync(srcpath)
    .filter((item) => fs.statSync(path.join(srcpath, item)).isDirectory());
}

const prodPluginBuilds = [];
const devPluginBuilds = [];

getDirectories(path.resolve(__dirname, './js/ckeditor5_plugins')).forEach((dir) => {
  const bc = {
    mode: 'production',
    optimization: {
      minimize: true,
      minimizer: [
        new TerserPlugin({
          terserOptions: {
            format: {
              comments: false,
            },
          },
          test: /\.js(\?.*)?$/i,
          extractComments: false,
        }),
      ],
      moduleIds: 'named',
    },
    entry: {
      path: path.resolve(__dirname, 'js/ckeditor5_plugins', dir, 'src/index.js'),
    },
    output: {
      path: path.resolve(__dirname, './js/build'),
      filename: `${dir}.js`,
      library: ['CKEditor5', dir],
      libraryTarget: 'umd',
      libraryExport: 'default',
    },
    plugins: [
      new webpack.BannerPlugin('cspell:disable'),
      new webpack.DllReferencePlugin({
        manifest: require(path.resolve(__dirname, './node_modules/ckeditor5/build/ckeditor5-dll.manifest.json')), // eslint-disable-line global-require, import/no-unresolved
        scope: 'ckeditor5/src',
        name: 'CKEditor5.dll',
      }),
    ],
    module: {
      rules: [{ test: /\.svg$/, type: 'asset/source' }],
    },
  };

  const dev = {
    ...bc,
    mode: 'development',
    optimization: { ...bc.optimization, minimize: false },
    devtool: false,
  };

  prodPluginBuilds.push(bc);
  devPluginBuilds.push(dev);
});

module.exports = (env, argv) =>
  argv.mode === 'development' ? devPluginBuilds : prodPluginBuilds;
