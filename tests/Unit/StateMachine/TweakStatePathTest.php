<?php
/**
 * Finding a legal route between two tweak states.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use WPDebloat\Apply\TweakStateMachine;
use WPDebloat\Contracts\TweakState;

/**
 * BUILD-SPEC §9.1.
 *
 * `pathTo()` exists so that no caller has to hard-code an edge. These tests
 * treat the transition table as the only authority: every path it returns is
 * walked through a real machine, which throws on an illegal transition, so a
 * path that the table does not permit cannot pass here.
 */
final class TweakStatePathTest extends TestCase {

	/**
	 * A path to the current state is empty rather than absent.
	 *
	 * @return void
	 */
	public function test_the_path_to_where_you_already_are_is_empty(): void {
		$machine = new TweakStateMachine( TweakState::APPLIED );

		$this->assertSame( array(), $machine->pathTo( TweakState::APPLIED ) );
	}

	/**
	 * The happy path is found, and is the direct one.
	 *
	 * @return void
	 */
	public function test_the_route_from_snapshotted_to_committed(): void {
		$machine = new TweakStateMachine( TweakState::SNAPSHOTTED );

		$this->assertSame(
			array( TweakState::APPLIED, TweakState::VERIFIED, TweakState::COMMITTED ),
			$machine->pathTo( TweakState::COMMITTED )
		);
	}

	/**
	 * A tweak that failed to apply reaches ROLLED_BACK through APPLY_FAILED, and
	 * one that failed verification through VERIFICATION_FAILED. Neither invents
	 * a direct edge.
	 *
	 * @return void
	 */
	public function test_the_two_failure_routes_to_rolled_back(): void {
		$this->assertSame(
			array( TweakState::APPLY_FAILED, TweakState::ROLLED_BACK ),
			( new TweakStateMachine( TweakState::SNAPSHOTTED ) )->pathTo( TweakState::ROLLED_BACK )
		);

		$this->assertSame(
			array( TweakState::VERIFICATION_FAILED, TweakState::ROLLED_BACK ),
			( new TweakStateMachine( TweakState::APPLIED ) )->pathTo( TweakState::ROLLED_BACK )
		);
	}

	/**
	 * Undoing a committed tweak goes through REVERT_REQUESTED, which is what
	 * §9.1 calls the manual undo.
	 *
	 * @return void
	 */
	public function test_undoing_a_committed_tweak_records_the_request(): void {
		$this->assertSame(
			array( TweakState::REVERT_REQUESTED, TweakState::ROLLED_BACK ),
			( new TweakStateMachine( TweakState::COMMITTED ) )->pathTo( TweakState::ROLLED_BACK )
		);
	}

	/**
	 * There is no way out of a terminal state, and saying so is not the same as
	 * returning an empty path.
	 *
	 * @return void
	 */
	public function test_there_is_no_route_out_of_a_terminal_state(): void {
		$this->assertNull( ( new TweakStateMachine( TweakState::ROLLED_BACK ) )->pathTo( TweakState::APPLIED ) );
		$this->assertNull( ( new TweakStateMachine( TweakState::DONT_TOUCH ) )->pathTo( TweakState::COMMITTED ) );
	}

	/**
	 * The lifecycle never runs backwards: nothing leads back to DISCOVERED.
	 *
	 * @return void
	 */
	public function test_nothing_leads_back_to_the_beginning(): void {
		foreach ( TweakState::cases() as $state ) {
			if ( TweakState::DISCOVERED === $state ) {
				continue;
			}

			$this->assertNull(
				( new TweakStateMachine( $state ) )->pathTo( TweakState::DISCOVERED ),
				sprintf( '%s should not be able to return to DISCOVERED.', $state->value )
			);
		}
	}

	/**
	 * Every path the table offers can actually be walked.
	 *
	 * The machine throws IllegalTransition on an edge outside the table, so
	 * walking every pair proves that pathTo() never returns a route the machine
	 * would refuse — which is the whole reason it exists.
	 *
	 * @return void
	 */
	public function test_every_path_offered_is_one_the_machine_accepts(): void {
		$walked = 0;

		foreach ( TweakState::cases() as $from ) {
			foreach ( TweakState::cases() as $to ) {
				$path = ( new TweakStateMachine( $from ) )->pathTo( $to );

				if ( null === $path || array() === $path ) {
					continue;
				}

				$machine = new TweakStateMachine( $from );

				foreach ( $path as $next ) {
					$machine->transitionTo( $next );
				}

				$this->assertSame(
					$to,
					$machine->state(),
					sprintf( 'Walking the path from %s did not arrive at %s.', $from->value, $to->value )
				);

				++$walked;
			}
		}

		$this->assertGreaterThan( 20, $walked, 'The table should offer many more routes than this.' );
	}

	/**
	 * Each route is the shortest one available.
	 *
	 * @return void
	 */
	public function test_each_route_is_the_shortest_available(): void {
		foreach ( TweakState::cases() as $from ) {
			foreach ( TweakState::cases() as $to ) {
				$path = ( new TweakStateMachine( $from ) )->pathTo( $to );

				if ( null === $path || count( $path ) < 2 ) {
					continue;
				}

				// If a shorter route existed, some state on this path would be
				// reachable from the start in fewer steps than it takes here.
				foreach ( $path as $index => $state ) {
					$this->assertCount(
						$index + 1,
						(array) ( new TweakStateMachine( $from ) )->pathTo( $state ),
						sprintf( 'The route from %s to %s is not the shortest one.', $from->value, $to->value )
					);
				}
			}
		}
	}
}
