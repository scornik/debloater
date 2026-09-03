<?php
/**
 * Facts about what loads on every request.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- The interpolated name is a $wpdb property, never input, and the condition beside it is SQL the subclass already prepared; nesting prepare() around it would process those placeholders a second time.

use Debloater\Contracts\Context;

/**
 * Collects the `db.autoload.*` facts (BUILD-SPEC §5).
 *
 * Autoloaded options are read in a single query at the start of every request,
 * so their combined size is paid for on every page view whether anything uses
 * them or not. That makes this the one database measurement that is directly
 * about weight rather than about tidiness.
 *
 * The `db` namespace is shared with DatabaseScanner. They write disjoint keys
 * and ScanRunner refuses any overlap, so the split simply keeps each file about
 * one thing.
 */
final class AutoloadScanner extends AbstractScanner {

	/**
	 * How many of the largest options to list.
	 */
	private const TOP_N = 20;

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'db';
	}

	/**
	 * Collect autoload facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		return array(
			'db.autoload.bytes' => $this->totalBytes(),
			'db.autoload.top'   => $this->largest(),
		);
	}

	/**
	 * Total size of all autoloaded option values, in bytes.
	 *
	 * @return int
	 */
	private function totalBytes(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A scan reports what is true now; the autoload index makes this cheap. The only interpolation is a constant condition built in this class.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names cannot be parameterised; every interpolated name here comes from $wpdb or from a constant in this class, never from input. Values are parameterised.
		return (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE " . $this->autoloadCondition()
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The largest autoloaded options, largest first.
	 *
	 * Bounded by LIMIT rather than sorted in PHP, so the row set never leaves
	 * the database in full.
	 *
	 * @return array<int,array{name:string,bytes:int}>
	 */
	private function largest(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The condition is built from constants in this class; the limit is parameterised.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names cannot be parameterised; every interpolated name here comes from $wpdb or from a constant in this class, never from input. Values are parameterised.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options}
				WHERE " . $this->autoloadCondition() . '
				ORDER BY bytes DESC
				LIMIT %d',
				self::TOP_N
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching


		$top = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$top[] = array(
				'name'  => (string) $row['option_name'],
				'bytes' => (int) $row['bytes'],
			);
		}

		return $top;
	}

	/**
	 * The SQL condition matching autoloaded options.
	 *
	 * WordPress 6.6 replaced the yes/no autoload column with a wider vocabulary
	 * (`on`, `auto`, `auto-on`, `off`, `auto-off`, `yes`, `no`). Matching the
	 * loading values explicitly keeps the figure correct on both sides of that
	 * change, where matching `= 'yes'` would silently under-report on 6.6+ and
	 * make the site look lighter than it is.
	 *
	 * @return string
	 */
	private function autoloadCondition(): string {
		return "autoload IN ('yes', 'on', 'auto', 'auto-on')";
	}
}
