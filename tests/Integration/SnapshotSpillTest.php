<?php
/**
 * Level B recovery points too large for the database.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use RuntimeException;
use WPDebloat\Apply\DataOperations\ExpiredTransientsCleanup;
use WPDebloat\Apply\Lock;
use WPDebloat\Contracts\PreviewPlan;
use WPDebloat\Contracts\RunState;
use WPDebloat\Contracts\SnapshotLevel;
use WPDebloat\Contracts\SnapshotStatus;
use WPDebloat\Snapshot\SnapshotManager;
use WPDebloat\Snapshot\SpillFile;

/**
 * BUILD-SPEC §4 and §8: above eight megabytes, Level B items go to a gzipped
 * file under wp-content/wpdebloat/backups instead of the snapshot_items table.
 *
 * This test writes a genuinely oversized snapshot rather than lowering the
 * threshold for the occasion. The threshold is a real number that real sites
 * will cross, and a test that moves it proves only that the test can move it.
 */
final class SnapshotSpillTest extends IntegrationTestCase {

	/**
	 * Bytes per transient value, chosen so a manageable number of rows crosses
	 * the eight-megabyte threshold.
	 */
	private const VALUE_BYTES = 64 * 1024;

	/**
	 * Transients to create: 160 x 64 KB is comfortably over the threshold.
	 */
	private const TRANSIENTS = 160;

	/**
	 * Prepare the tables and clear the lock.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();
	}

	/**
	 * Remove anything written to the backups directory.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$files = glob( $this->context()->backupsDir() . '/snapshot-*.ndjson.gz' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			unlink( $file );
		}

		( new Lock() )->forceRelease();

		parent::tear_down();
	}

	/**
	 * A snapshot over the threshold is written to a file, verifies from that
	 * file, and restores from it exactly.
	 *
	 * @return void
	 */
	public function test_a_large_recovery_point_spills_to_a_gzipped_file_and_restores_from_it(): void {
		$expected = $this->createExpiredTransients();

		$result = $this->plugin->apply( $this->transientPlan() );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );

		$snapshot = $this->levelB( $result->run_id );

		$this->assertSame( 'file', $snapshot->storage, 'A snapshot this size belongs in a file.' );
		$this->assertNotNull( $snapshot->file_path );
		$this->assertFileExists( (string) $snapshot->file_path );
		$this->assertSame( self::TRANSIENTS, $snapshot->items_count );
		$this->assertSame( SnapshotStatus::COMPLETE, $snapshot->status );

		$this->assertSame(
			0,
			$this->plugin->snapshots()->countItems( (int) $snapshot->id ),
			'A spilled snapshot must not also fill the items table.'
		);

		$this->assertTrue(
			$this->plugin->snapshotManager()->verify( $snapshot ),
			'A spilled snapshot must verify from its file.'
		);

		foreach ( array_keys( $expected ) as $name ) {
			$this->assertFalse( get_option( '_transient_' . $name, false ), 'The rows should have been deleted.' );
		}

		$undone = $this->plugin->rollback( $result->run_id );

		$this->assertSame( RunState::ROLLED_BACK, $undone->state, (string) $undone->error );

