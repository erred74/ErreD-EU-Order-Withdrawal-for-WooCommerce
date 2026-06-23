/**
 * Playwright configuration for the plugin's end-to-end suite.
 *
 * Extends the @wordpress/scripts base config (admin auth via its global setup, the wp-env tests
 * site on port 8889, reduced-motion + serial workers) and only points the runner at our specs.
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
};
