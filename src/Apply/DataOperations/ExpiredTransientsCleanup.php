<?php
/**
 * Deletes transients whose expiry has passed.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply\DataOperations;

use Debloater\Contracts\Context;
use Debloater\Contracts\DataOperationInterface;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\TweakParams;

/**
 * The MVP's one data operation (BUILD-SPEC §15).
 *
 * Chosen deliberately as the first, because the rows it removes are the least
 * valuable in the database: a transient whose expiry has passed has, by its own
 * declaration, stopped being useful. WordPress deletes each one itself the next
 * time something asks for it — the ones left behind are exactly the ones nothing
 * will ask for.
 *
 * It still takes a full Level B recovery point first. The point of proving the
 * recovery path is to prove it where a mistake costs nothing, not to skip it
 * because the rows look unimportant.
 *
 * A transient is two rows: the value, and a `_transient_timeout_` row holding
 * its expiry. Both are collected and both are restored, with the original
 * timeout intact — restoring a transient with a fresh expiry would resurrect a
 * cached value that should have died, which is worse than having deleted it.
 *
 * Transients held in a persistent object cache are invisible to this and
 * correctly so: they are not rows, and deleting rows would not touch them.
 */
final class ExpiredTransientsCleanup implements DataOperationInterface {

	/**
	 * The tweak this operation implements.
	 */
	public const TWEAK_ID = 'db.clean_expired_transients';

	/**
	 * Rows handled per batch when no size is given.
	 */
	public const DEFAULT_BATCH_SIZE = 500;

	/**
	 * Names collect() backed up, and the only ones execute() may delete.
	 *
	 * Reset at the start of every collect(), so a reused instance cannot delete
	 * on the strength of a recovery point taken for a previous run.
	 *
	 * @var array<string,true>
	 */
	private array $collected = array();

	/**
	 * The tweak id this operation implements.
	 *
	 * @return string
	 */
	public function tweakId(): string {
		return self::TWEAK_ID;
	}

	/**
	 * Whether this deletes anything a user would miss.
	 *
	 * No. An expired transient is a cached value past its own stated lifetime;
	 * nothing reads it, and WordPress would delete it itself given the chance.
	 * The Level B snapshot is taken regardless (§15).
	 *
	 * @return bool
	 */
	public function isDestructive(): bool {
		return false;
	}

	/**
	 * How many transients would be removed.
	 *
	 * Counts transients, not rows: a user shown "4 832" should be able to match
	 * it against the number the scan reported.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int
	 */
	public function countAffected( Context $context, TweakParams $params ): int {
		global $wpdb;

		unset( $context, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting what is there now; a cached answer would defeat the purpose.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
	}

	/**
	 * Yield every row this operation will delete.
	 *
	 * Both rows of each transient, with the timeout kept exactly as stored.
	 * Called before anything is deleted; what it yields becomes the recovery
	 * point.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return iterable<int,SnapshotItem>
	 */
	public function collect( Context $context, TweakParams $params ): iterable {
		global $wpdb;

		unset( $context );

		$this->collected = array();

		foreach ( $this->expiredNames( $this->batchSize( $params ) ) as $name ) {
			$this->collected[ $name ] = true;

			$value_option   = '_transient_' . $name;
			$timeout_option = '_transient_timeout_' . $name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the exact rows about to be deleted.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
					$value_option,
					$timeout_option
				),
				ARRAY_A
			);

			$payload = array( 'transient' => $name );

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$payload[ (string) $row['option_name'] ] = array(
					'option_value' => $row['option_value'],
					'autoload'     => $row['autoload'],
				);
			}

