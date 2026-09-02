<?php
/**
 * Bootstrap for the integration test suite.
 *
 * Integration tests run inside wp-env against a real WordPress install, because
 * the things they check — which hooks are registered, how many queries a
 * front-end request makes, whether a runtime survives a round trip — cannot be
 * answered by a mock.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

$wpdebloat_plugin_dir = dirname( __DIR__ );
$wpdebloat_tests_dir  = getenv( 'WP_TESTS_DIR' );

if ( false === $wpdebloat_tests_dir || '' === $wpdebloat_tests_dir ) {
	$wpdebloat_tests_dir = '/wordpress-phpunit';
}

$wpdebloat_tests_dir = rtrim( $wpdebloat_tests_dir, '/\\' );

if ( ! is_readable( $wpdebloat_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"The WordPress test suite was not found at {$wpdebloat_tests_dir}.\n"
		. "Integration tests run inside wp-env:\n\n"
		. "  npm run env:start\n"
		. "  npm run test:integration\n\n"
	);
	exit( 1 );
}

$wpdebloat_composer_autoload = $wpdebloat_plugin_dir . '/vendor/autoload.php';

if ( is_readable( $wpdebloat_composer_autoload ) ) {
	require_once $wpdebloat_composer_autoload;
}

require_once $wpdebloat_tests_dir . '/includes/functions.php';

/**
 * Load the plugin the way WordPress does, at muplugins_loaded.
 *
 * The plugin is loaded rather than merely autoloaded so activation hooks,
 * REST registration and the loader all behave as they do in production.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wpdebloat_plugin_dir ): void {
		require_once $wpdebloat_plugin_dir . '/wp-debloat.php';
	}
);

require_once $wpdebloat_tests_dir . '/includes/bootstrap.php';

/**
 * Absolute path to the plugin, for tests that read registry or handler files.
 */
define( 'WPDEBLOAT_TESTS_ROOT', $wpdebloat_plugin_dir );

require_once __DIR__ . '/Integration/IntegrationTestCase.php';
