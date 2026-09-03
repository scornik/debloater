<?php
/**
 * Tests for the apply-run state machine.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Debloater\Apply\RunStateMachine;
use Debloater\Contracts\IllegalTransition;
use Debloater\Contracts\RunState;

/**
 * Every legal transition in BUILD-SPEC §9.2 is exercised, and every transition
 * outside the table is proved to throw.
 */
final class RunStateMachineTest extends TestCase {

	/**
	 * A new machine starts idle with itself as its only history entry.
	 *
	 * @return void
	 */
	public function test_a_new_machine_is_idle(): void {
		$machine = new RunStateMachine();

		$this->assertSame( RunState::IDLE, $machine->state() );
		$this->assertSame( array( RunState::IDLE ), $machine->history() );
		$this->assertFalse( $machine->holdsLock() );
	}

	/**
	 * Every legal transition declared by the enum is accepted.
	 *
	 * @dataProvider legalTransitionProvider
	 * @param RunState $from Starting state.
	 * @param RunState $to   Target state.
	 * @return void
	 */
	public function test_legal_transitions_are_accepted( RunState $from, RunState $to ): void {
		$machine = new RunStateMachine( $from );

		$this->assertTrue( $machine->can( $to ) );
		$this->assertSame( $to, $machine->transitionTo( $to ) );
		$this->assertSame( array( $from, $to ), $machine->history() );
	}

	/**
	 * Every transition not in the table throws, with a message naming what was
	 * allowed instead.
	 *
	 * @dataProvider illegalTransitionProvider
	 * @param RunState $from Starting state.
	 * @param RunState $to   Target state.
	 * @return void
	 */
	public function test_illegal_transitions_throw( RunState $from, RunState $to ): void {
		$machine = new RunStateMachine( $from );

		$this->assertFalse( $machine->can( $to ) );

		try {
			$machine->transitionTo( $to );
			$this->fail( sprintf( '%s -> %s should be illegal', $from->value, $to->value ) );
		} catch ( IllegalTransition $exception ) {
			$this->assertSame( $from->value, $exception->from() );
			$this->assertSame( $to->value, $exception->to() );
			$this->assertSame( $from, $machine->state(), 'a refused transition must not change the state' );
		}
	}

	/**
	 * The full happy path from BUILD-SPEC §9.2.
	 *
	 * @return void
	 */
	public function test_the_happy_path_reaches_committed(): void {
		$machine = new RunStateMachine();

		$path = array(
			RunState::PLANNING,
			RunState::PREVIEWED,
			RunState::LOCKED,
			RunState::MEASURING_BEFORE,
			RunState::SNAPSHOTTING,
			RunState::APPLYING,
			RunState::APPLIED,
			RunState::VERIFYING,
			RunState::VERIFIED,
			RunState::MEASURING_AFTER,
			RunState::COMMITTED,
		);

		foreach ( $path as $state ) {
			$machine->transitionTo( $state );
		}

		$this->assertSame( RunState::COMMITTED, $machine->state() );
		$this->assertTrue( $machine->isTerminal() );
		$this->assertFalse( $machine->holdsLock() );
	}

	/**
	 * A verification failure must be able to reach a completed rollback.
	 *
	 * @return void
	 */
	public function test_verification_failure_reaches_rolled_back(): void {
		$machine = new RunStateMachine( RunState::VERIFYING );

		$machine->transitionTo( RunState::VERIFICATION_FAILED );
		$machine->transitionTo( RunState::ROLLING_BACK );
		$machine->transitionTo( RunState::ROLLED_BACK );

		$this->assertSame( RunState::ROLLED_BACK, $machine->state() );

		$machine->transitionTo( RunState::IDLE );

		$this->assertFalse( $machine->holdsLock() );
	}

	/**
	 * An apply failure must be able to reach a completed rollback.
	 *
	 * @return void
	 */
	public function test_apply_failure_reaches_rolled_back(): void {
		$machine = new RunStateMachine( RunState::APPLYING );

		$machine->transitionTo( RunState::APPLY_FAILED );
		$machine->transitionTo( RunState::ROLLING_BACK );
		$machine->transitionTo( RunState::ROLLED_BACK );

		$this->assertSame( RunState::ROLLED_BACK, $machine->state() );
	}

	/**
	 * A crashed run is recoverable from either state it can crash in.
	 *
	 * @return void
	 */
	public function test_interrupted_runs_can_be_rolled_back(): void {
		foreach ( array( RunState::APPLYING, RunState::VERIFYING ) as $crashed ) {
			$this->assertTrue( $crashed->needsCrashRecovery() );

			$machine = new RunStateMachine( $crashed );
			$machine->transitionTo( RunState::INTERRUPTED );
			$machine->transitionTo( RunState::ROLLING_BACK );
			$machine->transitionTo( RunState::ROLLED_BACK );

			$this->assertSame( RunState::ROLLED_BACK, $machine->state() );
		}
	}

