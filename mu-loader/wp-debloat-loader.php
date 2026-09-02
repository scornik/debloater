<?php
/**
 * Plugin Name: WP Debloat Loader
 * Description: Loads the WP Debloat generated runtime early, before plugins. Installed automatically; removed when WP Debloat is uninstalled.
 * Version: 1.0.0
 * Author: Hakeemify
 * License: GPL-2.0-or-later
 *
 * This file is copied into wp-content/mu-plugins by WP Debloat on activation.
 * It is intentionally tiny and has no dependencies: it runs on every request to
 * the site, including requests where WP Debloat itself is deactivated or broken.
 *
 * Nothing here reads an option, touches the database, or loads the plugin's
 * autoloader. If the generated runtime is absent, the whole file costs one
 * file_exists() call and returns (BUILD-SPEC §10).
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpdebloat_load_runtime' ) ) {

	/**
	 * Load the generated runtime, if there is one and it is intact.
	 *
	 * The hash check is what makes an edited or partially written runtime.php
	 * fail closed. A file that does not match the hash recorded when it was
	 * generated is not loaded at all: an unexpected runtime is more dangerous
	 * than no runtime, and the status endpoint reports the mismatch so the
	 * dashboard can say what happened.
	 *
	 * @return void
	 */
	function wpdebloat_load_runtime() {
		$directory = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( __DIR__ ) ) . '/wpdebloat';
		$runtime   = $directory . '/runtime.php';

		// The common case for a site with nothing selected: one stat, then out.
		if ( ! is_readable( $runtime ) ) {
			return;
		}

		$lock = $directory . '/runtime.lock';

		if ( ! is_readable( $lock ) ) {
			return;
		}

		$recorded = wpdebloat_runtime_recorded_hash( $lock );

		if ( '' === $recorded ) {
			return;
		}

		$source = file_get_contents( $runtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read before WordPress HTTP APIs exist.

		if ( false === $source || ! hash_equals( $recorded, hash( 'sha256', $source ) ) ) {
			return;
		}

		require_once $runtime;
	}
}

if ( ! function_exists( 'wpdebloat_runtime_recorded_hash' ) ) {

	/**
	 * Read the runtime hash out of runtime.lock.
	 *
	 * @param string $lock Absolute path of the lock file.
	 * @return string The recorded hash, or '' when it cannot be read.
	 */
	function wpdebloat_runtime_recorded_hash( $lock ) {
		$raw = file_get_contents( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read before WordPress HTTP APIs exist.

		if ( false === $raw ) {
			return '';
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['runtime_hash'] ) || ! is_string( $decoded['runtime_hash'] ) ) {
			return '';
		}

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $decoded['runtime_hash'] ) ) {
			return '';
		}

		return $decoded['runtime_hash'];
	}
}

if ( ! defined( 'WPDEBLOAT_LOADER_MODE' ) ) {
	define( 'WPDEBLOAT_LOADER_MODE', 'mu-plugin' );
}

wpdebloat_load_runtime();
