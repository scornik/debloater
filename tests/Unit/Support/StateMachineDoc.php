<?php
/**
 * Renders docs/STATE-MACHINE.md from the state enums.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Support;

use WPDebloat\Apply\RunStateMachine;
use WPDebloat\Apply\TweakStateMachine;
use WPDebloat\Contracts\RunState;
use WPDebloat\Contracts\TweakState;

/**
 * The document is generated, never hand-edited.
 *
 * BUILD-SPEC §9 requires the transition table in docs/STATE-MACHINE.md to be
 * derived from the enums so it cannot drift. Keeping the renderer in the test
 * support tree rather than in src/ means the shipped plugin carries no
 * documentation-generation code.
 */
final class StateMachineDoc {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Render the complete document.
	 *
	 * @return string
	 */
	public static function render(): string {
		$lines = array(
			'# State machines',
			'',
			'<!-- Generated from the RunState and TweakState enums by',
			'     tests/Unit/StateMachine/StateMachineDocTest.php. Do not edit by hand:',
			'     change the enum and run the test suite. -->',
			'',
			'Two state machines govern WP Debloat, and they are deliberately separate.',
			'`RunState` tracks what a run is doing; `TweakState` tracks where each',
			'individual tweak stands. One run legitimately ends with some tweaks',
			'`COMMITTED` and others parked at `DONT_TOUCH`, which a single machine could',
			'not express.',
			'',
			'Illegal transitions throw `WPDebloat\\Contracts\\IllegalTransition`. That is',
			'fatal rather than a returned `false` because the machine governs',
			'snapshotting, applying and rollback: a caller that has lost track of where it',
			'is must stop, not carry on guessing, when the next step might write to the',
			'filesystem or delete rows.',
			'',
		);

		$lines = array_merge( $lines, self::runSection(), self::tweakSection() );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The apply-run section.
	 *
	 * @return array<int,string>
	 */
	private static function runSection(): array {
		$lines = array(
			'## Apply run (`RunState`)',
			'',
			'The run state is persisted in `wpdebloat_runs.status` and updated on every',
			'transition, so a crash leaves an accurate record of how far the run got.',
			'',
			'| State | Allowed next | Holds lock | Terminal |',
			'|---|---|---|---|',
		);

		foreach ( RunState::cases() as $state ) {
			$next = RunStateMachine::transitionTable()[ $state->value ];

			$lines[] = sprintf(
				'| `%s` | %s | %s | %s |',
				$state->value,
				array() === $next ? '—' : '`' . implode( '`, `', $next ) . '`',
				$state->holdsLock() ? 'yes' : 'no',
				$state->isTerminal() ? 'yes' : 'no'
			);
		}

		$lines[] = '';
		$lines[] = '### Notes';
		$lines[] = '';
		$lines[] = '- `ABORTED` is reached by any failure before `APPLYING`. Nothing was changed,';
		$lines[] = '  so no rollback is required and the lock is released.';
		$lines[] = '- `INTERRUPTED` is set by crash recovery at boot for a run found in';
		$lines[] = '  `APPLYING` or `VERIFYING`. Such a run is rolled back automatically.';
		$lines[] = '- Failures during `MEASURING_BEFORE` and `MEASURING_AFTER` are warnings, not';
		$lines[] = '  transitions: the run continues and the warning is recorded on the result.';
		$lines[] = '- `ROLLED_BACK` returns to `IDLE` once the lock is released.';
		$lines[] = '';

		$recovery = array_filter( RunState::cases(), static fn ( RunState $state ): bool => $state->needsCrashRecovery() );

		$lines[] = sprintf(
			'States requiring crash recovery: %s.',
			'`' . implode(
				'`, `',
				array_map( static fn ( RunState $state ): string => $state->value, $recovery )
			) . '`'
		);
		$lines[] = '';

		return $lines;
	}

	/**
	 * The tweak-lifecycle section.
	 *
	 * @return array<int,string>
	 */
	private static function tweakSection(): array {
		$lines = array(
			'## Tweak lifecycle (`TweakState`)',
			'',
			'Stored per tweak in `wpdebloat_state.tweak_states`. Every transition writes a',
			'row to `wpdebloat_journal`.',
			'',
			'| State | Allowed next | Terminal |',
			'|---|---|---|',
		);

		foreach ( TweakState::cases() as $state ) {
			$next = TweakStateMachine::transitionTable()[ $state->value ];

			$lines[] = sprintf(
				'| `%s` | %s | %s |',
				$state->value,
				array() === $next ? '—' : '`' . implode( '`, `', $next ) . '`',
				$state->isTerminal() ? 'yes' : 'no'
			);
		}

		$lines[] = '';
		$lines[] = '### Notes';
		$lines[] = '';
		$lines[] = '- `DONT_TOUCH` is terminal for this run. It is a decision, not a failure,';
		$lines[] = '  and it is shown to the user as "No action recommended".';
		$lines[] = '- `COMMITTED` is the only state in which a tweak is in effect on the site.';
		$lines[] = '- A committed tweak is undone through `REVERT_REQUESTED` so that a manual';
		$lines[] = '  undo is journalled exactly like an automatic rollback.';
		$lines[] = '';

		return $lines;
	}

	/**
	 * Absolute path of the generated document.
	 *
	 * @return string
	 */
	public static function path(): string {
		return WPDEBLOAT_TESTS_ROOT . '/docs/STATE-MACHINE.md';
	}
}
