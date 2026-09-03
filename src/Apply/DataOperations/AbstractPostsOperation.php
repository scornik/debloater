<?php
/**
 * Deleting posts, and putting them back exactly.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply\DataOperations;

use Debloater\Contracts\Context;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\TweakParams;

/**
 * Three of this phase's operations delete rows from `wp_posts` — revisions,
 * abandoned auto-drafts, and content already in the trash. What differs between
 * them is one WHERE clause; everything else is identical, and identical in ways
 * that are easy to get subtly wrong.
 *
 * A post is not one row. It is a row in `posts`, its rows in `postmeta`, and its
 * rows in `term_relationships`. A restore that puts back only the first produces
 * a post that exists, has lost its categories, and has lost whatever a plugin
 * stored against it — which looks like a successful restore right up until
 * somebody goes looking.
 */
abstract class AbstractPostsOperation extends AbstractDataOperation {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- These operations exist to read and write exact rows; a cached answer would defeat the purpose, and there is no WordPress API for restoring a row under its original id. The one interpolated fragment is condition(), which every subclass builds with $wpdb->prepare; the sniff cannot see across the method call.

	/**
	 * The object type recorded in the recovery point.
	 */
	protected const OBJECT_TYPE = 'post';

	/**
	 * The SQL condition selecting the posts this operation removes.
	 *
	 * Returned already prepared. Every subclass builds it with `$wpdb->prepare`,
	 * because the values in it come from tweak parameters.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return string
	 */
	abstract protected function condition( TweakParams $params ): string;

	/**
	 * How many posts this operation would remove.
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condition() returns SQL already prepared by the subclass.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . $this->condition( $params ) );
	}

	/**
	 * Everything this operation will delete, in full.
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

		$condition = $this->condition( $params );
		$batch     = $this->batchSize( $params );
		$after     = 0;

		while ( true ) {
			// Paged by id rather than by offset: nothing is being deleted while
			// we collect, but an offset over a set another request is changing
			// skips rows, and this loop must not depend on that not happening.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condition() is prepared by the subclass; the rest is a literal with bound values.
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->posts} WHERE {$condition} AND ID > %d ORDER BY ID ASC LIMIT %d",
					$after,
					$batch
				),
				ARRAY_A
			);

			if ( ! is_array( $posts ) || array() === $posts ) {
				return;
			}

			foreach ( $posts as $post ) {
				$id    = (int) $post['ID'];
				$after = max( $after, $id );

				$this->raiseCeiling( static::OBJECT_TYPE, $id );

				yield new SnapshotItem(
					static::OBJECT_TYPE,
					(string) $id,
					array(
						'post'          => $post,
						'meta'          => $this->metaFor( $id ),
						'terms'         => $this->termsFor( $id ),
						'deleted_by'    => $this->tweakId(),
						'deleted_title' => (string) $post['post_title'],
					)
				);
			}
		}
	}

	/**
	 * Delete the posts.
	 *
	 * Through WordPress's own functions, so that everything hooked to
	 * `delete_post` — search indexes, caches, other plugins' bookkeeping — hears
	 * about it. A direct DELETE would leave those out of step with the database
	 * in ways nobody would notice for weeks.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int Number of posts removed.
	 */
	public function execute( Context $context, TweakParams $params ): int {
		global $wpdb;

		if ( ! $this->isSupported( $context ) ) {
			return 0;
		}

		$condition = $this->condition( $params );
		$batch     = $this->batchSize( $params );
		$ceiling   = $this->ceilingFor( static::OBJECT_TYPE );
		$removed   = 0;

		if ( 0 === $ceiling ) {
			// collect() found nothing, so there is nothing in this run's
			// recovery point and therefore nothing this run may delete.
			return 0;
		}

		do {
			// Re-read each batch rather than paging: the rows are disappearing
			// as we go, and an offset over a shrinking set skips rows. Bounded
			// by the ceiling, so nothing that arrived after the recovery point
			// was written can be deleted by it.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condition() is prepared by the subclass.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE {$condition} AND ID <= %d ORDER BY ID ASC LIMIT %d",
					$ceiling,
					$batch
				)
			);

			$ids = is_array( $ids ) ? $ids : array();

			foreach ( $ids as $id ) {
				if ( $this->deleteOne( (int) $id ) ) {
					++$removed;
				}
			}
		} while ( array() !== $ids );

		return $removed;
	}

	/**
	 * Put the posts back, with their ids, dates, metadata and terms.
	 *
	 * @param Context                 $context Site context.
	 * @param array<int,SnapshotItem> $items   Items to restore.
	 * @return int Number of posts restored.
	 */
	public function restore( Context $context, array $items ): int {
		global $wpdb;

		unset( $context );

		$restored = 0;

		foreach ( $items as $item ) {
			if ( static::OBJECT_TYPE !== $item->object_type ) {
				continue;
			}

			$post = $item->payload['post'] ?? null;

			if ( ! is_array( $post ) || ! isset( $post['ID'] ) ) {
				continue;
			}

			// replace() rather than insert(): a restore that has already run
			// once, or a row somehow still present, must not turn into a
			// duplicate-key failure halfway through putting a site back.
			$wpdb->replace( $wpdb->posts, $post );

			foreach ( is_array( $item->payload['meta'] ?? null ) ? $item->payload['meta'] : array() as $meta ) {
				if ( is_array( $meta ) ) {
					$wpdb->replace( $wpdb->postmeta, $meta );
				}
			}

			foreach ( is_array( $item->payload['terms'] ?? null ) ? $item->payload['terms'] : array() as $term ) {
				if ( is_array( $term ) ) {
					$wpdb->replace( $wpdb->term_relationships, $term );
				}
			}

			++$restored;
		}

		if ( $restored > 0 ) {
			$this->forgetCaches();
		}

		return $restored;
	}

	/**
	 * Delete one post, through WordPress.
	 *
	 * @param int $id Post id.
	 * @return bool
	 */
	protected function deleteOne( int $id ): bool {
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Every meta row belonging to a post.
	 *
	 * @param int $id Post id.
	 * @return array<int,array<string,mixed>>
	 */
	protected function metaFor( int $id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d", $id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every term relationship belonging to a post.
	 *
	 * @param int $id Post id.
	 * @return array<int,array<string,mixed>>
	 */
	protected function termsFor( int $id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id = %d", $id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
