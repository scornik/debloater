<?php
/**
 * Drafts nobody ever wrote.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply\DataOperations;

use WPDebloat\Contracts\TweakParams;

/**
 * Removes auto-drafts that were opened and abandoned.
 *
 * WordPress creates one of these every time somebody clicks "Add New" and walks
 * away, and deletes them itself after seven days — but only when its scheduled
 * task runs, which on a site with no visitors is "never". They accumulate in
 * their thousands on sites that are edited often.
 *
 * The default here is thirty days rather than seven, deliberately: an auto-draft
 * from last week might still be somebody's unfinished work, and the cost of
 * waiting three more weeks is a few rows.
 */
final class AutoDraftsCleanup extends AbstractPostsOperation {

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.clean_auto_drafts';

	/**
	 * Days an auto-draft must have sat untouched.
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * The tweak id this operation implements.
	 *
	 * @return string
	 */
	public function tweakId(): string {
		return self::TWEAK_ID;
	}

	/**
	 * Auto-drafts older than the cut-off.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return string
	 */
	protected function condition( TweakParams $params ): string {
		global $wpdb;

		return $wpdb->prepare(
			'post_status = %s AND post_modified_gmt < %s',
			'auto-draft',
			$this->olderThan( $params, self::DEFAULT_DAYS )
		);
	}
}
