const path = require('path');
const { getWebpackEntryPoints } = require('@wordpress/scripts/utils');
const config = require('../../webpack.config');

module.exports = {
	...config,
	entry: {
		...getWebpackEntryPoints('script')(),
		index: path.resolve(__dirname, 'src/index.js'),
	},
};
