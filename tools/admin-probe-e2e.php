<?php
/**
 * The admin probe against a WordPress that really answers.
 *
 * BUILD-SPEC §11, and the one thing the integration suite cannot do. That suite
 * runs each test inside a database transaction which is rolled back, so a user
 * it creates — and the session token authorising that user's cookie — is
 * invisible to any other connection. A real HTTP request is served by a
 * different PHP process, so core rejects the cookie for a reason that has
 * nothing to do with whether the cookie was right.
 *
 * WP-CLI commits. Run through it, this is the whole path: a real
 * administrator, a real session, a real request across the network to Apache,
 * and core's own `auth_redirect()` deciding.
 *
 *     npm run test:probe-e2e
 *
 * The site is asked for at `tests-wordpress`, which is where the web container
 * answers on the Docker network, with a `Host` header of the address WordPress
 * believes it lives at — otherwise it redirects to its canonical host, which
 * this container cannot route to. Everything past that point is untouched.
 *
 * Not part of any automated suite: it needs a running wp-env and a network
 * layout that CI does not have. It exists so the claim "the dashboard loads
 * signed in" is something somebody can reproduce rather than something a mock
 * was told to say.
 *
 * @package Debloater
 */

// No strict_types here: wp-cli eval-file evaluates the file inside a function,
// and a declare must be the first statement of a script.

use Debloater\Verify\HttpClient;
use Debloater\Verify\Probes\AdminProbe;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

const LOOPBACK_HOST = 'tests-wordpress';

/**
 * Print a line.
 *
 * @param string $line The line.
 * @return void
 */
function debloater_e2e_say( string $line ): void {
	WP_CLI::line( $line );
}

$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$site_port = wp_parse_url( home_url(), PHP_URL_PORT );
$site      = null === $site_port ? $site_host : $site_host . ':' . $site_port;

debloater_e2e_say( 'site believes it is at: ' . $site );
debloater_e2e_say( 'reaching it at:         ' . LOOPBACK_HOST );

// Reachability first, so an unreachable container is not reported as a failed
// credential.
$reachable = wp_remote_get(
	'http://' . LOOPBACK_HOST . '/',
	array(
		'timeout'     => 5,
		'redirection' => 0,
		'headers'     => array( 'Host' => $site ),
	)
);

if ( is_wp_error( $reachable ) ) {
	WP_CLI::error( 'The web container is not reachable: ' . $reachable->get_error_message() );
}

debloater_e2e_say( 'home page over real HTTP: ' . wp_remote_retrieve_response_code( $reachable ) );
debloater_e2e_say( '' );

// The test install has no active theme, so it serves an empty home page — and
// an empty page is a FAIL, correctly. That is a true statement about this
// environment and a useless one about the fix, so a theme is switched on for
// the run and switched back at the end. The suite's own reinstall resets it,
// which is why this cannot be done once by hand.
$previous_theme = get_option( 'stylesheet' );
$wanted_theme   = 'twentytwentyfour';

if ( $previous_theme !== $wanted_theme && wp_get_theme( $wanted_theme )->exists() ) {
	switch_theme( $wanted_theme );
	debloater_e2e_say( 'theme switched to ' . $wanted_theme . ' for this run' );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );

if ( array() === $admins ) {
	WP_CLI::error( 'This site has no administrator to act as.' );
}

$actor = (int) $admins[0]->ID;

debloater_e2e_say( 'acting as: ' . $admins[0]->user_login . ' (id ' . $actor . ')' );

// Point the admin at the routable host, and tell the site that this is still
// itself so the credential is not withheld as off-domain.
$rewrite = static function ( string $url ) use ( $site ): string {
	return str_replace( '//' . $site, '//' . LOOPBACK_HOST, $url );
};

add_filter( 'admin_url', $rewrite );
add_filter( 'home_url', $rewrite );

add_filter(
	'http_request_args',
	static function ( array $args, string $url ) use ( $site ): array {
		if ( str_contains( $url, LOOPBACK_HOST ) ) {
			$args['headers']['Host'] = $site;
		}

		return $args;
	},
	10,
	2
);

$plugin = \Debloater\Plugin::instance();

if ( null === $plugin ) {
	WP_CLI::error( "Debloater is not active on this site." );
}

$context = $plugin->context()->withActor( 'user:' . $actor );

$result = ( new AdminProbe( new HttpClient( $context ) ) )->run( $context );

debloater_e2e_say( '' );
debloater_e2e_say( 'admin probe: ' . $result->status->value );
debloater_e2e_say( 'message:     ' . $result->message );