			// A transient with no value row is a stranded timeout. It is still
			// worth removing, and worth recording that that is what it was.
			yield new SnapshotItem( 'transient', $name, $payload );
		}
	}

	/**
	 * Delete the expired transients.
	 *
	 * Deleted through WordPress's own API so that a persistent object cache, an
	 * external store, or anything hooked to `delete_transient` sees the removal
	 * — a direct DELETE would leave a cached copy alive and the site would keep
	 * serving a value that no longer exists in the database.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int Number of transients removed.
	 */
	public function execute( Context $context, TweakParams $params ): int {
		unset( $context );

		unset( $params );

		$removed = 0;

		// Only what collect() actually backed up. This is the collection
		// ceiling, in the form this operation needs: the sibling operations
		// bound execute() by the highest primary key collect() saw, and a
		// transient has no id worth ordering by, so the bound is the set of
		// names instead.
		//
		// The hole it closes is real rather than theoretical. A transient
		// expires by the clock, so on any site with traffic more of them become
		// expired in the seconds between the recovery point being written and
		// the deletion running — and the previous version re-queried the
		// database in a loop and deleted every one it found, including the ones
		// that were never collected. Those could not have been restored,
		// because they were never in the snapshot.
		//
		// Losing an expired transient is close to harmless, since it is a cache
		// entry the site had already stopped honouring. That is not the point.
		// Invariant 8 says a recovery point exists before a destructive
		// operation runs, and an operation that deletes rows outside its own
		// recovery point does not satisfy it — whatever the rows happen to be
		// worth. Anything that expires after this runs is left for the next
		// run, where it will be collected first like everything else.
		foreach ( array_keys( $this->collected ) as $name ) {
			delete_transient( (string) $name );

			++$removed;
		}

		return $removed;
	}

	/**
	 * Put the transients back, with their original expiry.
	 *
	 * Written as options rather than through set_transient(), because
	 * set_transient() takes a *duration* and would give a restored transient a
	 * fresh lifetime. These were expired when they were removed; they must be
	 * expired again afterwards, or the restore would resurrect stale cache
	 * entries as live ones.
	 *
	 * @param Context                 $context Site context.
	 * @param array<int,SnapshotItem> $items   Items to restore.
	 * @return int Number of transients restored.
	 */
	public function restore( Context $context, array $items ): int {
		global $wpdb;

		unset( $context );

		$restored = 0;

		foreach ( $items as $item ) {
			if ( 'transient' !== $item->object_type ) {
				continue;
			}

			$wrote = false;

			foreach ( $item->payload as $option_name => $row ) {
				if ( 'transient' === $option_name || ! is_array( $row ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option would refuse a name that still exists, and update_option cannot set autoload on insert; the row must go back exactly as it was.
				$wpdb->replace(
					$wpdb->options,
					array(
						'option_name'  => (string) $option_name,
						'option_value' => $row['option_value'],
						'autoload'     => $row['autoload'],
					),
					array( '%s', '%s', '%s' )
				);

				$wrote = true;
			}

			if ( $wrote ) {
				++$restored;
			}
		}

		// The options cache still holds the deletions.
		wp_cache_flush();

		return $restored;
	}

	/**
	 * The names of expired transients, up to a limit.
	 *
	 * Names, not rows: `_transient_timeout_foo` becomes `foo`, which is what
	 * `delete_transient()` and the rest of the API expect.
	 *
	 * @param int $limit Maximum names to return.
	 * @return array<int,string>
	 */
	private function expiredNames( int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix LIKE on the indexed option_name column.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE option_name LIKE %s AND option_value < %d
				ORDER BY option_id ASC
				LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time(),
				$limit
			)
		);

		$names  = array();
		$prefix = strlen( '_transient_timeout_' );

		foreach ( is_array( $rows ) ? $rows : array() as $option_name ) {
			$names[] = substr( (string) $option_name, $prefix );
		}

		return $names;
	}

	/**
	 * The batch size to use.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return int
	 */
	private function batchSize( TweakParams $params ): int {
		$size = $params->get( 'batch_size', self::DEFAULT_BATCH_SIZE );

		return is_numeric( $size ) ? max( 1, min( 2000, (int) $size ) ) : self::DEFAULT_BATCH_SIZE;
	}
}
