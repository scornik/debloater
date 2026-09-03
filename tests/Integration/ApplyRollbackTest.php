<?php
/**
 * Applying a plan, and undoing it exactly.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Apply\ApplyManager;
use Debloater\Apply\DataOperations\ExpiredTransientsCleanup;
use Debloater\Apply\Lock;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\RunState;
use Debloater\Contracts\Snapshot;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Contracts\SnapshotStatus;
use Debloater\Contracts\TweakState;
use Debloater\Storage\Schema;

/**
 * BUILD-SPEC §17 Phase 5.
 *
 * The phase the spec calls "the most important engineering phase", so these
 * tests are written to fail rather than to pass: the rollback assertions compare
 * bytes and row contents, not counts, because a rollback that restores the right
 * *number* of things and the wrong *things* is the failure mode that matters.
 */
final class ApplyRollbackTest extends IntegrationTestCase {

	/**
	 * The five config tweaks applied together.
	 *
	 * @var array<int,string>
	 */
	private const CONFIG_TWEAKS = array(
		'core.disable_emojis',
		'core.remove_generator',
		'core.remove_rsd',
		'core.remove_shortlink',
		'core.disable_self_pingbacks',
	);

	/**
	 * Make sure the tables exist and no lock is left over.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();
	}

	/**
	 * Release anything a test left holding.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		( new Lock() )->forceRelease();

		remove_all_filters( 'debloater_tweak_options' );

		parent::tear_down();
	}

	/**
	 * Applying five config tweaks and rolling back returns the runtime to the
	 * exact bytes it had before, and the options to their exact values.
	 *
	 * @return void
	 */
	public function test_applying_five_config_tweaks_then_rolling_back_restores_the_runtime_byte_for_byte(): void {
		// A site that already has one tweak in place, so the rollback has
		// something to restore rather than merely something to delete.
		$this->selectAndGenerate( array( 'core.remove_jquery_migrate' => array() ) );

		update_option( 'debloater_test_option', array( 'kept' => 'exactly this' ) );

		add_filter(
			'debloater_tweak_options',
			static fn ( array $options ): array => array_merge( $options, array( 'debloater_test_option' ) )
		);

		$runtime  = $this->context()->runtimeFile();
		$before   = (string) file_get_contents( $runtime );
		$hash     = $this->plugin->state()->runtimeHash();
		$selected = $this->plugin->state()->selection();

		$this->assertNotSame( '', $before, 'The baseline runtime should have been written.' );

		$result = $this->plugin->apply( $this->planOf( self::CONFIG_TWEAKS ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );
		$expected = self::CONFIG_TWEAKS;
		sort( $expected, SORT_STRING );

		$this->assertSame( $expected, $result->applied, 'Every planned tweak should have been applied.' );

		$applied_runtime = (string) file_get_contents( $runtime );

		$this->assertNotSame( $before, $applied_runtime, 'Applying should have changed the runtime.' );

		// Something else writes the option in between, so a restore that merely
		// leaves it alone cannot pass.
		update_option( 'debloater_test_option', 'clobbered' );

		$undone = $this->plugin->rollback( $result->run_id );

		$this->assertSame( RunState::ROLLED_BACK, $undone->state );
		$this->assertSame( $before, (string) file_get_contents( $runtime ), 'The runtime is not byte-identical.' );
		$this->assertSame( $hash, $this->plugin->state()->runtimeHash() );
		$this->assertSame( $selected, $this->plugin->state()->selection() );
		$this->assertSame( array( 'kept' => 'exactly this' ), get_option( 'debloater_test_option' ) );

		foreach ( self::CONFIG_TWEAKS as $tweak_id ) {
			$this->assertSame(
				TweakState::ROLLED_BACK,
				$this->plugin->state()->tweakStates()[ $tweak_id ] ?? null,
				sprintf( '%s should be recorded as rolled back.', $tweak_id )
			);
		}
	}

	/**
	 * An option that did not exist before the apply is removed again by the
	 * rollback, not left behind with a restored value.
	 *
	 * @return void
	 */
	public function test_rollback_removes_an_option_that_did_not_exist_before(): void {
		delete_option( 'debloater_absent_option' );

		add_filter(
			'debloater_tweak_options',
			static fn ( array $options ): array => array_merge( $options, array( 'debloater_absent_option' ) )
		);

		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );

		update_option( 'debloater_absent_option', 'created after the snapshot' );

		$this->plugin->rollback( $result->run_id );

		$this->assertFalse(
			get_option( 'debloater_absent_option', false ),
			'An option absent at snapshot time must be absent again after the restore.'
		);
	}