foreach ( $result->evidence as $key => $value ) {
	debloater_e2e_say( sprintf( '  %-20s %s', $key, (string) $value ) );
}

if ( 'PASS' !== $result->status->value ) {
	WP_CLI::error( 'The dashboard did not come back signed in.' );
}

// And now every probe, not just the one.
//
// What this deliberately does not do is run a whole `wp debloater apply`. That
// runs in its own process, and the address it would ask for comes from
// `WP_HOME` — which wp-env pins as a constant in `wp-config.php` to
// `http://localhost:8889`, an address that resolves to the CLI container
// itself. No option, filter or flag reaches a constant in another process, and
// editing the environment's `wp-config.php` would break the site for the
// browser on the host machine. That limit is the environment's, and it is
// exactly the situation D-0020 was written for.
//
// What *can* be done here is the verification stage itself, in this process,
// with every URL-producing function pointed at the address that routes. That is
// the whole of what decides VERIFIED against VERIFIED_WITH_WARNINGS: the
// aggregate over real responses from the real server.
foreach ( array( 'home_url', 'site_url', 'admin_url', 'login_url', 'rest_url' ) as $filter ) {
	add_filter( $filter, $rewrite );
}

debloater_e2e_say( '' );
debloater_e2e_say( 'verifying, every probe, over real HTTP ...' );

// A verifier built now, not the one the plugin memoised at boot: that one
// captured the site address before any of these filters existed, and its
// loopback check would ask for the unroutable one and stop there.
$live = new \Debloater\Contracts\Context(
	home_url(),
	ABSPATH,
	WP_CONTENT_DIR,
	$context->plugin_dir,
	$context->wp_version,
	$context->php_version,
	$context->plugin_version,
	'user:' . $actor,
	is_multisite()
);

$http = new HttpClient( $live );

$fresh = new \Debloater\Verify\Verifier(
	$live,
	array(
		new \Debloater\Verify\Probes\HomeProbe( $http ),
		new \Debloater\Verify\Probes\ContentPageProbe( $http ),
		new AdminProbe( $http ),
		new \Debloater\Verify\Probes\RestProbe( $http ),
		new \Debloater\Verify\Probes\LoginProbe( $http ),
		new \Debloater\Verify\Probes\RuntimeLoadedProbe( $http, $plugin->state() ),
	),
	$http
);

$verification = $fresh->verify();

debloater_e2e_say( '' );

foreach ( $verification->probes as $probe ) {
	debloater_e2e_say( sprintf( '  %-16s %-10s %s', $probe->probe, $probe->status->value, $probe->message ) );
}

debloater_e2e_say( '' );
debloater_e2e_say( 'aggregate: ' . $verification->status->value );
debloater_e2e_say(
	'a run verifying with this aggregate ends '
	. ( 'PASS' === $verification->status->value ? 'VERIFIED' : 'VERIFIED_WITH_WARNINGS' )
);

// Put the site back.
//
// Not politeness: this runs against the same database the integration suite
// uses, and the suite wraps each test in a transaction that is rolled back.
// Anything created here is created *outside* that transaction, so it survives
// the rollback and is still there for the next test — which is how a scan left
// behind by this script made `test_previewing_before_a_scan_is_refused` fail,
// and how tables left behind made a test that drops them find them still
// present. Nine failures, none of them about the code.
debloater_e2e_say( '' );
debloater_e2e_say( 'putting the site back ...' );

global $wpdb;

$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

foreach ( array( 'snapshot_items', 'snapshots', 'journal', 'runs' ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own tables in a throwaway environment.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'debloater_' . $table );
}

$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

foreach ( array( 'debloater_selection', 'debloater_state', 'debloater_settings', 'debloater_schema_version' ) as $option ) {
	delete_option( $option );
}

$runtime = WP_CONTENT_DIR . '/debloater';
$loader  = WP_CONTENT_DIR . '/mu-plugins/debloater-loader.php';

if ( file_exists( $loader ) ) {
	wp_delete_file( $loader );
}

if ( is_dir( $runtime ) ) {
	foreach ( (array) glob( $runtime . '/*' ) as $file ) {
		if ( is_string( $file ) && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}

if ( is_string( $previous_theme ) && $previous_theme !== get_option( 'stylesheet' ) ) {
	switch_theme( $previous_theme );
}

debloater_e2e_say( 'tables, options, generated files and the theme put back' );

WP_CLI::success( 'done' );
