<?php
/**
 * The per-tweak lifecycle state machine.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply;

use WPDebloat\Contracts\IllegalTransition;
use WPDebloat\Contracts\TweakState;

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
