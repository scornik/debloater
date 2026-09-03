<?php
/**
 * Options WordPress reads on every single request.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply\DataOperations;

use Debloater\Contracts\Context;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\TweakParams;

/**
 * Stops a small, known-safe set of options being loaded eagerly.
 *
 * WordPress reads every option flagged `autoload` into memory before it does
 * anything else, on every request, including requests that will never look at
 * them. A site with a few megabytes of autoloaded data pays for all of it on
 * every page view.
 *
 * Nothing is deleted here and no value changes — only the flag that decides
 * whether an option is read eagerly. It is therefore not destructive, and it is
 * reversible by putting the flag back.
 *
 * **The allowlist is the whole safety model.** Deciding automatically that an
 * option is not needed early is exactly the kind of judgement that breaks sites
 * in ways nobody can trace: a plugin that reads an option during
 * `plugins_loaded` will still find it, just after an extra query, and one that
 * reads it before the database is ready will not find it at all. So this
 * operation touches only option names matching prefixes that are known to be
 * safe, and the `prefixes` parameter can **narrow** that list, never widen it.
 * A parameter that could add a prefix would be a parameter that could turn this
 * into "switch off autoload for everything", which is a well-known way to make
 * a site slower and occasionally broken.
 */
final class AutoloadReview extends AbstractDataOperation {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading and writing the autoload flag on exact rows; there is no options API for changing it without rewriting the value. The only interpolation is a list of LIKE placeholders built from a count of allowlisted prefixes, with every value bound.

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.autoload_off';

	/**
	 * The only option prefixes this operation will ever touch.
	 *
	 * Each is here because the option it names is read on demand rather than
	 * during boot: transient timeouts are consulted only when the transient is
	 * asked for; a WooCommerce session belongs to one visitor; Elementor's
	 * remote-info cache is refreshed on a schedule; Rank Math and LiteSpeed keep
	 * admin-screen state that no front-end request reads.
	 *
	 * Adding to this list is a deliberate act with a name attached, not a
	 * parameter somebody can pass.
	 */
	public const ALLOWED_PREFIXES = array(
		'_transient_timeout_',
		'_site_transient_timeout_',
		'_wc_session_',
		'elementor_remote_info_',
		'rank_math_',
		'litespeed.admin_display.',
	);

	/**
	 * Smallest option worth changing, in bytes.
	 */
	public const DEFAULT_MINIMUM_BYTES = 4096;

	/**
	 * The tweak id this operation implements.
	 *
	 * @return string
	 */
	public function tweakId(): string {
		return self::TWEAK_ID;
	}

	/**
	 * Nothing is deleted here.
	 *
	 * @return bool
	 */
	public function isDestructive(): bool {
		return false;
	}

	/**
	 * How many options would stop being loaded eagerly.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int
	 */
	public function countAffected( Context $context, TweakParams $params ): int {
		unset( $context );

		return count( $this->candidates( $params ) );
	}

	/**
	 * The options this operation will change, with their current flag.
	 *
	 * The value is captured as well as the flag. It is not needed to undo the
	 * change, but a recovery point that holds only half a row invites a future
	 * version to restore half a row.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return iterable<int,SnapshotItem>
	 */
	public function collect( Context $context, TweakParams $params ): iterable {
		unset( $context );

		foreach ( $this->candidates( $params ) as $option ) {
			yield new SnapshotItem(
				'option',
				(string) $option['option_name'],
				array(
					'option_name' => (string) $option['option_name'],
					'autoload'    => (string) $option['autoload'],
					'bytes'       => (int) $option['bytes'],
					'changed_by'  => self::TWEAK_ID,
				)
			);
		}
	}

	/**
	 * Set `autoload` to no on the allowed options.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int Number of options changed.
	 */
	public function execute( Context $context, TweakParams $params ): int {
		global $wpdb;

		unset( $context );

		$changed = 0;

		foreach ( $this->candidates( $params ) as $option ) {
			$name = (string) $option['option_name'];

			// WordPress 6.6 renamed the values this column holds — 'yes' and
			// 'no' became 'on' and 'off' — and added a function that knows which
			// spelling this install uses. Writing the string ourselves would
			// work today and quietly stop working, or start meaning something
			// slightly different, on a version we have not seen.
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				if ( wp_set_option_autoload( $name, false ) ) {
					++$changed;
				}

				continue;
			}

			$updated = $wpdb->update(
				$wpdb->options,
				array( 'autoload' => 'no' ),
				array( 'option_name' => $name ),
				array( '%s' ),
				array( '%s' )
			);

			if ( false !== $updated && $updated > 0 ) {
				++$changed;
			}
		}

