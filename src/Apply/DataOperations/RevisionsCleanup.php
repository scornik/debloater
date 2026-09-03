<?php
/**
 * Older versions of a post, once there are enough newer ones.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply\DataOperations;

use Debloater\Contracts\TweakParams;

/**
 * Keeps the most recent revisions of each post and deletes the rest.
 *
 * The parameter is per post, not per site, and that is the whole design. A site
 * with four thousand revisions does not have a revisions problem; it has one
 * post that has been edited four thousand times, and the useful question is how
 * many of *that post's* versions are worth keeping.
 *
 * Deleted through `wp_delete_post_revision()`, which is what the revisions
 * screen itself calls.
 */
final class RevisionsCleanup extends AbstractPostsOperation {

	/**
	 * What these rows are called in a recovery point.
	 *
	 * A revision is a post row, but the contract distinguishes the two, and it
	 * is right to: restoring "a post" and restoring "an earlier version of a
	 * post" are different promises to the person reading the recovery point.
	 */
	protected const OBJECT_TYPE = 'revision';

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.clean_revisions';

	/**
	 * Revisions kept per post when the tweak does not say otherwise.
	 */
	public const DEFAULT_KEEP = 5;

	/**
	 * The tweak id this operation implements.
	 *
	 * @return string
	 */
	public function tweakId(): string {
		return self::TWEAK_ID;
	}

	/**
	 * Every revision beyond the newest `keep_per_post` of its own parent.
	 *
	 * The correlated subquery is the price of "per post": for each revision, how
	 * many newer siblings does it have? Keeping the newest N means deleting the
	 * ones with N or more.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return string
	 */
	protected function condition( TweakParams $params ): string {
		global $wpdb;

		$keep = $this->intParam( $params, 'keep_per_post', self::DEFAULT_KEEP, 0, 50 );

		return $wpdb->prepare(
			"post_type = %s AND (
				SELECT COUNT(*) FROM {$wpdb->posts} newer
				WHERE newer.post_type = %s
				  AND newer.post_parent = {$wpdb->posts}.post_parent
				  AND ( newer.post_date_gmt > {$wpdb->posts}.post_date_gmt
				        OR ( newer.post_date_gmt = {$wpdb->posts}.post_date_gmt AND newer.ID > {$wpdb->posts}.ID ) )
			) >= %d",
			'revision',
			'revision',
			$keep
		);
	}

	/**
	 * Delete one revision, through the function the editor uses.
	 *
	 * @param int $id Revision id.
	 * @return bool
	 */
	protected function deleteOne( int $id ): bool {
		return (bool) wp_delete_post_revision( $id );
	}
}
