<?php
/**
 * Constants PHPStan needs to reason about the plugin.
 *
 * WordPress defines these at runtime, so static analysis has to be told about
 * them. This file is never loaded by the plugin.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

define( 'DEBLOATER_DIR', __DIR__ . '/../' );
define( 'DEBLOATER_URL', 'https://example.test/wp-content/plugins/debloater/' );
define( 'DEBLOATER_DISABLE', false );
define( 'DEBLOATER_LOADER_MODE', 'mu-plugin' );
define( 'WP_CLI', false );

// Defined by wp-config.php on every install.
define( 'DB_NAME', 'wordpress' );
define( 'DB_PASSWORD', 'password' );

// Defined by WordPress when the cookie constants are set up, which happens on
// every request but not before wp-settings.php runs.
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_0123456789abcdef' );
define( 'AUTH_COOKIE', 'wordpress_0123456789abcdef' );
define( 'SECURE_AUTH_COOKIE', 'wordpress_sec_0123456789abcdef' );