		if ( $changed > 0 ) {
			$this->forgetCaches();
		}

		return $changed;
	}

	/**
	 * Put the flags back exactly as they were.
	 *
	 * @param Context                 $context Site context.
	 * @param array<int,SnapshotItem> $items   Items to restore.
	 * @return int Number of options restored.
	 */
	public function restore( Context $context, array $items ): int {
		global $wpdb;

		unset( $context );

		$restored = 0;

		foreach ( $items as $item ) {
			if ( 'option' !== $item->object_type ) {
				continue;
			}

			$name     = is_string( $item->payload['option_name'] ?? null ) ? $item->payload['option_name'] : '';
			$autoload = is_string( $item->payload['autoload'] ?? null ) ? $item->payload['autoload'] : '';

			if ( '' === $name || '' === $autoload ) {
				continue;
			}

			// Restored as the exact string that was there before, whichever
			// spelling this WordPress uses, because a recovery point that
			// normalises what it puts back is not putting back what it took.
			$wpdb->update(
				$wpdb->options,
				array( 'autoload' => $autoload ),
				array( 'option_name' => $name ),
				array( '%s' ),
				array( '%s' )
			);

			++$restored;
		}

		if ( $restored > 0 ) {
			$this->forgetCaches();
		}

		return $restored;
	}

	/**
	 * The biggest autoloaded options on the site, whatever their name.
	 *
	 * Used by the analyzer to report what is actually costing memory. Reporting
	 * is not changing: this list is wider than the allowlist on purpose, because
	 * a user is owed the truth about what is loading on every request even when
	 * Debloater will not touch it.
	 *
	 * @param int $limit How many to return.
	 * @return array<int,array{option_name:string,bytes:int}>
	 */
	public static function topAutoloaded( int $limit = 10 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH( option_value ) AS bytes
				FROM {$wpdb->options}
				WHERE autoload IN ( 'yes', 'on', 'auto', 'auto-on' )
				ORDER BY bytes DESC
				LIMIT %d",
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		$top = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$top[] = array(
				'option_name' => (string) $row['option_name'],
				'bytes'       => (int) $row['bytes'],
			);
		}

		return $top;
	}

	/**
	 * The options eligible to change: allowlisted, autoloaded, and big enough.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return array<int,array{option_name:string,autoload:string,bytes:int}>
	 */
	private function candidates( TweakParams $params ): array {
		global $wpdb;

		$prefixes = $this->prefixes( $params );

		if ( array() === $prefixes ) {
			return array();
		}

		$minimum = $this->intParam( $params, 'minimum_bytes', self::DEFAULT_MINIMUM_BYTES, 256, 1048576 );

		$clauses = array();
		$values  = array();

		foreach ( $prefixes as $prefix ) {
			$clauses[] = 'option_name LIKE %s';
			$values[]  = $wpdb->esc_like( $prefix ) . '%';
		}

		$values[] = $minimum;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The clause list is built from a count of allowlisted prefixes; every value is bound below.
		$sql = $wpdb->prepare(
			"SELECT option_name, autoload, LENGTH( option_value ) AS bytes
			FROM {$wpdb->options}
			WHERE autoload IN ( 'yes', 'on', 'auto', 'auto-on' )
			  AND ( " . implode( ' OR ', $clauses ) . ' )
			  AND LENGTH( option_value ) >= %d
			ORDER BY bytes DESC',
			$values
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$candidates = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$candidates[] = array(
				'option_name' => (string) $row['option_name'],
				'autoload'    => (string) $row['autoload'],
				'bytes'       => (int) $row['bytes'],
			);
		}

		return $candidates;
	}

	/**
	 * The prefixes to act on: the allowlist, optionally narrowed.
	 *
	 * Intersected with `ALLOWED_PREFIXES`, so a parameter can only ever remove
	 * prefixes from consideration. Passing an unknown prefix does nothing rather
	 * than adding it, and passing nothing usable falls back to the full list.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return array<int,string>
	 */
	private function prefixes( TweakParams $params ): array {
		$requested = $params->get( 'prefixes', self::ALLOWED_PREFIXES );

		if ( ! is_array( $requested ) ) {
			return self::ALLOWED_PREFIXES;
		}

		$narrowed = array_values(
			array_intersect( self::ALLOWED_PREFIXES, array_filter( $requested, 'is_string' ) )
		);

		return array() === $narrowed ? self::ALLOWED_PREFIXES : $narrowed;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
