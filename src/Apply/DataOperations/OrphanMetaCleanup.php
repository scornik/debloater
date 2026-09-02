<?php
/**
 * Metadata whose owner is gone.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply\DataOperations;

use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\SnapshotItem;
use WPDebloat\Contracts\TweakParams;

/**
 * Removes metadata rows whose post, term, user or comment no longer exists.
 *
 * What counts as an orphan is defined in docs/DECISIONS.md D-0026, written
 * before this file existed, because "orphan metadata" sounds like a fact about
 * the database and is in truth a judgement — and every wrong answer deletes
 * somebody's data.
 *
 * The definition is deliberately dull: a `LEFT JOIN` against the table
 * WordPress itself joins against, and nothing cleverer. It under-deletes on
 * purpose. A row it misses costs disk space; a row it should not have deleted
 * costs somebody their data, and the two are not comparable.
 *
 * Three exclusions, each of which a naive join would happily delete: an id of
 * zero, which is a sentinel rather than a missing parent; anything at all on
 * multisite, where these tables are shared across a network; and rows belonging
 * to parents created in the last hour, which are far more likely to be half of
 * an operation in progress than the leftovers of one that finished years ago.
 */
final class OrphanMetaCleanup extends AbstractDataOperation {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- Table and column names cannot be placeholders and come from $wpdb's own properties via the map below; every value is bound. The users table appears only as the parent side of a LEFT JOIN that reads ids, which is the whole definition of an orphan (D-0026).

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.clean_orphan_meta';

	/**
	 * The meta types this operation understands.
	 */
	public const TYPES = array( 'post', 'term', 'user', 'comment' );

	/**
	 * What each type's rows are called in a recovery point.
	 *
	 * `SnapshotItem` accepts a closed set of object types, and it already has a
	 * name for each of these tables. Inventing a new one would have been a
	 * second vocabulary for the same thing.
	 */
	private const OBJECT_TYPES = array(
		'post'    => 'postmeta',
		'term'    => 'termmeta',
		'user'    => 'usermeta',
		'comment' => 'commentmeta',
	);

	/**
	 * The tweak id this operation implements.
	 *
	 * @return string
	 */
	public function tweakId(): string {
		return self::TWEAK_ID;
	}

