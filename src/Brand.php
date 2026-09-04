<?php
/**
 * Single source of product naming.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater;

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
	public const NAME = 'Debloater';

	/**
	 * What the product is, in one line.
	 *
	 * Separate from the name because the two are read in different places. A
	 * menu item, an error message and a sentence want "Debloater"; the plugin
	 * header and the readme title want the whole thing, because that is the
	 * only text a wordpress.org search result shows.
	 *
	 * Not translated, for the same reason the name is not: it is the product's
	 * listing on an English-language directory, and a translated listing title
	 * would not match the plugin somebody searched for.
	 *
	 * "Site" rather than "WordPress". The word was worth having for search and
	 * turned out not to be available: Plugin Check refuses the term "wordpress"
	 * anywhere in a plugin name, tagline included, and it is the tool
	 * wordpress.org runs at review. Reference material that says the term is
	 * permitted in a display name and forbidden only in a slug is wrong in
	 * practice (docs/DECISIONS.md D-0052).
	 */
	public const TAGLINE = 'Scan, Fix & Undo Site Bloat';

	/**
	 * Plugin slug, used for the directory, text domain and asset handles.
	 */
	public const SLUG = 'debloater';

	/**
	 * Text domain for translation.
	 */
	public const TEXT_DOMAIN = 'debloater';

	/**
	 * Prefix for options, tables, hooks and functions.
	 */
	public const PREFIX = 'debloater';

	/**
	 * Prefix for constants.
	 */
	public const CONSTANT_PREFIX = 'DEBLOATER';

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'debloater/v1';

	/**
	 * WP-CLI top-level command.
	 */
	public const CLI_COMMAND = 'debloater';

	/**
	 * Capability required to manage the plugin.
	 */
	public const CAPABILITY = 'debloater_manage';

	/**
	 * Admin menu slug.
	 */
	public const MENU_SLUG = 'debloater';

	/**
	 * The single option all plugin state lives in.
	 */
	public const STATE_OPTION = 'debloater_state';

	/**
	 * Transient guarding concurrent apply and rollback runs.
	 */
	public const LOCK_TRANSIENT = 'debloater_lock';

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

	/**
	 * Name and tagline, the way wordpress.org reads it.
	 *
	 * Composed rather than written out, so the plugin header and the readme
	 * title cannot drift apart — which is exactly the pair a release check has
	 * to compare and a person has to remember.
	 *
	 * @return string
	 */
	public static function fullName(): string {
		return self::NAME . ' – ' . self::TAGLINE;
	}
}
