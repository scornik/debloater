<?php
/**
 * Database tables.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Storage;

use Debloater\Brand;

/**
 * Creates and migrates Debloater's tables (BUILD-SPEC §8).
 *
 * Tables are created through `dbDelta`, which is idempotent: running it against
 * an existing table produces the ALTERs needed to reach the declared shape and
 * nothing else. The schema version in `debloater_state` records what the site
 * has been migrated to, so `ensure()` is cheap on every request after the first.
 *
 * Only the tables a phase actually needs exist. `debloater_snapshots`,
 * `debloater_snapshot_items`, `debloater_journal` and `debloater_measurements`
 * arrive with the code that writes them, rather than sitting empty in the
 * meantime.
 */
final class Schema {

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A table name cannot be a placeholder, and every one interpolated in this class is built from Schema's own constants plus $wpdb->prefix. Values are always parameterised; the sniff cannot see the difference.

	/**
	 * Schema version. Bump when a table definition changes.
	 */
	public const VERSION = 2;

	/**
	 * The runs table, without the site prefix.
	 */
	public const RUNS = 'runs';

	/**
	 * Recovery points.
	 */
	public const SNAPSHOTS = 'snapshots';

	/**
	 * The exact rows a data operation will delete.
	 */
	public const SNAPSHOT_ITEMS = 'snapshot_items';

	/**
	 * Every state change, per tweak.
	 */
	public const JOURNAL = 'journal';

	/**
	 * Plugin state, which records the migrated schema version.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Constructor.
	 *
	 * @param State $state Plugin state.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * The full name of one of our tables.
	 *
	 * @param string $table Unprefixed table name.
	 * @return string
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return Brand::table( $wpdb->prefix, $table );
	}

	/**
	 * Create or migrate the tables if the site is not already up to date.
	 *
	 * @return bool Whether a migration ran.
	 */
	public function ensure(): bool {
		if ( self::VERSION === $this->state->get( 'db_version', 0 ) && $this->tablesExist() ) {
			return false;
		}

		$this->install();

		$this->state->set( array( 'db_version' => self::VERSION ) );

		return true;
	}

	/**
	 * Create or migrate every table.
	 *
	 * @return void
	 */
	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		foreach ( $this->definitions( $charset ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Drop every table.
	 *
	 * Only ever called from uninstall, and only when the user opted in to
	 * cleanup (BUILD-SPEC §13 rule 10). Removing a plugin is not by itself
	 * consent to delete the record of what it changed.
	 *
	 * @return void
	 */
	public function drop(): void {
		global $wpdb;

		foreach ( array_reverse( self::tables() ) as $table ) {
			$name = self::table( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names cannot be parameterised, and this one is built from our own constant plus $wpdb->prefix.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}
	}

	/**
	 * Every table this version defines.
	 *
	 * @return array<int,string>
	 */
	public static function tables(): array {
		return array( self::RUNS, self::SNAPSHOTS, self::SNAPSHOT_ITEMS, self::JOURNAL );
	}

	/**
	 * Whether every table exists.
	 *
	 * @return bool
	 */
	public function tablesExist(): bool {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$name = self::table( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking for a table's existence has no cacheable equivalent, and the result is used to decide whether to migrate.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );

			if ( $found !== $name ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The CREATE TABLE statements, in dbDelta's expected form.
	 *
	 * dbDelta is particular: two spaces after PRIMARY KEY, one field per line,
	 * KEY rather than INDEX, and no backticks around index names.
	 *
	 * @param string $charset Charset and collation clause.
	 * @return array<string,string>
	 */
	private function definitions( string $charset ): array {
		$runs      = self::table( self::RUNS );
		$snapshots = self::table( self::SNAPSHOTS );
		$items     = self::table( self::SNAPSHOT_ITEMS );
		$journal   = self::table( self::JOURNAL );

		return array(
			self::RUNS           => "CREATE TABLE {$runs} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	type VARCHAR(16) NOT NULL,
	status VARCHAR(32) NOT NULL,
	actor VARCHAR(64) NOT NULL,
	started_at DATETIME NOT NULL,
	finished_at DATETIME NULL,
	plugin_version VARCHAR(20) NOT NULL,
	registry_hash CHAR(64) NOT NULL,
	payload LONGTEXT NULL,
	error TEXT NULL,
	PRIMARY KEY  (id),
	KEY type_status (type, status),
	KEY started_at (started_at)
) {$charset};",

			self::SNAPSHOTS      => "CREATE TABLE {$snapshots} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	run_id BIGINT UNSIGNED NOT NULL,
	level VARCHAR(1) NOT NULL,
	created_at DATETIME NOT NULL,
	site_hash CHAR(64) NOT NULL,
	plugin_version VARCHAR(20) NOT NULL,
	config LONGTEXT NULL,
	items_count INT UNSIGNED NOT NULL DEFAULT 0,
	bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
	storage VARCHAR(8) NOT NULL DEFAULT 'db',
	file_path VARCHAR(255) NULL,
	checksum CHAR(64) NOT NULL,
	status VARCHAR(16) NOT NULL,
	PRIMARY KEY  (id),
	KEY run_id (run_id),
	KEY status_created (status, created_at)
) {$charset};",

			self::SNAPSHOT_ITEMS => "CREATE TABLE {$items} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	snapshot_id BIGINT UNSIGNED NOT NULL,
	object_type VARCHAR(32) NOT NULL,
	object_key VARCHAR(191) NOT NULL,
	payload LONGTEXT NOT NULL,
	restored TINYINT(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY snapshot_id (snapshot_id),
	KEY snapshot_type (snapshot_id, object_type)
) {$charset};",

			self::JOURNAL        => "CREATE TABLE {$journal} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	run_id BIGINT UNSIGNED NOT NULL,
	tweak_id VARCHAR(100) NOT NULL,
	action VARCHAR(8) NOT NULL,
	from_state VARCHAR(32) NOT NULL,
	to_state VARCHAR(32) NOT NULL,
	params TEXT NULL,
	at DATETIME NOT NULL,
	actor VARCHAR(64) NOT NULL,
	PRIMARY KEY  (id),
	KEY run_id (run_id),
	KEY tweak_id (tweak_id)
) {$charset};",
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
