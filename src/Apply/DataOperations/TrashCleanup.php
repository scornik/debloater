<?php
/**
 * Emptying the trash, which is what the trash is for.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply\DataOperations;

use Debloater\Contracts\TweakParams;

/**
 * Permanently deletes content that is already in the trash.
 *
 * The gentlest destructive operation in the set, because the user has already
 * said this content should go — twice, if you count the confirmation. WordPress
 * empties the trash itself after thirty days, when `wp_scheduled_delete` runs,
 * which on a quiet site it may not.
 *
 * Only items that have been in the trash for a while are touched. Something
 * trashed this morning is very often something about to be untrashed.
 */
final class TrashCleanup extends AbstractPostsOperation {

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.empty_trash';

	/**
	 * Days content must have been in the trash.
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
	 * Trashed content older than the cut-off.
	 *
	 * `post_modified_gmt` is when it was trashed: WordPress updates it as part
	 * of moving the post to the trash.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return string
	 */
	protected function condition( TweakParams $params ): string {
		global $wpdb;

		return $wpdb->prepare(
			'post_status = %s AND post_modified_gmt < %s',
			'trash',
			$this->olderThan( $params, self::DEFAULT_DAYS )
		);
	}
}
