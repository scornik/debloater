<?php
/**
 * Comments something already judged to be spam.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply\DataOperations;

use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\SnapshotItem;
use WPDebloat\Contracts\TweakParams;

/**
 * Permanently deletes comments already marked as spam.
 *
 * Only `spam` — never `hold`. A comment awaiting moderation is one nobody has
 * judged yet, and deleting those would be deleting the moderation queue.
 *
 * Comments carry their own metadata, and anti-spam plugins put a good deal of it
 * there. All of it is captured, so a restored comment comes back with whatever
 * Akismet knew about it rather than as a bare row.
 */
final class SpamCommentsCleanup extends AbstractDataOperation {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading and restoring exact rows, under their original ids; there is no WordPress API for the second.

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.delete_spam_comments';

	/**
	 * Days a comment must have been marked as spam.
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
	 * How many comments would be removed.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int
	 */
	public function countAffected( Context $context, TweakParams $params ): int {
		global $wpdb;

		if ( ! $this->isSupported( $context ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s AND comment_date_gmt < %s",
				'spam',
				$this->olderThan( $params, self::DEFAULT_DAYS )
			)
		);
	}

	/**
	 * Every comment this operation will delete, with its metadata.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return iterable<int,SnapshotItem>
	 */
	public function collect( Context $context, TweakParams $params ): iterable {
		global $wpdb;

		if ( ! $this->isSupported( $context ) ) {
			return;
		}

		$cutoff = $this->olderThan( $params, self::DEFAULT_DAYS );
		$batch  = $this->batchSize( $params );
		$after  = 0;

		while ( true ) {
			$comments = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->comments}
					WHERE comment_approved = %s AND comment_date_gmt < %s AND comment_ID > %d
					ORDER BY comment_ID ASC LIMIT %d",
					'spam',
					$cutoff,
					$after,
					$batch
				),
				ARRAY_A
			);

			if ( ! is_array( $comments ) || array() === $comments ) {
				return;
			}

			foreach ( $comments as $comment ) {
				$id    = (int) $comment['comment_ID'];
				$after = max( $after, $id );

				$this->raiseCeiling( 'comment', $id );

				$meta = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id = %d", $id ),
					ARRAY_A
				);

				yield new SnapshotItem(
					'comment',
					(string) $id,
					array(
						'comment'    => $comment,
						'meta'       => is_array( $meta ) ? $meta : array(),
						'deleted_by' => self::TWEAK_ID,
					)
				);
			}
		}
	}

	/**
	 * Delete the spam.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int Number of comments removed.
	 */
	public function execute( Context $context, TweakParams $params ): int {
		global $wpdb;

		if ( ! $this->isSupported( $context ) ) {
			return 0;
		}

		$cutoff  = $this->olderThan( $params, self::DEFAULT_DAYS );
		$batch   = $this->batchSize( $params );
		$ceiling = $this->ceilingFor( 'comment' );
		$removed = 0;

		if ( 0 === $ceiling ) {
			return 0;
		}

		do {
			// Bounded by what collect() actually backed up: a comment marked as
			// spam since then is not in this run's recovery point.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments}
					WHERE comment_approved = %s AND comment_date_gmt < %s AND comment_ID <= %d
					ORDER BY comment_ID ASC LIMIT %d",
					'spam',
					$cutoff,
					$ceiling,
					$batch
				)
			);

			$ids = is_array( $ids ) ? $ids : array();

			foreach ( $ids as $id ) {
				// Through WordPress, so comment counts are recalculated and
				// anything hooked to the deletion hears about it.
				if ( wp_delete_comment( (int) $id, true ) ) {
					++$removed;
				}
			}
		} while ( array() !== $ids );

		return $removed;
	}

	/**
	 * Put the comments back, with their ids, dates and metadata.
	 *
	 * @param Context                 $context Site context.
	 * @param array<int,SnapshotItem> $items   Items to restore.
	 * @return int Number of comments restored.
	 */
	public function restore( Context $context, array $items ): int {
		global $wpdb;

		unset( $context );

		$restored = 0;
		$posts    = array();

		foreach ( $items as $item ) {
			if ( 'comment' !== $item->object_type ) {
				continue;
			}

			$comment = $item->payload['comment'] ?? null;

			if ( ! is_array( $comment ) || ! isset( $comment['comment_ID'] ) ) {
				continue;
			}

			$wpdb->replace( $wpdb->comments, $comment );

			foreach ( is_array( $item->payload['meta'] ?? null ) ? $item->payload['meta'] : array() as $meta ) {
				if ( is_array( $meta ) ) {
					$wpdb->replace( $wpdb->commentmeta, $meta );
				}
			}

			if ( isset( $comment['comment_post_ID'] ) ) {
				$posts[ (int) $comment['comment_post_ID'] ] = true;
			}

			++$restored;
		}

		if ( $restored > 0 ) {
			// Spam does not count towards a post's comment count, but the row
			// being back is not the same as WordPress knowing it is back.
			foreach ( array_keys( $posts ) as $post_id ) {
				wp_update_comment_count_now( $post_id );
			}

			$this->forgetCaches();
		}

		return $restored;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
