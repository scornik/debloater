<?php
/**
 * Tests for the per-tweak lifecycle machine.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use WPDebloat\Apply\TweakStateMachine;
use WPDebloat\Contracts\IllegalTransition;
use WPDebloat\Contracts\TweakState;

/**
 * The lifecycle in BUILD-SPEC §9.1, exercised in full.
 */
final class TweakStateMachineTest extends TestCase {

	/**
	 * A tweak starts as discovered.
	 *
	 * @return void
	 */
	public function test_a_new_tweak_is_discovered(): void {
		$machine = new TweakStateMachine();

		$this->assertSame( TweakState::DISCOVERED, $machine->state() );
	}

	/**
	 * The complete happy path from discovery to committed.
	 *
	 * @return void
	 */
	public function test_the_happy_path_reaches_committed(): void {
		$machine = new TweakStateMachine();

		foreach (
			array(
				TweakState::ELIGIBLE,
				TweakState::RECOMMENDED,
				TweakState::SELECTED,
				TweakState::PREVIEWED,
				TweakState::SNAPSHOTTED,
				TweakState::APPLIED,
				TweakState::VERIFIED,
				TweakState::COMMITTED,
			) as $state
		) {
			$machine->transitionTo( $state );
		}

		$this->assertSame( TweakState::COMMITTED, $machine->state() );
		$this->assertTrue( $machine->state()->isActive() );
	}

	/**
	 * A committed tweak is undone through an explicit revert request, so a
	 * manual undo is journalled exactly like an automatic rollback.
	 *
	 * @return void
	 */
	public function test_manual_undo_goes_through_revert_requested(): void {
		$machine = new TweakStateMachine( TweakState::COMMITTED );

		$machine->transitionTo( TweakState::REVERT_REQUESTED );
		$machine->transitionTo( TweakState::ROLLED_BACK );

		$this->assertSame( TweakState::ROLLED_BACK, $machine->state() );
		$this->assertTrue( $machine->state()->isTerminal() );
	}

	/**
	 * A committed tweak cannot jump straight to rolled back.
	 *
	 * @return void
	 */
	public function test_committed_cannot_skip_the_revert_request(): void {
		$machine = new TweakStateMachine( TweakState::COMMITTED );

		$this->expectException( IllegalTransition::class );

		$machine->transitionTo( TweakState::ROLLED_BACK );
	}

	/**
	 * Dont-touch is terminal: a refusal is not reconsidered inside one run.
	 *
	 * @return void
	 */
	public function test_dont_touch_is_terminal(): void {
		$this->assertTrue( TweakState::DONT_TOUCH->isTerminal() );

		$machine = new TweakStateMachine( TweakState::DONT_TOUCH );

		$this->expectException( IllegalTransition::class );

		$machine->transitionTo( TweakState::RECOMMENDED );
	}

	/**
	 * A tweak may be refused at any point up to being recommended, but not
	 * after it has been selected for a plan.
	 *
	 * @return void
	 */
	public function test_dont_touch_is_reachable_only_before_selection(): void {
		foreach ( array( TweakState::DISCOVERED, TweakState::ELIGIBLE, TweakState::RECOMMENDED ) as $state ) {
			$this->assertTrue( $state->canTransitionTo( TweakState::DONT_TOUCH ), $state->value );
		}

		foreach ( array( TweakState::SELECTED, TweakState::PREVIEWED, TweakState::APPLIED, TweakState::COMMITTED ) as $state ) {
			$this->assertFalse( $state->canTransitionTo( TweakState::DONT_TOUCH ), $state->value );
		}
	}

	/**
	 * Only a committed tweak is in effect on the site.
	 *
	 * @return void
	 */
	public function test_only_committed_is_active(): void {
		foreach ( TweakState::cases() as $state ) {
			$this->assertSame( TweakState::COMMITTED === $state, $state->isActive(), $state->value );
		}
	}

	/**
	 * Every legal transition is accepted.
	 *
	 * @dataProvider legalTransitionProvider
	 * @param TweakState $from Starting state.
	 * @param TweakState $to   Target state.
	 * @return void
	 */
	public function test_legal_transitions_are_accepted( TweakState $from, TweakState $to ): void {
		$machine = new TweakStateMachine( $from );

		$this->assertSame( $to, $machine->transitionTo( $to ) );
	}

	/**
	 * Every transition outside the table throws and leaves the state untouched.
	 *
	 * @dataProvider illegalTransitionProvider
	 * @param TweakState $from Starting state.
	 * @param TweakState $to   Target state.
	 * @return void
	 */
	public function test_illegal_transitions_throw( TweakState $from, TweakState $to ): void {
		$machine = new TweakStateMachine( $from );

		try {
			$machine->transitionTo( $to );
			$this->fail( sprintf( '%s -> %s should be illegal', $from->value, $to->value ) );
		} catch ( IllegalTransition $exception ) {
			$this->assertSame( $from->value, $exception->from() );
			$this->assertSame( $from, $machine->state() );
		}
	}

	/**
	 * Every state named as a successor is a real state.
	 *
	 * @return void
	 */
	public function test_transition_table_has_no_dangling_successors(): void {
		$known = array_map( static fn ( TweakState $state ): string => $state->value, TweakState::cases() );

		foreach ( TweakStateMachine::transitionTable() as $from => $successors ) {
			foreach ( $successors as $to ) {
				$this->assertContains( $to, $known, sprintf( '%s -> %s names an unknown state', $from, $to ) );
			}
		}
	}

	/**
	 * Legal transitions, derived from the enum.
	 *
	 * @return array<string,array{0:TweakState,1:TweakState}>
	 */
	public static function legalTransitionProvider(): array {
		$cases = array();

		foreach ( TweakState::cases() as $from ) {
			foreach ( $from->allowedNext() as $to ) {
				$cases[ $from->value . ' -> ' . $to->value ] = array( $from, $to );
			}
		}

		return $cases;
	}

	/**
	 * Illegal transitions: the complement of the table.
	 *
	 * @return array<string,array{0:TweakState,1:TweakState}>
	 */
	public static function illegalTransitionProvider(): array {
		$cases = array();

		foreach ( TweakState::cases() as $from ) {
			foreach ( TweakState::cases() as $to ) {
				if ( $from->canTransitionTo( $to ) ) {
					continue;
				}

				$cases[ $from->value . ' -x-> ' . $to->value ] = array( $from, $to );
			}
		}

		return $cases;
	}
}