	/**
	 * Every transition is journalled, in the order §9.1 allows.
	 *
	 * @return void
	 */
	public function test_every_tweak_transition_is_journalled(): void {
		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$entries = $this->plugin->journal()->forRun( $result->run_id );
		$states  = array();

		foreach ( $entries as $entry ) {
			if ( 'core.remove_generator' === $entry['tweak_id'] ) {
				$states[] = $entry['from_state'] . '->' . $entry['to_state'];
			}
		}

		$this->assertSame(
			array(
				'SELECTED->PREVIEWED',
				'PREVIEWED->SNAPSHOTTED',
				'SNAPSHOTTED->APPLIED',
				'APPLIED->VERIFIED',
				'VERIFIED->COMMITTED',
			),
			$states,
			'The journal should read as the §9.1 lifecycle, with no invented edges.'
		);

		foreach ( $entries as $entry ) {
			$from = TweakState::from( (string) $entry['from_state'] );
			$to   = TweakState::from( (string) $entry['to_state'] );

			$this->assertTrue(
				$from === $to || $from->canTransitionTo( $to ),
				sprintf( 'The journal records %s -> %s, which the table does not allow.', $from->value, $to->value )
			);
		}
	}

	/**
	 * The transient cleanup round-trip: rows and timeouts come back exactly.
	 *
	 * @return void
	 */
	public function test_expired_transient_cleanup_round_trips_rows_and_timeouts_exactly(): void {
		$expired = array();

		for ( $index = 0; $index < 12; $index++ ) {
			$name = 'wpd_expired_' . $index;

			set_transient(
				$name,
				array(
					'index'   => $index,
					'payload' => str_repeat( 'x', 16 ),
				),
				60 
			);

			// Push the expiry into the past, which is the only way to make a
			// genuinely expired transient without waiting.
			$timeout = time() - ( 3600 + $index );

			update_option( '_transient_timeout_' . $name, $timeout );

			$expired[ $name ] = $timeout;
		}

		set_transient( 'wpd_live_transient', 'still valid', HOUR_IN_SECONDS );

		$before = $this->transientRows( array_keys( $expired ) );

		$this->assertCount( 24, $before, 'Each transient should be two rows before the cleanup.' );

		$result = $this->plugin->apply( $this->planOf( array( ExpiredTransientsCleanup::TWEAK_ID ) ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );
		$this->assertSame( array(), $this->transientRows( array_keys( $expired ) ), 'The expired rows should be gone.' );
		$this->assertSame( 'still valid', get_transient( 'wpd_live_transient' ), 'A live transient must be untouched.' );

		$undone = $this->plugin->rollback( $result->run_id );

		$this->assertSame( RunState::ROLLED_BACK, $undone->state );
		$this->assertSame( $before, $this->transientRows( array_keys( $expired ) ), 'The rows did not come back exactly.' );

		foreach ( $expired as $name => $timeout ) {
			$this->assertSame(
				(string) $timeout,
				(string) get_option( '_transient_timeout_' . $name ),
				'A restored transient must keep its original expiry, not get a fresh one.'
			);

			$this->assertFalse(
				get_transient( $name ),
				'A restored expired transient must still read as expired.'
			);
		}
	}

	/**
	 * A recovery point whose checksum no longer matches is refused, and nothing
	 * is written.
	 *
	 * @return void
	 */
	public function test_a_corrupt_recovery_point_refuses_to_restore(): void {
		$this->selectAndGenerate( array( 'core.remove_jquery_migrate' => array() ) );

		$before = (string) file_get_contents( $this->context()->runtimeFile() );
		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );

		$snapshot = $this->plugin->snapshots()->forRun( $result->run_id )[0];
		$tampered = $this->tamper( $snapshot->id, array( 'selection' => array( 'core.disable_emojis' => array() ) ) );

		$this->assertNotNull( $tampered );

		$this->expectExceptionMessageMatches( '/does not match its checksum/' );

		try {
			$this->plugin->snapshotManager()->verify( $tampered );
		} finally {
			$this->assertSame(
				SnapshotStatus::CORRUPT,
				$this->plugin->snapshots()->find( (int) $snapshot->id )->status,
				'A snapshot that fails verification must be marked corrupt.'
			);

			$this->assertNotSame(
				$before,
				(string) file_get_contents( $this->context()->runtimeFile() ),
				'Verification must not have written anything.'
			);
		}
	}

	/**
	 * A corrupt snapshot cannot be restored even when asked directly.
	 *
	 * @return void
	 */
	public function test_restoring_a_corrupt_recovery_point_is_refused(): void {
		$result   = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );
		$snapshot = $this->plugin->snapshots()->forRun( $result->run_id )[0];

		$this->plugin->snapshots()->update( $snapshot->withStatus( SnapshotStatus::CORRUPT ) );

		$corrupt = $this->plugin->snapshots()->find( (int) $snapshot->id );

