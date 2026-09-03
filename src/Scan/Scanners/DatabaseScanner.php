<?php
/**
 * Facts about what is in the database.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;

/**
 * Collects the counting half of the `db.*` facts (BUILD-SPEC §5).
 *
 * Every query here is bounded and indexed. That is not a performance nicety: a
 * plugin whose job is to reduce load has no business locking up the database of
 * the site it is auditing, and on a site with a million posts a careless
 * `COUNT(*)` with a `LIKE '%…'` is exactly how that happens.
 *
 * Concretely:
 *
 * - post counts filter on `post_type` and `post_status`, which the `type_status_date`
 *   index covers;
 * - comment counts filter on `comment_approved`, which `comment_approved_date_gmt`
 *   covers;
 * - transient counts use a **prefix** `LIKE`, which uses the `option_name`
 *   index; a leading wildcard would not;
 * - orphan-meta counts are `LEFT JOIN … IS NULL` on indexed foreign keys.
 *
 * The query count is fixed and asserted by a test, so a future addition here
 * cannot quietly turn one scan into thirty.
 */
final class DatabaseScanner extends AbstractScanner {

	/**
	 * How many queries this scanner is allowed to run.
	 *
	 * Pinned by DatabaseScannerTest. Raising it is a decision, not an accident.
	 */
	public const QUERY_BUDGET = 12;

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'db';
	}

	/**
	 * Collect database facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		global $wpdb;

		return array(
			'db.size_bytes'            => $this->sizeBytes(),
			'db.revisions.count'       => $this->countPosts( 'revision' ),
			'db.autodrafts.count'      => $this->countPostsByStatus( 'auto-draft' ),
			'db.trash.count'           => $this->countPostsByStatus( 'trash' ),
			'db.spam_comments.count'   => $this->countComments( 'spam' ),
			'db.transients.count'      => $this->countTransients( false ),
			'db.transients.expired'    => $this->countTransients( true ),
			'db.orphan_postmeta.count' => $this->countOrphanMeta( $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID' ),
			'db.orphan_termmeta.count' => $this->countOrphanMeta( $wpdb->termmeta, 'term_id', $wpdb->terms, 'term_id' ),
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- Counting orphaned user meta means joining against the users table; nothing else distinguishes an orphan from a live row.
			'db.orphan_usermeta.count' => $this->countOrphanMeta( $wpdb->usermeta, 'user_id', $wpdb->users, 'ID' ),
		);
	}

	/**
	 * Total size of the site's tables, in bytes.
	 *
	 * Read from information_schema, which is a metadata lookup rather than a
	 * scan of the data. The figure is approximate — that is how MySQL reports
	 * it — and is used for context, never for a claim.
	 *
	 * @return int
	 */
	private function sizeBytes(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metadata lookup; a scan reports what is true now.
		$bytes = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES
				WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		return (int) $bytes;
	}

	/**
	 * Count posts of a given type.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	private function countPosts( string $post_type ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed count; no WordPress API returns this without hydrating every row.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type )
		);
	}

	/**
	 * Count posts in a given status, across all types.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function countPostsByStatus( string $status ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", $status )
		);
	}

	/**
	 * Count comments in a given approval state.
	 *
	 * @param string $approved Value of comment_approved, e.g. "spam" or "trash".
	 * @return int
	 */
	private function countComments( string $approved ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", $approved )
		);
	}

	/**
	 * Count transients, optionally only the expired ones.
	 *
	 * Expiry lives in a companion `_transient_timeout_*` option, so an expired
	 * transient is one whose timeout is in the past. The prefix match keeps the
	 * `option_name` index usable.
	 *
	 * Transients held in a persistent object cache are invisible here, which is
	 * correct: they are not rows, and cleaning rows would not touch them.
	 *
	 * @param bool $expired_only Whether to count only expired transients.
	 * @return int
	 */
	private function countTransients( bool $expired_only ): int {
		global $wpdb;

		if ( ! $expired_only ) {
			// Every transient with an expiry has a companion _transient_timeout_
			// row, and that row also matches the _transient_ prefix. Excluding it
			// is the difference between counting transients and counting rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix LIKE on an indexed column.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options}
					WHERE option_name LIKE %s AND option_name NOT LIKE %s",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_' ) . '%'
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
	}

	/**
	 * Count meta rows whose owning object no longer exists.
	 *
	 * "Orphan" is defined narrowly and identically for every meta table: a row
	 * whose foreign key has no matching row in the parent table. Phase 10 needs
	 * this definition to be exact before it deletes anything, so it is stated
	 * here rather than implied.
	 *
	 * @param string $meta_table   Meta table name.
	 * @param string $foreign_key  Column holding the parent id.
	 * @param string $parent_table Parent table name.
	 * @param string $parent_key   Parent primary key column.
	 * @return int
	 */
	private function countOrphanMeta( string $meta_table, string $foreign_key, string $parent_table, string $parent_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every interpolated name comes from $wpdb, never from input.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names cannot be parameterised; every interpolated name here comes from $wpdb or from a constant in this class, never from input. Values are parameterised.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$meta_table}` AS m
			LEFT JOIN `{$parent_table}` AS p ON m.`{$foreign_key}` = p.`{$parent_key}`
			WHERE p.`{$parent_key}` IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
