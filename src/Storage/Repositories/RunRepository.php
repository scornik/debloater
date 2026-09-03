<?php
/**
 * Reads and writes run records.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Storage\Repositories;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Json;
use Debloater\Contracts\Run;
use Debloater\Contracts\RunType;
use Debloater\Storage\Schema;

/**
 * Persistence for debloater_runs (BUILD-SPEC §8).
 *
 * A run written by a newer version of the plugin, or corrupted in storage, is
 * reported as unreadable rather than crashing the screen that lists it. The
 * contracts refuse to construct from bad data by design (D-0002); this is the
 * layer that turns that refusal into "this run cannot be displayed" instead of
 * a fatal error on an admin page.
 */
final class RunRepository {

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- A table name cannot be a placeholder, and every one interpolated in this class is built from Schema's own constants plus $wpdb->prefix. Values are always parameterised; the sniff cannot see the difference.

	/**
	 * Insert a run and return it with its assigned id.
	 *
	 * @param Run $run Run to insert.
	 * @return Run
	 * @throws RuntimeException When the insert fails.
	 */
	public function insert( Run $run ): Run {
		global $wpdb;

		$table = Schema::table( Schema::RUNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table; there is no WordPress API for it and the result must not be cached.
		$inserted = $wpdb->insert( $table, $this->toRow( $run ), $this->formats() );

		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not record the run: ' . $wpdb->last_error );
		}

		return $run->withId( (int) $wpdb->insert_id );
	}

	/**
	 * Update an existing run.
	 *
	 * @param Run $run Run to update; must have an id.
	 * @return Run
	 * @throws RuntimeException When the run has no id or the update fails.
	 */
	public function update( Run $run ): Run {
		global $wpdb;

		if ( null === $run->id ) {
			throw new RuntimeException( 'Cannot update a run that has not been inserted.' );
		}

		$table = Schema::table( Schema::RUNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$updated = $wpdb->update( $table, $this->toRow( $run ), array( 'id' => $run->id ), $this->formats(), array( '%d' ) );

		if ( false === $updated ) {
			throw new RuntimeException( 'Could not update the run: ' . $wpdb->last_error );
		}

		return $run;
	}

	/**
	 * Find a run by id.
	 *
	 * @param int $id Run id.
	 * @return Run|null Null when absent or unreadable.
	 */
	public function find( int $id ): ?Run {
		global $wpdb;

		$table = Schema::table( Schema::RUNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from our own constant; the id is parameterised.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table,
				$id 
			),
			ARRAY_A 
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->fromRow( $row );
	}

	/**
	 * The most recent run of a given type.
	 *
	 * @param RunType $type Run type.
	 * @return Run|null
	 */
	public function latestOfType( RunType $type ): ?Run {
		global $wpdb;

		$table = Schema::table( Schema::RUNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- As above.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE type = %s ORDER BY id DESC LIMIT 1',
				$table,
				$type->value 
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->fromRow( $row );
	}

	/**
	 * Recent runs, newest first.
	 *
	 * Rows that cannot be read back into a contract are skipped rather than
	 * failing the whole listing.
	 *
	 * @param int          $limit Maximum rows.
	 * @param RunType|null $type  Restrict to one type, or null for all.
	 * @return array<int,Run>
	 */
	public function recent( int $limit = 20, ?RunType $type = null ): array {
		global $wpdb;

		$table = Schema::table( Schema::RUNS );
		$limit = max( 1, min( 200, $limit ) );

		if ( null === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
					$table,
					$limit 
				),
				ARRAY_A 
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE type = %s ORDER BY id DESC LIMIT %d',
					$table,
					$type->value,
					$limit 
				),
				ARRAY_A
			);
		}

		$runs = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$run = $this->fromRow( $row );

			if ( null !== $run ) {
				$runs[] = $run;
			}
		}

		return $runs;
	}

	/**
	 * Runs left in a state that means the site may be partially changed.
	 *
	 * Used by crash recovery at boot (BUILD-SPEC §9.2).
	 *
	 * @param array<int,string> $statuses Statuses to look for.
	 * @return array<int,Run>
	 */
	public function withStatus( array $statuses ): array {
		global $wpdb;

		if ( array() === $statuses ) {
			return array();
		}

		$table        = Schema::table( Schema::RUNS );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder list is built from a count of the statuses, never from input, and every value is parameterised.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The count is right at runtime: the sniff sees only the placeholders written in the literal, not the ones inside $placeholders.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ({$placeholders}) ORDER BY id ASC",
				$table,
				...$statuses
			),
			ARRAY_A
		);

		$runs = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$run = $this->fromRow( $row );

			if ( null !== $run ) {
				$runs[] = $run;
			}
		}

		return $runs;
	}

	/**
	 * Delete a run.
	 *
	 * @param int $id Run id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return false !== $wpdb->delete( Schema::table( Schema::RUNS ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * How many runs are stored.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		$table = Schema::table( Schema::RUNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Map a run to a database row.
	 *
	 * @param Run $run Run to map.
	 * @return array<string,mixed>
	 */
	private function toRow( Run $run ): array {
		return array(
			'type'           => $run->type->value,
			'status'         => $run->status,
			'actor'          => $run->actor,
			'started_at'     => $run->started_at,
			'finished_at'    => $run->finished_at,
			'plugin_version' => $run->plugin_version,
			'registry_hash'  => $run->registry_hash,
			'payload'        => array() === $run->payload ? null : Json::encode( $run->payload ),
			'error'          => $run->error,
		);
	}

	/**
	 * Column formats, in the same order as toRow().
	 *
	 * @return array<int,string>
	 */
	private function formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
	}

	/**
	 * Map a database row back to a run.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return Run|null Null when the row cannot be read.
	 */
	private function fromRow( array $row ): ?Run {
		$payload = array();

		if ( is_string( $row['payload'] ?? null ) && '' !== $row['payload'] ) {
			try {
				$decoded = Json::decodeArray( (string) $row['payload'] );
				$payload = $decoded;
			} catch ( RuntimeException $exception ) {
				unset( $exception );

				return null;
			}
		}

		try {
			/** @var array<string,mixed> $payload */
			return Run::fromArray(
				array(
					'id'             => (int) $row['id'],
					'type'           => (string) $row['type'],
					'status'         => (string) $row['status'],
					'actor'          => (string) $row['actor'],
					'started_at'     => (string) $row['started_at'],
					'finished_at'    => null === $row['finished_at'] ? null : (string) $row['finished_at'],
					'plugin_version' => (string) $row['plugin_version'],
					'registry_hash'  => (string) $row['registry_hash'],
					'payload'        => $payload,
					'error'          => null === $row['error'] ? null : (string) $row['error'],
				)
			);
		} catch ( ContractViolation $exception ) {
			unset( $exception );

			// A row this version cannot read is not a reason to break the page
			// that lists it. It is reported as missing and left alone.
			return null;
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}