		$this->assertNotNull( $this->plugin->rollbackManager()->refusalReason( $corrupt ) );

		$this->expectExceptionMessageMatches( '/did not verify/' );

		$this->plugin->rollbackManager()->restore( $corrupt );
	}

	/**
	 * A recovery point from another site is refused.
	 *
	 * @return void
	 */
	public function test_a_recovery_point_from_another_site_is_refused(): void {
		$result   = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );
		$snapshot = $this->plugin->snapshots()->forRun( $result->run_id )[0];

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a snapshot restored onto the wrong site.
		$wpdb->update(
			Schema::table( Schema::SNAPSHOTS ),
			array( 'site_hash' => str_repeat( 'a', 64 ) ),
			array( 'id' => (int) $snapshot->id ),
			array( '%s' ),
			array( '%d' )
		);

		$foreign = $this->plugin->snapshots()->find( (int) $snapshot->id );

		$this->assertStringContainsString(
			'different site',
			(string) $this->plugin->rollbackManager()->refusalReason( $foreign )
		);
	}

	/**
	 * A second apply while the lock is held is rejected, and changes nothing.
	 *
	 * @return void
	 */
	public function test_a_second_apply_while_locked_is_rejected(): void {
		$this->selectAndGenerate( array( 'core.remove_jquery_migrate' => array() ) );

		$before = (string) file_get_contents( $this->context()->runtimeFile() );
		$holder = new Lock( 'another-request' );

		$this->assertTrue( $holder->acquire(), 'The first claim on the lock should succeed.' );

		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$this->assertSame( RunState::ABORTED, $result->state );
		$this->assertStringContainsString( 'already in progress', (string) $result->error );
		$this->assertSame( array(), $result->applied );
		$this->assertSame( $before, (string) file_get_contents( $this->context()->runtimeFile() ) );
		$this->assertSame( 'another-request', $holder->heldBy(), 'The rejected run must not steal the lock.' );

		$holder->release();
	}

	/**
	 * The lock is released once a run settles, so the next apply can proceed.
	 *
	 * @return void
	 */
	public function test_the_lock_is_released_when_a_run_settles(): void {
		$first = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$this->assertSame( RunState::COMMITTED, $first->state, (string) $first->error );
		$this->assertNull( ( new Lock() )->heldBy(), 'A committed run must not leave the lock held.' );

		$second = $this->plugin->apply( $this->planOf( array( 'core.remove_rsd' ) ) );

		$this->assertSame( RunState::COMMITTED, $second->state, (string) $second->error );
	}

	/**
	 * A run whose process died in APPLYING is rolled back on the next boot.
	 *
	 * @return void
	 */
	public function test_a_run_interrupted_in_applying_is_rolled_back_on_the_next_boot(): void {
		global $wpdb;

		$this->selectAndGenerate( array( 'core.remove_jquery_migrate' => array() ) );

		$before = (string) file_get_contents( $this->context()->runtimeFile() );
		$result = $this->plugin->apply( $this->planOf( self::CONFIG_TWEAKS ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );
		$this->assertNotSame( $before, (string) file_get_contents( $this->context()->runtimeFile() ) );

		// Rewind the run to the state a crash would have left it in: applied to
		// the site, never verified, never committed, and nobody coming back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a process that died mid-apply.
		$wpdb->update(
			Schema::table( Schema::RUNS ),
			array( 'status' => RunState::APPLYING->value ),
			array( 'id' => $result->run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$recovered = $this->plugin->recoverInterruptedRuns();

		$this->assertSame( array( $result->run_id ), $recovered );
		$this->assertSame(
			$before,
			(string) file_get_contents( $this->context()->runtimeFile() ),
			'Crash recovery must restore the runtime exactly.'
		);

		$run = $this->plugin->runs()->find( $result->run_id );

		$this->assertSame( RunState::ROLLED_BACK->value, $run->status );
		$this->assertStringContainsString( 'interrupted', (string) $run->error );
		$this->assertNull( ( new Lock() )->heldBy(), 'Recovery must not leave the lock held.' );
	}

	/**
	 * A live apply is not mistaken for a crashed one.
	 *
	 * @return void
	 */
	public function test_crash_recovery_leaves_a_running_apply_alone(): void {
		global $wpdb;

		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating an apply still in flight.
		$wpdb->update(
			Schema::table( Schema::RUNS ),
			array( 'status' => RunState::APPLYING->value ),
			array( 'id' => $result->run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$holder = new Lock( 'the-request-still-working' );

		$this->assertTrue( $holder->acquire() );

		$this->assertSame(
			array(),
			$this->plugin->recoverInterruptedRuns(),
			'A run whose lock is still held is running, not interrupted.'
		);

		$this->assertSame( 'the-request-still-working', $holder->heldBy() );

		$holder->release();
	}

	/**
	 * A data operation with no complete recovery point does not run.
	 *
	 * @return void
	 */
	public function test_a_data_operation_without_a_recovery_point_does_not_run(): void {
		set_transient( 'wpd_protected', 'value', 60 );
		update_option( '_transient_timeout_wpd_protected', time() - 3600 );

		// An apply manager that knows about the tweak but has no operation for
		// it cannot snapshot it, and so must not run it.
		$manager = new ApplyManager(
			$this->context(),
			$this->plugin->registry(),
			$this->plugin->runs(),
			$this->plugin->snapshots(),
			$this->plugin->snapshotManager(),
			$this->plugin->rollbackManager(),
			$this->plugin->state(),
			$this->plugin->journal(),
			array(),
			new Lock()
		);

		$result = $manager->apply( $this->planOf( array( ExpiredTransientsCleanup::TWEAK_ID ) ) );

		$this->assertSame( RunState::ABORTED, $result->state );
		$this->assertStringContainsString( 'cannot be backed up', (string) $result->error );
		$this->assertNotFalse(
			get_option( '_transient_wpd_protected', false ),
			'The rows must still be there: nothing may run without cover.'
		);
	}

	/**
	 * The Level B snapshot holds every row the operation is about to delete.
	 *
	 * @return void
	 */
	public function test_the_level_b_snapshot_covers_every_row_before_anything_is_deleted(): void {
		for ( $index = 0; $index < 5; $index++ ) {
			set_transient( 'wpd_covered_' . $index, $index, 60 );
			update_option( '_transient_timeout_wpd_covered_' . $index, time() - 100 );
		}

		$result = $this->plugin->apply( $this->planOf( array( ExpiredTransientsCleanup::TWEAK_ID ) ) );
		$levels = array();

		foreach ( $this->plugin->snapshots()->forRun( $result->run_id ) as $snapshot ) {
			$levels[ $snapshot->level->value ] = $snapshot;
		}

		$this->assertArrayHasKey( SnapshotLevel::A->value, $levels, 'Level A is always taken.' );
		$this->assertArrayHasKey( SnapshotLevel::B->value, $levels, 'A data tweak requires Level B.' );

		$data = $levels[ SnapshotLevel::B->value ];

		$this->assertSame( SnapshotStatus::COMPLETE, $data->status );
		$this->assertSame( 5, $data->items_count );
		$this->assertTrue( $this->plugin->snapshotManager()->verify( $data ) );
	}

	/**
	 * Build a plan from tweak ids.
	 *
	 * @param array<int,string> $tweak_ids Tweak ids.
	 * @return PreviewPlan
	 */
	private function planOf( array $tweak_ids ): PreviewPlan {
		$tweaks = array();

		foreach ( $tweak_ids as $tweak_id ) {
			$tweaks[] = $this->plugin->registry()->tweak( $tweak_id )->resolve();
		}

		return new PreviewPlan( $tweaks );
	}

	/**
	 * Every option row belonging to the given transients, as stored.
	 *
	 * @param array<int,string> $names Transient names.
	 * @return array<string,array<string,string>>
	 */
	private function transientRows( array $names ): array {
		global $wpdb;

		$rows = array();

		foreach ( $names as $name ) {
			foreach ( array( '_transient_' . $name, '_transient_timeout_' . $name ) as $option ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the raw rows is the point of the assertion.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
						$option
					),
					ARRAY_A
				);

				if ( is_array( $row ) ) {
					$rows[ $option ] = array(
						'option_value' => (string) $row['option_value'],
						'autoload'     => (string) $row['autoload'],
					);
				}
			}
		}

		ksort( $rows, SORT_STRING );

		return $rows;
	}

	/**
	 * Change a stored snapshot's configuration behind its checksum's back.
	 *
	 * @param int|null            $snapshot_id Snapshot to tamper with.
	 * @param array<string,mixed> $overrides   Configuration keys to replace.
	 * @return Snapshot|null
	 */
	private function tamper( ?int $snapshot_id, array $overrides ): ?Snapshot {
		global $wpdb;

		if ( null === $snapshot_id ) {
			return null;
		}

		$snapshot = $this->plugin->snapshots()->find( $snapshot_id );

		if ( null === $snapshot ) {
			return null;
		}

		$config = array_merge( (array) $snapshot->config, $overrides );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Corrupting the row deliberately; the repository would recompute the checksum.
		$wpdb->update(
			Schema::table( Schema::SNAPSHOTS ),
			array( 'config' => wp_json_encode( $config ) ),
			array( 'id' => $snapshot_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $this->plugin->snapshots()->find( $snapshot_id );
	}
}
