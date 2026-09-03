<?php
/**
 * What happens when somebody deletes Debloater.
 *
 * BUILD-SPEC §13 rule 10: uninstalling **always** removes the generated runtime
 * file and the mu-plugin loader, and drops the tables and options **only** if
 * the site opted in to cleanup.
 *
 * The asymmetry is deliberate and it is the whole design.
 *
 * The runtime file and the loader are code this plugin wrote and nothing else
 * reads. Leaving them behind after the plugin is gone would mean a site running
 * hooks from a plugin that is not installed — unattributable, unexplainable, and
 * impossible for the next person to debug. They always go.
 *
 * The tables hold recovery points. Those describe what a site used to look like,
 * and somebody who deletes a plugin at eleven at night to get their shop working
 * may want them at nine the next morning. Dropping them by default would destroy
 * the one thing that makes the changes reversible, at the exact moment somebody
 * is most likely to need it. So they stay unless the site said otherwise, and
 * saying otherwise is a deliberate act recorded in the plugin's own settings.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

// Only WordPress may run this, and only during an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// -- WP_Filesystem is the wrong tool here and using it would be less safe, not more.
//
// It cannot do an atomic replace: there is no move() that guarantees
// rename(2) semantics, and a non-atomic write to a file loaded on every
// request is exactly how a site ends up serving half a runtime. It also
// asks for FTP credentials when it cannot write directly, which during an
// apply means a modal in the middle of a change that is already underway.
//
// Everything written here is inside wp-content/debloater or mu-plugins,
// from paths this plugin builds itself (BUILD-SPEC §13 rule 6), and
// tests/Integration/SecurityRulesTest.php asserts that boundary.
//
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own tables is what an opt-in uninstall is.

/**
 * Remove the generated runtime and the loader, whatever else happens.
 *
 * Written out here rather than delegated, because delegating would mean booting
 * the plugin during its own uninstall — and the one thing that must work here is
 * the part that runs when everything else is already half gone.
 *
 * @return void
 */
function debloater_uninstall_runtime(): void {
	$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

	$paths = array(
		$content . '/debloater/runtime.php',
		$content . '/debloater/runtime.lock',
		$content . '/mu-plugins/debloater-loader.php',
	);

	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	// The backups directory and its guards, then the directory itself. Only
	// files this plugin is responsible for: the loop removes what it recognises
	// and leaves anything it does not, and rmdir() refuses a directory that
	// still has something in it.
	$backups = $content . '/debloater/backups';

	if ( is_dir( $backups ) ) {
		foreach ( array( 'index.php', '.htaccess' ) as $guard ) {
			if ( is_file( $backups . '/' . $guard ) ) {
				wp_delete_file( $backups . '/' . $guard );
			}
		}

		$spilled = glob( $backups . '/*.ndjson.gz' );

		foreach ( is_array( $spilled ) ? $spilled : array() as $file ) {
			wp_delete_file( $file );
		}

		@rmdir( $backups ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A directory somebody else has put something in is theirs; rmdir refuses it and that refusal is the correct outcome, not an error to report during an uninstall.
	}

	foreach ( array( 'index.php', '.htaccess' ) as $guard ) {
		if ( is_file( $content . '/debloater/' . $guard ) ) {
			wp_delete_file( $content . '/debloater/' . $guard );
		}
	}

	@rmdir( $content . '/debloater' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.
}

/**
 * Drop the tables and options, but only if the site asked for that.
 *
 * @return void
 */
function debloater_uninstall_data(): void {
	global $wpdb;

	$state = get_option( \Debloater\Brand::STATE_OPTION );

	if ( ! is_array( $state ) || empty( $state['uninstall_cleanup'] ) ) {
		// The default. Recovery points outlive the plugin, because the moment
		// somebody deletes it is the moment they are most likely to need them.
		return;
	}

	foreach ( \Debloater\Storage\Schema::tables() as $key ) {
		$table = \Debloater\Storage\Schema::table( $key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dropping this plugin's own tables, and only on the opt-in path above.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	delete_option( \Debloater\Brand::STATE_OPTION );
	delete_transient( \Debloater\Brand::LOCK_TRANSIENT );

	// The wordpress.org release-date cache from the opt-in plugin check. Named
	// individually rather than by a LIKE over the options table, because a
	// pattern delete during an uninstall is how somebody else's data gets
	// removed by accident.
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	foreach ( array_keys( get_plugins() ) as $plugin_file ) {
		$slug = \Debloater\Scan\Scanners\PluginScanner::slugOf( (string) $plugin_file );

		delete_transient( 'debloater_wporg_' . md5( $slug ) );
	}
}

debloater_uninstall_runtime();
debloater_uninstall_data();
