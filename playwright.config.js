const { defineConfig, devices } = require( '@playwright/test' );

const port = process.env.WP_ENV_PORT || '8888';
const baseURL = process.env.WP_BASE_URL || `http://localhost:${ port }`;

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 90_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: process.env.PLAYWRIGHT_WORKERS
		? Number( process.env.PLAYWRIGHT_WORKERS )
		: 1,
	reporter: process.env.CI
		? [ [ 'github' ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		baseURL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
