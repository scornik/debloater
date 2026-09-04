<?php
/**
 * Plugin Name:       Debloater – Scan, Fix & Undo Site Bloat
 * Plugin URI:        https://github.com/scornik/debloater
 * Description:       Audits a WordPress site against the facts, then applies only the changes you approve — each with its own risk level, a recovery point taken first, and an automatic rollback if verification fails.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Hakeemify
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       debloater
 * Domain Path:       /languages
 *
 * @package Debloater
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const DEBLOATER_VERSION = '0.1.0';
const DEBLOATER_FILE    = __FILE__;

define( 'DEBLOATER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEBLOATER_URL', plugin_dir_url( __FILE__ ) );

/**
 * The plugin needs PHP 8.1. Refuse to boot rather than fatal halfway through.
 *
 * WordPress honours the "Requires PHP" header on new installs, but a site that
 * downgrades PHP under an installed plugin gets no such protection, and a fatal
 * error in a plugin that loads this early would take the whole site with it.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

$debloater_autoload = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $debloater_autoload ) ) {
	require_once $debloater_autoload;
} else {
	spl_autoload_register(
		/**
		 * Minimal PSR-4 autoloader for the Debloater namespace.
		 *
		 * The plugin has no runtime Composer dependencies (BUILD-SPEC §3), so a
		 * distribution build does not need a vendor directory at all. This keeps
		 * the plugin working when it is installed from a zip that has none.
		 *
		 * @param string $class_name Fully-qualified class name.
		 * @return void
		 */
		static function ( $class_name ) {
			if ( ! str_starts_with( $class_name, 'Debloater\\' ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( 'Debloater\\' ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

Debloater\Plugin::boot( __FILE__, DEBLOATER_VERSION );
