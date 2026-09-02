<?php
/**
 * The apply-run state machine.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply;

use WPDebloat\Contracts\IllegalTransition;
use WPDebloat\Contracts\RunState;

/**
 * Drives one run through the states in BUILD-SPEC §9.2.
 *
 * The machine holds the current state and refuses anything the transition table
 * does not allow. It knows nothing about snapshots, files or the database; a
 * caller performs the work for a step and then asks the machine to record it.
 * That separation is what lets the whole table be tested without WordPress.
 *
 * Every transition is appended to a history so a run can be replayed in the UI
 * and so crash recovery can see how far a run had got.
 */
final class RunStateMachine {

	/**
	 * Name used in exception messages.
	 */
	private const MACHINE = 'RunStateMachine';

	/**
	 * Current state.
	 *
	 * @var RunState
	 */
	private RunState $state;

	/**
	 * Ordered list of states visited, starting with the initial state.
	 *
	 * @var array<int,RunState>
	 */
	private array $history;

	/**
	 * Constructor.
	 *
	 * @param RunState $state Initial state.
	 */
	public function __construct( RunState $state = RunState::IDLE ) {
		$this->state   = $state;
		$this->history = array( $state );
	}

	/**
	 * The current state.
	 *
	 * @return RunState
	 */
	public function state(): RunState {
		return $this->state;
	}

	/**
	 * States visited so far, in order.
	 *
	 * @return array<int,RunState>
	 */
	public function history(): array {
		return $this->history;
	}

	/**
	 * Whether a transition to the given state is currently legal.
	 *
	 * @param RunState $next Candidate state.
	 * @return bool
	 */
	public function can( RunState $next ): bool {
		return $this->state->canTransitionTo( $next );
	}

	/**
	 * Move to the given state.
	 *
	 * @param RunState $next Target state.
	 * @return RunState The new state.
	 * @throws IllegalTransition When the transition is not in the table.
	 */
	public function transitionTo( RunState $next ): RunState {
		if ( ! $this->state->canTransitionTo( $next ) ) {
			throw new IllegalTransition(
				self::MACHINE,
				$this->state->value,
				$next->value,
				array_map( static fn ( RunState $state ): string => $state->value, $this->state->allowedNext() )
			);
		}

		$this->state     = $next;
		$this->history[] = $next;

		return $next;
	}

	/**
	 * Whether the run has reached a state with no successor.
	 *
	 * @return bool
	 */
	public function isTerminal(): bool {
		return $this->state->isTerminal();
	}

	/**
	 * Whether the run currently holds the apply lock.
	 *
	 * @return bool
	 */
	public function holdsLock(): bool {
		return $this->state->holdsLock();
	}

	/**
	 * The full transition table, as a map of state value to sorted successors.
	 *
	 * Used by the documentation test that renders docs/STATE-MACHINE.md, so the
	 * document can never drift from the enum.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function transitionTable(): array {
		$table = array();

		foreach ( RunState::cases() as $state ) {
			$successors = array_map(
				static fn ( RunState $next ): string => $next->value,
				$state->allowedNext()
			);

			$table[ $state->value ] = $successors;
		}

		return $table;
	}
}