		foreach ( $expected as $name => $value ) {
			$this->assertSame(
				$value,
				get_option( '_transient_' . $name ),
				'A restored row must hold exactly what it held before.'
			);
		}
	}

	/**
	 * A truncated spill file fails verification instead of restoring what is
	 * left of it.
	 *
	 * @return void
	 */
	public function test_a_truncated_spill_file_is_refused(): void {
		$this->createExpiredTransients();

		$result   = $this->plugin->apply( $this->transientPlan() );
		$snapshot = $this->levelB( $result->run_id );
		$path     = (string) $snapshot->file_path;

		$intact = (string) file_get_contents( $path );

		file_put_contents( $path, substr( $intact, 0, (int) floor( strlen( $intact ) / 2 ) ) );

		try {
			$this->plugin->snapshotManager()->verify( $snapshot );

			$this->fail( 'A truncated recovery file should not verify.' );
		} catch ( RuntimeException $error ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame(
			SnapshotStatus::CORRUPT,
			$this->plugin->snapshots()->find( (int) $snapshot->id )->status,
			'A snapshot whose file will not read must be marked corrupt.'
		);

		$this->expectExceptionMessageMatches( '/did not verify/' );

		$this->plugin->rollbackManager()->restore(
			$this->plugin->snapshots()->find( (int) $snapshot->id )
		);
	}

	/**
	 * A missing spill file is reported, not silently treated as zero rows.
	 *
	 * @return void
	 */
	public function test_a_missing_spill_file_is_reported(): void {
		$this->createExpiredTransients();

		$result   = $this->plugin->apply( $this->transientPlan() );
		$snapshot = $this->levelB( $result->run_id );

		unlink( (string) $snapshot->file_path );

		$this->expectExceptionMessageMatches( '/is missing/' );

		$this->plugin->snapshotManager()->verify( $snapshot );
	}

	/**
	 * Forgetting a spilled snapshot removes its file too.
	 *
	 * @return void
	 */
	public function test_forgetting_a_spilled_snapshot_removes_its_file(): void {
		$this->createExpiredTransients();

		$result   = $this->plugin->apply( $this->transientPlan() );
		$snapshot = $this->levelB( $result->run_id );
		$path     = (string) $snapshot->file_path;

		$this->assertFileExists( $path );
		$this->assertTrue( $this->plugin->snapshotManager()->forget( $snapshot ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertNull( $this->plugin->snapshots()->find( (int) $snapshot->id ) );
	}

	/**
	 * The backups directory is closed to the web.
	 *
	 * @return void
	 */
	public function test_the_backups_directory_is_not_browsable(): void {
		$this->createExpiredTransients();

		$this->plugin->apply( $this->transientPlan() );

		$this->assertFileExists( $this->context()->backupsDir() . '/index.php' );
		$this->assertFileExists( $this->context()->backupsDir() . '/.htaccess' );
	}

	/**
	 * A path outside the backups directory is refused, whatever it looks like.
	 *
	 * @return void
	 */
	public function test_a_path_outside_the_backups_directory_is_refused(): void {
		$spill = new SpillFile( $this->context() );

		$this->expectExceptionMessageMatches( '/outside the backups directory/' );

		iterator_to_array(
			$spill->read( $this->context()->backupsDir() . '/../../wp-config.php' )
		);
	}

	/**
	 * The threshold is the documented one.
	 *
	 * @return void
	 */
	public function test_the_threshold_is_eight_megabytes(): void {
		$this->assertSame( 8 * 1024 * 1024, SnapshotManager::SPILL_THRESHOLD_BYTES );
	}

	/**
	 * Create enough expired transients to cross the threshold.
	 *
	 * @return array<string,string> Transient name to stored option value.
	 */
	private function createExpiredTransients(): array {
		$values = array();

		for ( $index = 0; $index < self::TRANSIENTS; $index++ ) {
			$name  = 'wpd_big_' . $index;
			$value = str_repeat( (string) chr( 97 + ( $index % 26 ) ), self::VALUE_BYTES );

			set_transient( $name, $value, 60 );
			update_option( '_transient_timeout_' . $name, time() - 3600 );

			$values[ $name ] = $value;
		}

		return $values;
	}

	/**
	 * A plan holding only the transient cleanup.
	 *
	 * @return PreviewPlan
	 */
	private function transientPlan(): PreviewPlan {
		return new PreviewPlan(
			array( $this->plugin->registry()->tweak( ExpiredTransientsCleanup::TWEAK_ID )->resolve() )
		);
	}

	/**
	 * The Level B snapshot of a run.
	 *
	 * @param int $run_id Run id.
	 * @return \WPDebloat\Contracts\Snapshot
	 */
	private function levelB( int $run_id ): \WPDebloat\Contracts\Snapshot {
		foreach ( $this->plugin->snapshots()->forRun( $run_id ) as $snapshot ) {
			if ( SnapshotLevel::B === $snapshot->level ) {
				return $snapshot;
			}
		}

		$this->fail( 'The run has no Level B recovery point.' );
	}
}
