<?php
/**
 * Bootstrap for the unit test suite.
 *
 * Unit tests run without WordPress: no globals, no database, no network. If a
 * test needs WordPress it belongs in tests/Integration, which boots wp-env.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

$wpdebloat_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $wpdebloat_autoload ) ) {
	fwrite(
		STDERR,
		"Composer dependencies are not installed. Run composer install before the test suite.\n"
	);
	exit( 1 );
}

require_once $wpdebloat_autoload;

/**
 * Absolute path to the repository root, for tests that read registry files.
 */
define( 'WPDEBLOAT_TESTS_ROOT', dirname( __DIR__ ) );
