<?php
/**
 * Moves a tweak through its lifecycle, and writes down every step.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply;

use WPDebloat\Contracts\JournalAction;
use WPDebloat\Contracts\TweakParams;
use WPDebloat\Contracts\TweakState;
use WPDebloat\Journal\Journal;
use WPDebloat\Storage\State;

/**
 * The bridge between the §9.1 table, the stored tweak states and the journal.
 *
 * BUILD-SPEC §9.1 ends with "every transition writes a journal row", and the
 * only way to keep that true is to have one place that does both. Callers say
 * where a tweak should end up; this works out how it gets there by asking the
 * transition table, walks it one legal edge at a time, and journals each edge.
 *
 * The alternative — each caller writing `journal->applied( $id, APPLIED,
 * ROLLED_BACK )` from memory — produces a journal full of transitions the state
 * machine would have rejected, which is worse than no journal: it reads as
 * authoritative and is not.
 */
final class TweakLifecycle {

	/**
	 * Plugin state.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Transition record.
	 *
	 * @var Journal
	 */
	private Journal $journal;

	/**
	 * Constructor.
	 *
	 * @param State   $state   Plugin state.
	 * @param Journal $journal Transition record.
	 */
	public function __construct( State $state, Journal $journal ) {
		$this->state   = $state;
		$this->journal = $journal;
	}

	/**
	 * The state a tweak is currently in.
	 *
	 * @param string     $tweak_id Tweak id.
	 * @param TweakState $fallback State to assume when none is stored.
	 * @return TweakState
	 */
	public function current( string $tweak_id, TweakState $fallback = TweakState::DISCOVERED ): TweakState {
		return $this->state->tweakStates()[ $tweak_id ] ?? $fallback;
	}

	/**
	 * Move a tweak to the given state, journalling every step of the way.
	 *
	 * @param int              $run_id   Run this belongs to.
	 * @param string           $tweak_id Tweak id.
	 * @param TweakState       $target   Where it should end up.
	 * @param JournalAction    $action   What was being attempted.
	 * @param TweakParams|null $params   Parameters in force, when relevant.
	 * @param TweakState|null  $from     State to start from; defaults to the stored one.
	 * @return TweakState The state actually reached.
	 */
	public function advance(
		int $run_id,
		string $tweak_id,
		TweakState $target,
		JournalAction $action = JournalAction::APPLY,
		?TweakParams $params = null,
		?TweakState $from = null
	): TweakState {
		$start   = $from ?? $this->current( $tweak_id, TweakState::SELECTED );
		$machine = new TweakStateMachine( $start );
		$path    = $machine->pathTo( $target );

		if ( null === $path ) {
			// No legal route. The stored state stands, and the attempt is
			// recorded as a skip rather than forced through.
			$this->journal->skipped( $run_id, $tweak_id, $start );

			return $start;
		}

		$previous = $start;

		foreach ( $path as $next ) {
			$machine->transitionTo( $next );

			$this->journal->record( $run_id, $tweak_id, $action, $previous, $next, $params );

			$previous = $next;
		}

		if ( array() !== $path ) {
			$this->state->setTweakState( $tweak_id, $target );
		}

		return $target;
	}

	/**
	 * Move several tweaks to the same state.
	 *
	 * @param int               $run_id    Run this belongs to.
	 * @param array<int,string> $tweak_ids Tweak ids.
	 * @param TweakState        $target    Where they should end up.
	 * @param JournalAction     $action    What was being attempted.
	 * @return void
	 */
	public function advanceAll(
		int $run_id,
		array $tweak_ids,
		TweakState $target,
		JournalAction $action = JournalAction::APPLY
	): void {
		foreach ( $tweak_ids as $tweak_id ) {
			$this->advance( $run_id, $tweak_id, $target, $action );
		}
	}

	/**
	 * The states a set of tweaks are currently in.
	 *
	 * Taken before a rollback restores the recorded states, so that the journal
	 * can describe the route each tweak actually travelled rather than the route
	 * it would have travelled from where the restore put it back.
	 *
	 * @param array<int,string> $tweak_ids Tweak ids.
	 * @return array<string,TweakState>
	 */
	public function statesOf( array $tweak_ids ): array {
		$stored = $this->state->tweakStates();
		$states = array();

		foreach ( $tweak_ids as $tweak_id ) {
			$states[ $tweak_id ] = $stored[ $tweak_id ] ?? TweakState::SELECTED;
		}

		return $states;
	}

	/**
	 * Move several tweaks to the same state, from where each of them was.
	 *
	 * @param int                      $run_id Run this belongs to.
	 * @param array<string,TweakState> $from   Tweak id to the state it was in.
	 * @param TweakState               $target Where they should end up.
	 * @param JournalAction            $action What was being attempted.
	 * @return void
	 */
	public function advanceAllFrom(
		int $run_id,
		array $from,
		TweakState $target,
		JournalAction $action = JournalAction::APPLY
	): void {
		foreach ( $from as $tweak_id => $state ) {
			$this->advance( $run_id, $tweak_id, $target, $action, null, $state );
		}
	}
}