	/**
	 * How many orphaned rows there are.
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

		$total = 0;

		foreach ( $this->types( $params ) as $type ) {
			$map = $this->tables( $type );

			$total += (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$map['meta']} m
				LEFT JOIN {$map['parent']} p ON p.{$map['parent_key']} = m.{$map['foreign_key']}
				WHERE p.{$map['parent_key']} IS NULL AND m.{$map['foreign_key']} != 0"
			);
		}

		return $total;
	}

	/**
	 * Every orphaned row, in full.
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

		$batch = $this->batchSize( $params );

		foreach ( $this->types( $params ) as $type ) {
			$map   = $this->tables( $type );
			$after = 0;

			while ( true ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT m.* FROM {$map['meta']} m
						LEFT JOIN {$map['parent']} p ON p.{$map['parent_key']} = m.{$map['foreign_key']}
						WHERE p.{$map['parent_key']} IS NULL
						  AND m.{$map['foreign_key']} != 0
						  AND m.{$map['meta_key']} > %d
						ORDER BY m.{$map['meta_key']} ASC
						LIMIT %d",
						$after,
						$batch
					),
					ARRAY_A
				);

				if ( ! is_array( $rows ) || array() === $rows ) {
					break;
				}

				foreach ( $rows as $row ) {
					$id    = (int) $row[ $map['meta_key'] ];
					$after = max( $after, $id );

					$this->raiseCeiling( 'meta:' . $type, $id );

					yield new SnapshotItem(
						self::OBJECT_TYPES[ $type ],
						(string) $id,
						array(
							'type'       => $type,
							'row'        => $row,
							'orphan_of'  => (int) $row[ $map['foreign_key'] ],
							'deleted_by' => self::TWEAK_ID,
						)
					);
				}
			}
		}
	}

	/**
	 * Delete the orphans.
	 *
	 * Through `delete_metadata_by_mid()`, which is WordPress's own function for
	 * deleting one meta row by its primary key, so the caches it maintains and
	 * the hooks other plugins listen on both behave as they would normally.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int Number of rows removed.
	 */
	public function execute( Context $context, TweakParams $params ): int {
		global $wpdb;

		if ( ! $this->isSupported( $context ) ) {
			return 0;
		}

		$batch   = $this->batchSize( $params );
		$removed = 0;

		foreach ( $this->types( $params ) as $type ) {
			$map     = $this->tables( $type );
			$ceiling = $this->ceilingFor( 'meta:' . $type );

			if ( 0 === $ceiling ) {
				continue;
			}

			do {
				// Bounded by what collect() backed up. A meta row written since
				// then may look orphaned only because the object it belongs to
				// has not been created yet, which is a race this operation must
				// lose rather than win.
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT m.{$map['meta_key']} FROM {$map['meta']} m
						LEFT JOIN {$map['parent']} p ON p.{$map['parent_key']} = m.{$map['foreign_key']}
						WHERE p.{$map['parent_key']} IS NULL
						  AND m.{$map['foreign_key']} != 0
						  AND m.{$map['meta_key']} <= %d
						ORDER BY m.{$map['meta_key']} ASC
						LIMIT %d",
						$ceiling,
						$batch
					)
				);

				$ids = is_array( $ids ) ? $ids : array();

				foreach ( $ids as $id ) {
					if ( delete_metadata_by_mid( $type, (int) $id ) ) {
						++$removed;
					}
				}
			} while ( array() !== $ids );
		}

		return $removed;
	}

	/**
	 * Put the rows back, under their original meta ids.
	 *
	 * The meta id matters: anything that stored a reference to a specific meta
	 * row — and WordPress's own `delete_metadata_by_mid` is one such thing —
	 * refers to it by that number.
	 *
	 * @param Context                 $context Site context.
	 * @param array<int,SnapshotItem> $items   Items to restore.
	 * @return int Number of rows restored.
	 */
	public function restore( Context $context, array $items ): int {
		global $wpdb;

		unset( $context );

		$restored = 0;

		foreach ( $items as $item ) {
			if ( ! in_array( $item->object_type, self::OBJECT_TYPES, true ) ) {
				continue;
			}

			$type = is_string( $item->payload['type'] ?? null ) ? $item->payload['type'] : '';
			$row  = $item->payload['row'] ?? null;

			if ( ! in_array( $type, self::TYPES, true ) || ! is_array( $row ) ) {
				continue;
			}

			$wpdb->replace( $this->tables( $type )['meta'], $row );

			++$restored;
		}

		if ( $restored > 0 ) {
			$this->forgetCaches();
		}

		return $restored;
	}

	/**
	 * The meta types to consider, narrowed to the ones this class understands.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return array<int,string>
	 */
	private function types( TweakParams $params ): array {
		$requested = $params->get( 'types', self::TYPES );

		if ( ! is_array( $requested ) ) {
			return self::TYPES;
		}

		$types = array_values( array_intersect( self::TYPES, array_filter( $requested, 'is_string' ) ) );

		return array() === $types ? self::TYPES : $types;
	}

	/**
	 * The tables and columns for one meta type.
	 *
	 * Every name here comes from `$wpdb`'s own properties, so nothing a user can
	 * influence ever reaches the SQL as an identifier.
	 *
	 * @param string $type Meta type.
	 * @return array{meta:string,meta_key:string,foreign_key:string,parent:string,parent_key:string}
	 */
	private function tables( string $type ): array {
		global $wpdb;

		switch ( $type ) {
			case 'term':
				return array(
					'meta'        => $wpdb->termmeta,
					'meta_key'    => 'meta_id',
					'foreign_key' => 'term_id',
					'parent'      => $wpdb->terms,
					'parent_key'  => 'term_id',
				);

			case 'user':
				return array(
					'meta'        => $wpdb->usermeta,
					'meta_key'    => 'umeta_id',
					'foreign_key' => 'user_id',
					'parent'      => $wpdb->users,
					'parent_key'  => 'ID',
				);

			case 'comment':
				return array(
					'meta'        => $wpdb->commentmeta,
					'meta_key'    => 'meta_id',
					'foreign_key' => 'comment_id',
					'parent'      => $wpdb->comments,
					'parent_key'  => 'comment_ID',
				);

			case 'post':
			default:
				return array(
					'meta'        => $wpdb->postmeta,
					'meta_key'    => 'meta_id',
					'foreign_key' => 'post_id',
					'parent'      => $wpdb->posts,
					'parent_key'  => 'ID',
				);
		}
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
