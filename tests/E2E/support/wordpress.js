/**
 * Shared helpers for the end-to-end suite.
 *
 * Everything a scenario needs to get to the point: signing in, opening our
 * screen, and asking the site a question over WP-CLI when the browser is the
 * wrong instrument for it.
 */

const { execFile } = require( 'child_process' );
const { promisify } = require( 'util' );

const run = promisify( execFile );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

/**
 * Sign in as an administrator.
 *
 * WordPress's login form rather than a cookie injected from outside, because
 * half of what these scenarios exercise is the admin screen behaving like an
 * admin screen — capabilities, nonces, the REST cookie. A faked session would
 * skip exactly the parts most worth checking.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function login( page ) {
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );

	// Already signed in: WordPress redirects straight to the dashboard.
	if ( ! ( await page.locator( '#loginform' ).count() ) ) {
		return;
	}

	// Waited for rather than assumed. wp-env's WordPress is a container behind
	// a container, and under a suite that keeps applying and rolling back
	// changes it is sometimes slow to paint — which shows up as a click timing
	// out on a button that was always going to arrive.
	const submit = page.locator( '#wp-submit' );

	await submit.waitFor( { state: 'visible', timeout: 60_000 } );

	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASSWORD );

	await Promise.all( [
		page.waitForURL( /wp-admin/, { timeout: 60_000 } ),
		submit.click( { timeout: 60_000 } ),
	] );
}

/**
 * Open the WP Debloat screen.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openDebloat( page ) {
	await page.goto( '/wp-admin/admin.php?page=wp-debloat' );
	await page.waitForSelector( '#wpdebloat-root', { state: 'attached' } );
}

/**
 * Exit codes `wp debloat apply` may legitimately return.
 *
 * 0 is applied and verified. 3 is applied but *not* verified — which is what
 * happens whenever the site cannot reach itself over HTTP, and wp-env is
 * exactly such a site: the runner and the web server are separate containers,
 * so `localhost:8888` inside the container is not the site (docs/DECISIONS.md
 * D-0009, and the same limitation Phase 6 records).
 *
 * Listed rather than ignored. A blanket "any exit code is fine" would swallow
 * the one thing these scenarios exist to catch.
 */
const APPLY_EXIT_CODES = [ 0, 3 ];

/**
 * Run a WP-CLI command inside the wp-env development container.
 *
 * Used for the things a browser cannot see: what the stored runtime hash is,
 * what a scan actually recorded, whether a rollback restored the previous
 * selection exactly. Reading those through the UI would be testing the UI's
 * rendering of them rather than the fact itself.
 *
 * @param {string[]} args    WP-CLI arguments.
 * @param {number[]} allowed Exit codes to treat as success. Defaults to [0].
 * @return {Promise<string>} stdout, trimmed.
 */
async function wpCli( args, allowed = [ 0 ] ) {
	let stdout;

	try {
		( { stdout } = await run(
			'npx',
			[
				'wp-env',
				'run',
				'cli',
				'--env-cwd=wp-content/plugins/wp-debloat',
				'wp',
				...args,
			],
			{ shell: process.platform === 'win32', maxBuffer: 32 * 1024 * 1024 }
		) );
	} catch ( error ) {
		// wp-env is a wrapper: it reports its own exit status and prints the
		// wrapped command's as text. So the number that matters is in the
		// output, not in error.code, and reading only the latter would treat
		// every WP-CLI exit code as the same failure.
		const reported = /exit code (\d+)/.exec(
			`${ error.stdout || '' }${ error.stderr || '' }${ error.message || '' }`
		);

		const code = reported ? Number( reported[ 1 ] ) : error.code;

		if ( ! allowed.includes( code ) ) {
			throw error;
		}

		stdout = error.stdout || '';
	}

	// wp-env prefixes its own progress lines; the command's own output is
	// whatever sits between them.
	return stdout
		.split( '\n' )
		.filter(
			( line ) =>
				! line.startsWith( 'ℹ' ) &&
				! line.startsWith( '✔' ) &&
				! line.startsWith( '✖' )
		)
		.join( '\n' )
		.trim();
}

/**
 * A URL for a post that works whatever the permalink structure is.
 *
 * The fixture site runs on plain permalinks on purpose — that is WordPress's
 * default and the configuration that hid the REST bug this phase found — so a
 * pretty path cannot be assumed. `?p=` with the post type is always right.
 *
 * @param {string|number} id   Post id.
 * @param {string}        type Post type.
 * @return {string} Root-relative URL.
 */
function postUrl( id, type = 'post' ) {
	// Pages are addressed by `page_id`, not `p`. WordPress ignores `?p=` for a
	// page and serves the front page instead, which is a silent wrong answer —
	// a test looking for a form finds nothing and blames the form.
	if ( 'page' === type ) {
		return `/?page_id=${ id }`;
	}

	return 'post' === type
		? `/?p=${ id }`
		: `/?p=${ id }&post_type=${ type }`;
}

/**
 * The plugin's own status, as JSON.
 *
 * @return {Promise<Object>} Decoded status.
 */
async function debloatStatus() {
	const output = await wpCli( [ 'debloat', 'status', '--format=json', '--allow-root', '--skip-themes' ] );

	return JSON.parse( output );
}

module.exports = {
	login,
	openDebloat,
	wpCli,
	postUrl,
	debloatStatus,
	APPLY_EXIT_CODES,
	ADMIN_USER,
	ADMIN_PASSWORD,
};