	/**
	 * Only APPLYING and VERIFYING leave the site partially changed.
	 *
	 * @return void
	 */
	public function test_only_applying_and_verifying_need_crash_recovery(): void {
		$needing = array_values(
			array_filter( RunState::cases(), static fn ( RunState $state ): bool => $state->needsCrashRecovery() )
		);

		$this->assertSame( array( RunState::APPLYING, RunState::VERIFYING ), $needing );
	}

	/**
	 * Nothing before APPLYING may reach a rollback: an aborted run changed
	 * nothing, so rolling it back would be meaningless work on live data.
	 *
	 * @return void
	 */
	public function test_pre_apply_failures_abort_rather_than_roll_back(): void {
		foreach ( array( RunState::PLANNING, RunState::PREVIEWED, RunState::LOCKED, RunState::MEASURING_BEFORE, RunState::SNAPSHOTTING ) as $state ) {
			$this->assertTrue( $state->canTransitionTo( RunState::ABORTED ), $state->value . ' must be able to abort' );
			$this->assertFalse(
				$state->canTransitionTo( RunState::ROLLING_BACK ),
				$state->value . ' must not roll back; nothing was changed yet'
			);
		}

		$this->assertTrue( RunState::ABORTED->isTerminal() );
	}

	/**
	 * The lock is held from LOCKED until the run settles, and never before or
	 * after.
	 *
	 * @return void
	 */
	public function test_lock_is_held_only_while_the_run_is_in_flight(): void {
		foreach ( array( RunState::IDLE, RunState::PLANNING, RunState::PREVIEWED, RunState::COMMITTED, RunState::ABORTED, RunState::ROLLED_BACK ) as $state ) {
			$this->assertFalse( $state->holdsLock(), $state->value . ' must not hold the lock' );
		}

		foreach ( array( RunState::LOCKED, RunState::SNAPSHOTTING, RunState::APPLYING, RunState::VERIFYING, RunState::ROLLING_BACK ) as $state ) {
			$this->assertTrue( $state->holdsLock(), $state->value . ' must hold the lock' );
		}
	}

	/**
	 * Terminal states really are terminal.
	 *
	 * @return void
	 */
	public function test_terminal_states(): void {
		$terminal = array_values(
			array_filter( RunState::cases(), static fn ( RunState $state ): bool => $state->isTerminal() )
		);

		$this->assertSame( array( RunState::COMMITTED, RunState::ABORTED ), $terminal );
	}

	/**
	 * The published table covers every state exactly once.
	 *
	 * @return void
	 */
	public function test_transition_table_covers_every_state(): void {
		$table = RunStateMachine::transitionTable();

		$this->assertCount( count( RunState::cases() ), $table );

		foreach ( RunState::cases() as $state ) {
			$this->assertArrayHasKey( $state->value, $table );
		}
	}

	/**
	 * Every state named as a successor is itself a real state.
	 *
	 * @return void
	 */
	public function test_transition_table_has_no_dangling_successors(): void {
		$known = array_map( static fn ( RunState $state ): string => $state->value, RunState::cases() );

		foreach ( RunStateMachine::transitionTable() as $from => $successors ) {
			foreach ( $successors as $to ) {
				$this->assertContains( $to, $known, sprintf( '%s -> %s names an unknown state', $from, $to ) );
			}
		}
	}

	/**
	 * Every non-terminal state can reach a terminal one, so no run can be
	 * stranded.
	 *
	 * @return void
	 */
	public function test_every_state_can_reach_a_terminal_state(): void {
		foreach ( RunState::cases() as $state ) {
			$this->assertTrue(
				$this->reachesTerminal( $state, array() ),
				sprintf( '%s cannot reach a terminal state', $state->value )
			);
		}
	}

	/**
	 * Legal transitions, derived from the enum.
	 *
	 * @return array<string,array{0:RunState,1:RunState}>
	 */
	public static function legalTransitionProvider(): array {
		$cases = array();

		foreach ( RunState::cases() as $from ) {
			foreach ( $from->allowedNext() as $to ) {
				$cases[ $from->value . ' -> ' . $to->value ] = array( $from, $to );
			}
		}

		return $cases;
	}

	/**
	 * Illegal transitions: the complement of the table.
	 *
	 * @return array<string,array{0:RunState,1:RunState}>
	 */
	public static function illegalTransitionProvider(): array {
		$cases = array();

		foreach ( RunState::cases() as $from ) {
			foreach ( RunState::cases() as $to ) {
				if ( $from->canTransitionTo( $to ) ) {
					continue;
				}

				$cases[ $from->value . ' -x-> ' . $to->value ] = array( $from, $to );
			}
		}

		return $cases;
	}

	/**
	 * Whether a terminal state is reachable from the given state.
	 *
	 * @param RunState          $state   State to explore from.
	 * @param array<int,string> $visited States already visited.
	 * @return bool
	 */
	private function reachesTerminal( RunState $state, array $visited ): bool {
		if ( $state->isTerminal() ) {
			return true;
		}

		if ( in_array( $state->value, $visited, true ) ) {
			return false;
		}

		$visited[] = $state->value;

		foreach ( $state->allowedNext() as $next ) {
			if ( $this->reachesTerminal( $next, $visited ) ) {
				return true;
			}
		}

		return false;
	}
}
