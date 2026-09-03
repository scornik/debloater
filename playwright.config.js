/**
 * Playwright configuration for the end-to-end suite (BUILD-SPEC §14, §17
 * Phase 16).
 *
 * This suite is CI-only and never ships. It runs against the wp-env development
 * environment, which is the one carrying the full stack — WooCommerce,
 * Elementor, Contact Form 7, Rank Math, LiteSpeed Cache — because the scenarios
 * worth automating are the ones the unit and integration suites cannot reach:
 * a real browser, a real checkout, a real editor.
 *
 * Everything here is deliberately unforgiving. Retries are off, because a test
 * that passes on the second attempt is a test that found something; and the
 * suite runs serially, because every scenario changes the same site and two of
 * them running at once would be testing the lock rather than the feature.
 */

const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: './tests/E2E',
	outputDir: './tests/E2E/.artifacts',

	// Sign in once rather than in every scenario. None of these tests is about
	// logging in, and on a loaded CI runner the thirteenth login is the one
	// that times out.
	globalSetup: require.resolve( './tests/E2E/global-setup.js' ),

	// One at a time. Each scenario applies changes to one shared site, and the
	// apply lock would turn a parallel run into a test of the lock.
	fullyParallel: false,
	workers: 1,

	// No retries. A flaky end-to-end test on a plugin that edits people's sites
	// is a finding, not a nuisance to be smoothed over.
	retries: 0,

	// A generous per-test budget: a scan fetches pages, an apply writes a
	// runtime file and verifies over loopback, and the Elementor editor is
	// slow to boot even when it is working.
	timeout: 120_000,
	expect: { timeout: 15_000 },

	forbidOnly: !! process.env.CI,
	reporter: process.env.CI
		? [ [ 'github' ], [ 'html', { open: 'never', outputFolder: './tests/E2E/.report' } ] ]
		: [ [ 'list' ] ],

	use: {
		baseURL: BASE_URL,
		storageState: path.join( __dirname, 'tests', 'E2E', '.auth', 'admin.json' ),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		actionTimeout: 20_000,
		navigationTimeout: 60_000,
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
