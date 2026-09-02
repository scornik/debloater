<?php
/**
 * Constants PHPStan needs to reason about the plugin.
 *
 * WordPress defines these at runtime, so static analysis has to be told about
 * them. This file is never loaded by the plugin.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

define( 'WPDEBLOAT_DIR', __DIR__ . '/../' );
define( 'WPDEBLOAT_URL', 'https://example.test/wp-content/plugins/wp-debloat/' );
define( 'WPDEBLOAT_DISABLE', false );
define( 'WPDEBLOAT_LOADER_MODE', 'mu-plugin' );
define( 'WP_CLI', false );

// Defined by wp-config.php on every install.
define( 'DB_NAME', 'wordpress' );
define( 'DB_PASSWORD', 'password' );

// Defined by WordPress when the cookie constants are set up, which happens on
// every request but not before wp-settings.php runs.
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_0123456789abcdef' );
