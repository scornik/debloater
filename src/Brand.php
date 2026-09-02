<?php
/**
 * Single source of product naming.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat;

/**
 * Product naming (CONVENTIONS.md, BUILD-SPEC §3).
 *
 * Nothing user-visible is hardcoded in feature code. Every screen, CLI command
 * and REST response takes its product name from here, so the public name chosen
 * in Phase 18 can be applied by changing this class and the build config alone.
 *
 * The prefixes are constants rather than strings sprinkled through the codebase
 * for the same reason: they appear in option names, table names, hook names and
 * REST routes, and a rename that misses one of those is a data-loss bug.
 */
final class Brand {

	/**
	 * Product name shown to users. Not translated: it is a proper noun.
	 */
	public const NAME = 'WP Debloat';

	/**
	 * Plugin slug, used for the directory, text domain and asset handles.
	 */
	public const SLUG = 'wp-debloat';

	/**
	 * Text domain for translation.
	 */
	public const TEXT_DOMAIN = 'wp-debloat';

	/**
	 * Prefix for options, tables, hooks and functions.
	 */
	public const PREFIX = 'wpdebloat';

	/**
	 * Prefix for constants.
	 */
	public const CONSTANT_PREFIX = 'WPDEBLOAT';

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'wpdebloat/v1';

	/**
	 * WP-CLI top-level command.
	 */
	public const CLI_COMMAND = 'debloat';

	/**
	 * Capability required to manage the plugin.
	 */
	public const CAPABILITY = 'wpdebloat_manage';

	/**
	 * Admin menu slug.
	 */
	public const MENU_SLUG = 'wp-debloat';

	/**
	 * The single option all plugin state lives in.
	 */
	public const STATE_OPTION = 'wpdebloat_state';

	/**
	 * Transient guarding concurrent apply and rollback runs.
	 */
	public const LOCK_TRANSIENT = 'wpdebloat_lock';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * A prefixed option name.
	 *
	 * @param string $name Unprefixed name.
	 * @return string
	 */
	public static function option( string $name ): string {
		return self::PREFIX . '_' . $name;
	}

	/**
	 * A prefixed hook name.
	 *
	 * @param string $name Unprefixed name.
	 * @return string
	 */
	public static function hook( string $name ): string {
		return self::PREFIX . '_' . $name;
	}

	/**
	 * A prefixed table name for the given wpdb prefix.
	 *
	 * @param string $wpdb_prefix The site's table prefix, e.g. "wp_".
	 * @param string $name        Unprefixed table name, e.g. "runs".
	 * @return string
	 */
	public static function table( string $wpdb_prefix, string $name ): string {
		return $wpdb_prefix . self::PREFIX . '_' . $name;
	}
}
