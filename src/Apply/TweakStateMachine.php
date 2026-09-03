<?php
/**
 * The per-tweak lifecycle state machine.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use Debloater\Contracts\IllegalTransition;
use Debloater\Contracts\TweakState;

/**
 * Drives one tweak through the lifecycle in BUILD-SPEC §9.1.
 *
 * The run state machine says what the run is doing; this says where each
 * individual tweak stands. They are separate because a single run legitimately
 * ends with some tweaks COMMITTED and others skipped at DONT_TOUCH.
 */
final class TweakStateMachine {

	/**
	 * Name used in exception messages.
	 */
	private const MACHINE = 'TweakStateMachine';

	/**
	 * Current state.
	 *
	 * @var TweakState
	 */
	private TweakState $state;

	/**
	 * Ordered list of states visited, starting with the initial state.
	 *
	 * @var array<int,TweakState>
	 */
	private array $history;

	/**
	 * Constructor.
	 *
	 * @param TweakState $state Initial state.
	 */
	public function __construct( TweakState $state = TweakState::DISCOVERED ) {
		$this->state   = $state;
		$this->history = array( $state );
	}

	/**
	 * The current state.
	 *
	 * @return TweakState
	 */
	public function state(): TweakState {
		return $this->state;
	}

	/**
	 * States visited so far, in order.
	 *
	 * @return array<int,TweakState>
	 */
	public function history(): array {
		return $this->history;
	}

	/**
	 * Whether a transition to the given state is currently legal.
	 *
	 * @param TweakState $next Candidate state.
	 * @return bool
	 */
	public function can( TweakState $next ): bool {
		return $this->state->canTransitionTo( $next );
	}

	/**
	 * Move to the given state.
	 *
	 * @param TweakState $next Target state.
	 * @return TweakState The new state.
	 * @throws IllegalTransition When the transition is not in the table.
	 */
	public function transitionTo( TweakState $next ): TweakState {
		if ( ! $this->state->canTransitionTo( $next ) ) {
			throw new IllegalTransition(
				self::MACHINE,
				$this->state->value,
				$next->value,
				array_map( static fn ( TweakState $state ): string => $state->value, $this->state->allowedNext() )
			);
		}

		$this->state     = $next;
		$this->history[] = $next;

		return $next;
	}

	/**
	 * The shortest legal sequence of transitions from here to the given state.
	 *
	 * Returned as the states to pass through, excluding the current one and
	 * including the target. An empty array means the current state is already
	 * the target; null means the table offers no way to get there.
	 *
	 * This exists so that callers never have to guess an edge. A tweak that
	 * failed mid-apply has to reach ROLLED_BACK, and how it gets there depends
	 * on where it had got to; asking the table is the only way to answer that
	 * without inventing transitions that §9.1 does not contain.
	 *
	 * @param TweakState $target Target state.
	 * @return array<int,TweakState>|null
	 */
	public function pathTo( TweakState $target ): ?array {
		if ( $this->state === $target ) {
			return array();
		}

		$queue   = array( array( $this->state, array() ) );
		$visited = array( $this->state->value => true );

		while ( array() !== $queue ) {
			/** @var array{0:TweakState,1:array<int,TweakState>} $entry */
			$entry = array_shift( $queue );

			foreach ( $entry[0]->allowedNext() as $next ) {
				if ( isset( $visited[ $next->value ] ) ) {
					continue;
				}

				$path   = $entry[1];
				$path[] = $next;

				if ( $next === $target ) {
					return $path;
				}

				$visited[ $next->value ] = true;
				$queue[]                 = array( $next, $path );
			}
		}

		return null;
	}

	/**
	 * The full transition table, as a map of state value to successors.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function transitionTable(): array {
		$table = array();

		foreach ( TweakState::cases() as $state ) {
			$table[ $state->value ] = array_map(
				static fn ( TweakState $next ): string => $next->value,
				$state->allowedNext()
			);
		}

		return $table;
	}
}
