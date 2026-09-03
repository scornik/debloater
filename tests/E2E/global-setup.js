/**
 * Sign in once, before any scenario runs.
 *
 * Every scenario needs an administrator session and not one of them is *about*
 * logging in. Doing it thirteen times meant thirteen form submissions against a
 * WordPress that is also running WooCommerce, Elementor and a page builder on a
 * shared CI runner — and on that runner a login occasionally took longer than a
 * minute, failing a test that had nothing to do with what it was checking.
 *
 * So it happens once here, and the saved cookies are handed to every test. The
 * `login()` helper stays exactly as it was: with a session already in place
 * WordPress redirects away from the login form, the helper sees no form and
 * returns immediately.
 */

const { chromium } = require( '@playwright/test' );
const path = require( 'path' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

const STATE_PATH = path.join( __dirname, '.auth', 'admin.json' );

module.exports = async () => {
	const browser = await chromium.launch();
	const context = await browser.newContext( { baseURL: BASE_URL } );
	const page = await context.newPage();

	// Generous, and only paid once. A fresh wp-env has just finished installing
	// WordPress and five plugins, and the first request to it is the slowest
	// one it will ever serve.
	page.setDefaultTimeout( 120_000 );
	page.setDefaultNavigationTimeout( 120_000 );

	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );

	await page.locator( '#wp-submit' ).waitFor( { state: 'visible' } );

	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASSWORD );

	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.click( '#wp-submit' ),
	] );

	await context.storageState( { path: STATE_PATH } );

	await browser.close();
};

module.exports.STATE_PATH = STATE_PATH;
