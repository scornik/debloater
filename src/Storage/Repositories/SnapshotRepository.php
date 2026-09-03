<?php
/**
 * Reads and writes recovery points.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Storage\Repositories;

use RuntimeException;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Json;
use Debloater\Contracts\Snapshot;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Contracts\SnapshotStatus;
use Debloater\Storage\Schema;

/**
 * Persistence for snapshots and their items (BUILD-SPEC §8).
 *
 * This is the layer the whole safety story rests on, so it is deliberately
 * unclever. Items are written in batches, read back in order, and marked
 * restored one at a time. There is no caching, no lazy loading, and no attempt
 * to be quick: a restore that is fast and occasionally wrong is worth nothing
 * next to one that is slow and always right.
 *
 * A snapshot whose items cannot be read is reported as corrupt rather than
 * partially restored. Half a restore is worse than none, because the site is
 * then in a state neither the user nor the plugin has a name for.
 */
final class SnapshotRepository {

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- A table name cannot be a placeholder, and every one interpolated in this class is built from Schema's own constants plus $wpdb->prefix. Values are always parameterised; an IN list built from a count is placeholders all the way down, which the sniff cannot see.

	/**
	 * How many item rows to insert per query.
	 */
	private const INSERT_BATCH = 100;

	/**
	 * Insert a snapshot and return it with its id.
	 *
	 * @param Snapshot $snapshot Snapshot to insert.
	 * @return Snapshot
	 * @throws RuntimeException When the insert fails.
	 */
	public function insert( Snapshot $snapshot ): Snapshot {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$written = $wpdb->insert(
			Schema::table( Schema::SNAPSHOTS ),
			$this->toRow( $snapshot ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $written ) {
			throw new RuntimeException( 'Could not record the recovery point: ' . $wpdb->last_error );
		}

		return $snapshot->withId( (int) $wpdb->insert_id );
	}

	/**
	 * Update an existing snapshot.
	 *
	 * @param Snapshot $snapshot Snapshot to update; must have an id.
	 * @return Snapshot
	 * @throws RuntimeException When the snapshot has no id or the update fails.
	 */
	public function update( Snapshot $snapshot ): Snapshot {
		global $wpdb;

		if ( null === $snapshot->id ) {
			throw new RuntimeException( 'Cannot update a recovery point that has not been saved.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$updated = $wpdb->update(
			Schema::table( Schema::SNAPSHOTS ),
			$this->toRow( $snapshot ),
			array( 'id' => $snapshot->id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Could not update the recovery point: ' . $wpdb->last_error );
		}

		return $snapshot;
	}

	/**
	 * Find a snapshot by id.
	 *
	 * @param int $id Snapshot id.
	 * @return Snapshot|null
	 */
	public function find( int $id ): ?Snapshot {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $this->fromRow( $row ) : null;
	}

	/**
	 * Snapshots belonging to a run, oldest first.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,Snapshot>
	 */
	public function forRun( int $run_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d ORDER BY id ASC", $run_id ),
			ARRAY_A
		);

		return $this->hydrate( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The most recent restorable snapshot at a given level.
	 *
	 * @param SnapshotLevel $level Level to look for.
	 * @return Snapshot|null
	 */
	public function latestRestorable( SnapshotLevel $level ): ?Snapshot {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE level = %s AND status = %s ORDER BY id DESC LIMIT 1",
				$level->value,
				SnapshotStatus::COMPLETE->value
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->fromRow( $row ) : null;
	}

	/**
	 * Recent snapshots, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,Snapshot>
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOTS );
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return $this->hydrate( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Store the rows a data operation is about to delete.
	 *
	 * @param int                     $snapshot_id Snapshot id.
	 * @param array<int,SnapshotItem> $items       Items to store.
	 * @return int How many rows were written.
	 * @throws RuntimeException When a batch fails.
	 */
	public function addItems( int $snapshot_id, array $items ): int {
		global $wpdb;

		if ( array() === $items ) {
			return 0;
		}

		$table   = Schema::table( Schema::SNAPSHOT_ITEMS );
		$written = 0;

		foreach ( array_chunk( $items, self::INSERT_BATCH ) as $batch ) {
			$values       = array();
			$placeholders = array();

			foreach ( $batch as $item ) {
				$placeholders[] = '(%d, %s, %s, %s, 0)';

				$values[] = $snapshot_id;
				$values[] = $item->object_type;
				$values[] = $item->object_key;
				$values[] = Json::encode( $item->payload );
			}

			$sql = "INSERT INTO `{$table}` (snapshot_id, object_type, object_key, payload, restored) VALUES "
				. implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder list is built from a count, never from input; every value is parameterised.
			$result = $wpdb->query( $wpdb->prepare( $sql, ...$values ) );

			if ( false === $result ) {
				throw new RuntimeException(
					'Could not store the rows this operation would delete, so it will not run: ' . $wpdb->last_error
				);
			}

			$written += count( $batch );
		}

		return $written;
	}

	/**
	 * The items of a snapshot, oldest first.
	 *
	 * @param int  $snapshot_id      Snapshot id.
	 * @param bool $unrestored_only  Whether to skip items already put back.
	 * @return array<int,array{id:int,item:SnapshotItem}>
	 */
	public function items( int $snapshot_id, bool $unrestored_only = false ): array {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOT_ITEMS );

		$sql = "SELECT * FROM `{$table}` WHERE snapshot_id = %d"
			. ( $unrestored_only ? ' AND restored = 0' : '' )
			. ' ORDER BY id ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $snapshot_id ), ARRAY_A );

		$items = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			try {
				$payload = Json::decodeArray( (string) $row['payload'] );

				/** @var array<string,mixed> $payload */
				$items[] = array(
					'id'   => (int) $row['id'],
					'item' => new SnapshotItem(
						(string) $row['object_type'],
						(string) $row['object_key'],
						$payload,
						(bool) $row['restored']
					),
				);
			} catch ( RuntimeException | ContractViolation $exception ) {
				unset( $exception );

				// One unreadable row makes the whole snapshot untrustworthy: a
				// partial restore leaves the site in a state nobody has a name
				// for. Refuse rather than restore what happens to parse.
				throw new RuntimeException(
					sprintf( 'Recovery point %d contains a row that cannot be read; it will not be restored.', $snapshot_id )
				);
			}
		}

		return $items;
	}

	/**
	 * How many items a snapshot holds.
	 *
	 * @param int $snapshot_id Snapshot id.
	 * @return int
	 */
	public function countItems( int $snapshot_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOT_ITEMS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE snapshot_id = %d", $snapshot_id )
		);
	}

	/**
	 * Mark items as put back.
	 *
	 * @param array<int,int> $item_ids Row ids of the restored items.
	 * @return int How many rows were marked.
	 */
	public function markRestored( array $item_ids ): int {
		global $wpdb;

		if ( array() === $item_ids ) {
			return 0;
		}

		$table        = Schema::table( Schema::SNAPSHOT_ITEMS );
		$placeholders = implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$result = $wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET restored = 1 WHERE id IN ({$placeholders})", ...$item_ids )
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete a snapshot and its items.
	 *
	 * Items first: an orphaned item row is invisible and unreachable, whereas a
	 * snapshot whose items failed to delete is at least still consistent.
	 *
	 * @param int $snapshot_id Snapshot id.
	 * @return bool
	 */
	public function delete( int $snapshot_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$wpdb->delete( Schema::table( Schema::SNAPSHOT_ITEMS ), array( 'snapshot_id' => $snapshot_id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return false !== $wpdb->delete( Schema::table( Schema::SNAPSHOTS ), array( 'id' => $snapshot_id ), array( '%d' ) );
	}

	/**
	 * How many snapshots are stored.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		$table = Schema::table( Schema::SNAPSHOTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Map a snapshot to a database row.
	 *
	 * @param Snapshot $snapshot Snapshot to map.
	 * @return array<string,mixed>
	 */
	private function toRow( Snapshot $snapshot ): array {
		return array(
			'run_id'         => $snapshot->run_id,
			'level'          => $snapshot->level->value,
			'created_at'     => $snapshot->created_at,
			'site_hash'      => $snapshot->site_hash,
			'plugin_version' => $snapshot->plugin_version,
			'config'         => null === $snapshot->config ? null : Json::encode( $snapshot->config ),
			'items_count'    => $snapshot->items_count,
			'bytes'          => $snapshot->bytes,
			'storage'        => $snapshot->storage,
			'file_path'      => $snapshot->file_path,
			'checksum'       => $snapshot->checksum,
			'status'         => $snapshot->status->value,
		);
	}

	/**
	 * Map rows to snapshots, skipping any this version cannot read.
	 *
	 * @param array<int,array<string,mixed>> $rows Database rows.
	 * @return array<int,Snapshot>
	 */
	private function hydrate( array $rows ): array {
		$snapshots = array();

		foreach ( $rows as $row ) {
			$snapshot = $this->fromRow( $row );

			if ( null !== $snapshot ) {
				$snapshots[] = $snapshot;
			}
		}

		return $snapshots;
	}

	/**
	 * Map a database row back to a snapshot.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return Snapshot|null Null when the row cannot be read.
	 */
	private function fromRow( array $row ): ?Snapshot {
		$config = null;

		if ( is_string( $row['config'] ?? null ) && '' !== $row['config'] ) {
			try {
				$config = Json::decodeArray( (string) $row['config'] );
			} catch ( RuntimeException $exception ) {
				unset( $exception );

				return null;
			}
		}

		try {
			/** @var array<string,mixed>|null $config */
			return Snapshot::fromArray(
				array(
					'id'             => (int) $row['id'],
					'run_id'         => (int) $row['run_id'],
					'level'          => (string) $row['level'],
					'created_at'     => (string) $row['created_at'],
					'site_hash'      => (string) $row['site_hash'],
					'plugin_version' => (string) $row['plugin_version'],
					'config'         => $config,
					'items_count'    => (int) $row['items_count'],
					'bytes'          => (int) $row['bytes'],
					'storage'        => (string) $row['storage'],
					'file_path'      => null === $row['file_path'] ? null : (string) $row['file_path'],
					'checksum'       => (string) $row['checksum'],
					'status'         => (string) $row['status'],
				)
			);
		} catch ( ContractViolation $exception ) {
			unset( $exception );

			return null;
		}
	}

	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}
