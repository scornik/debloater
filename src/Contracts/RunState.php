<?php
/**
 * State of an apply run.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Apply-run state (BUILD-SPEC §9.2).
 *
 * The transition table below is the literal reading of the diagram in §9.2. It
 * is rendered into docs/STATE-MACHINE.md by a test, so the document can never
 * drift from this enum.
 *
 * Two states exist outside the happy path:
 *
 * - ABORTED: any failure before APPLYING. Nothing was changed, the lock is
 *   released, and no rollback is needed.
 * - INTERRUPTED: a run found still in APPLYING or VERIFYING at boot. Crash
 *   recovery marks it INTERRUPTED and rolls it back.
 *
 * Failures in MEASURING_BEFORE and MEASURING_AFTER are warnings, not
 * transitions: the run continues along the happy path and the warning is
 * recorded on the run.
 */
enum RunState: string {

	case IDLE                   = 'IDLE';
	case PLANNING               = 'PLANNING';
	case PREVIEWED              = 'PREVIEWED';
	case LOCKED                 = 'LOCKED';
	case MEASURING_BEFORE       = 'MEASURING_BEFORE';
	case SNAPSHOTTING           = 'SNAPSHOTTING';
	case APPLYING               = 'APPLYING';
	case APPLIED                = 'APPLIED';
	case APPLY_FAILED           = 'APPLY_FAILED';
	case VERIFYING              = 'VERIFYING';
	case VERIFIED               = 'VERIFIED';
	case VERIFIED_WITH_WARNINGS = 'VERIFIED_WITH_WARNINGS';
	case VERIFICATION_FAILED    = 'VERIFICATION_FAILED';
	case MEASURING_AFTER        = 'MEASURING_AFTER';
	case COMMITTED              = 'COMMITTED';
	case ROLLING_BACK           = 'ROLLING_BACK';
	case ROLLED_BACK            = 'ROLLED_BACK';
	case ABORTED                = 'ABORTED';
	case INTERRUPTED            = 'INTERRUPTED';

	/**
	 * States this state may transition to.
	 *
	 * @return array<int,self>
	 */
	public function allowedNext(): array {
		return match ( $this ) {
			self::IDLE                   => array( self::PLANNING ),
			self::PLANNING               => array( self::PREVIEWED, self::ABORTED ),
			self::PREVIEWED              => array( self::LOCKED, self::ABORTED ),
			self::LOCKED                 => array( self::MEASURING_BEFORE, self::ABORTED ),
			self::MEASURING_BEFORE       => array( self::SNAPSHOTTING, self::ABORTED ),
			self::SNAPSHOTTING           => array( self::APPLYING, self::ABORTED ),
			self::APPLYING               => array( self::APPLIED, self::APPLY_FAILED, self::INTERRUPTED ),
			self::APPLIED                => array( self::VERIFYING ),
			self::APPLY_FAILED           => array( self::ROLLING_BACK ),
			self::VERIFYING              => array(
				self::VERIFIED,
				self::VERIFIED_WITH_WARNINGS,
				self::VERIFICATION_FAILED,
				self::INTERRUPTED,
			),
			self::VERIFIED               => array( self::MEASURING_AFTER ),
			self::VERIFIED_WITH_WARNINGS => array( self::MEASURING_AFTER ),
			self::VERIFICATION_FAILED    => array( self::ROLLING_BACK ),
			self::MEASURING_AFTER        => array( self::COMMITTED ),
			self::INTERRUPTED            => array( self::ROLLING_BACK ),
			self::ROLLING_BACK           => array( self::ROLLED_BACK ),
			self::ROLLED_BACK            => array( self::IDLE ),
			self::COMMITTED              => array(),
			self::ABORTED                => array(),
		};
	}

	/**
	 * Whether a transition to the given state is legal.
	 *
	 * @param self $next Candidate next state.
	 * @return bool
	 */
	public function canTransitionTo( self $next ): bool {
		return in_array( $next, $this->allowedNext(), true );
	}

	/**
	 * Whether this state has no legal successor.
	 *
	 * @return bool
	 */
	public function isTerminal(): bool {
		return array() === $this->allowedNext();
	}

	/**
	 * Whether the run holds the apply lock while in this state.
	 *
	 * The lock is acquired on entering LOCKED and released on reaching a
	 * settled state (COMMITTED, ABORTED or ROLLED_BACK).
	 *
	 * @return bool
	 */
	public function holdsLock(): bool {
		return match ( $this ) {
			self::LOCKED,
			self::MEASURING_BEFORE,
			self::SNAPSHOTTING,
			self::APPLYING,
			self::APPLIED,
			self::APPLY_FAILED,
			self::VERIFYING,
			self::VERIFIED,
			self::VERIFIED_WITH_WARNINGS,
			self::VERIFICATION_FAILED,
			self::MEASURING_AFTER,
			self::INTERRUPTED,
			self::ROLLING_BACK => true,
			default            => false,
		};
	}

	/**
	 * Whether a crash in this state leaves the site partially changed.
	 *
	 * A run found in one of these states at boot is marked INTERRUPTED and
	 * rolled back (BUILD-SPEC §9.2).
	 *
	 * @return bool
	 */
	public function needsCrashRecovery(): bool {
		return self::APPLYING === $this || self::VERIFYING === $this;
	}
}
