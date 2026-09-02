<?php
/**
 * Facts about who uses the site.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;
use WPDebloat\Storage\Schema;

/**
 * Collects the `users.*` facts (BUILD-SPEC §5).
 *
 * Both numbers exist to answer one question later: does anyone actually depend
 * on the admin behaviour a tweak would change? Heartbeat at 15 seconds is
 * wasteful on a one-person blog and load-bearing on a site where four people
 * edit the same posts, and that difference is the whole reason this scanner
 * exists.
 *
 * No personal data is collected: two counts, no names, no addresses, no ids.
 * The journal is held to the same rule (BUILD-SPEC §13 rule 12).
 */
final class UserScanner extends AbstractScanner {

	/**
	 * How far back "recent" reaches, in days.
	 */
	private const RECENT_DAYS = 7;

	/**
	 * Upper bound on the administrator count.
	 *
	 * A site with more than this many administrators has a different problem,
	 * and counting them exactly would mean an unbounded query on a table that
	 * can be very large.
	 */
	private const ADMIN_LIMIT = 200;

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'users';
	}

	/**
	 * Collect user facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		return array(
			'users.admin_count'       => $this->administratorCount(),
			'users.recent_editors_7d' => $this->recentEditorCount(),
		);
	}

	/**
	 * How many users hold the administrator role.
	 *
	 * @return int
	 */
	private function administratorCount(): int {
		$query = new \WP_User_Query(
			array(
				'role'        => 'administrator',
				'fields'      => 'ID',
				'number'      => self::ADMIN_LIMIT,
				'count_total' => false,
			)
		);

		return count( $query->get_results() );
	}

	/**
	 * How many distinct authors changed content in the last week.
	 *
	 * Counted with one indexed query against post_modified rather than through
	 * WP_Query, which would hydrate every post object to arrive at a number.
	 *
	 * @return int
	 */
	private function recentEditorCount(): int {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::RECENT_DAYS * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A scan reports what is true now; a cached answer would defeat the purpose.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts}
				WHERE post_modified_gmt >= %s
				AND post_status IN ('publish', 'draft', 'pending', 'private', 'future')
				AND post_type NOT IN ('revision', 'nav_menu_item')",
				$since
			)
		);

		return (int) $count;
	}
}
