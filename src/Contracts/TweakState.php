<?php
/**
 * Lifecycle state of a single tweak.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Tweak lifecycle (BUILD-SPEC §9.1).
 *
 * DISCOVERED -> ELIGIBLE -> RECOMMENDED -> SELECTED -> PREVIEWED -> SNAPSHOTTED
 * -> APPLIED -> VERIFIED -> COMMITTED, with DONT_TOUCH branching off ELIGIBLE and
 * the failure branches converging on ROLLED_BACK. Stored per tweak in
 * wpdebloat_state.tweak_states; every transition writes a journal row.
 */
enum TweakState: string {

	case DISCOVERED          = 'DISCOVERED';
	case ELIGIBLE            = 'ELIGIBLE';
	case DONT_TOUCH          = 'DONT_TOUCH';
	case RECOMMENDED         = 'RECOMMENDED';
	case SELECTED            = 'SELECTED';
	case PREVIEWED           = 'PREVIEWED';
	case SNAPSHOTTED         = 'SNAPSHOTTED';
	case APPLIED             = 'APPLIED';
	case APPLY_FAILED        = 'APPLY_FAILED';
	case VERIFIED            = 'VERIFIED';
	case VERIFICATION_FAILED = 'VERIFICATION_FAILED';
	case COMMITTED           = 'COMMITTED';
	case REVERT_REQUESTED    = 'REVERT_REQUESTED';
	case ROLLED_BACK         = 'ROLLED_BACK';

	/**
	 * States this state may transition to.
	 *
	 * @return array<int,self>
	 */
	public function allowedNext(): array {
		return match ( $this ) {
			self::DISCOVERED          => array( self::ELIGIBLE, self::DONT_TOUCH ),
			self::ELIGIBLE            => array( self::RECOMMENDED, self::DONT_TOUCH ),
			self::RECOMMENDED         => array( self::SELECTED, self::DONT_TOUCH ),
			self::SELECTED            => array( self::PREVIEWED ),
			self::PREVIEWED           => array( self::SNAPSHOTTED ),
			self::SNAPSHOTTED         => array( self::APPLIED, self::APPLY_FAILED ),
			self::APPLIED             => array( self::VERIFIED, self::VERIFICATION_FAILED ),
			self::APPLY_FAILED        => array( self::ROLLED_BACK ),
			self::VERIFIED            => array( self::COMMITTED ),
			self::VERIFICATION_FAILED => array( self::ROLLED_BACK ),
			self::COMMITTED           => array( self::REVERT_REQUESTED ),
			self::REVERT_REQUESTED    => array( self::ROLLED_BACK ),
			self::DONT_TOUCH          => array(),
			self::ROLLED_BACK         => array(),
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
	 * Whether this is a terminal state with no legal successor.
	 *
	 * @return bool
	 */
	public function isTerminal(): bool {
		return array() === $this->allowedNext();
	}

	/**
	 * Whether the tweak is currently in effect on the site.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return self::COMMITTED === $this;
	}
}
