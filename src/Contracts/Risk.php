<?php
/**
 * How dangerous the recommended change is.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Risk of applying a change (BUILD-SPEC §6, locked decision #4).
 *
 * "Safe" means low blast radius and a clean revert path. It does not mean
 * "cannot break anything" — that is why Confidence is a separate dimension.
 */
enum Risk: string {

	case SAFE   = 'safe';
	case LOW    = 'low';
	case MEDIUM = 'medium';
	case HIGH   = 'high';

	/**
	 * Ordering rank, ascending from least to most risky.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ( $this ) {
			self::SAFE   => 0,
			self::LOW    => 1,
			self::MEDIUM => 2,
			self::HIGH   => 3,
		};
	}

	/**
	 * Whether this risk level is eligible for the "Fix Safe Issues" plan.
	 *
	 * Eligibility is necessary but not sufficient: BUILD-SPEC §7.4 also requires
	 * decision=recommend, destructive=false, resolved requires, and no conflict.
	 *
	 * @return bool
	 */
	public function isSafePlanEligible(): bool {
		return self::SAFE === $this || self::LOW === $this;
	}

	/**
	 * The next risk level up, saturating at HIGH.
	 *
	 * Used by RiskEngine (BUILD-SPEC §17 Phase 4) to raise risk one level when
	 * dependencies are detected or the host is unknown.
	 *
	 * @return self
	 */
	public function raised(): self {
		return match ( $this ) {
			self::SAFE   => self::LOW,
			self::LOW    => self::MEDIUM,
			self::MEDIUM => self::HIGH,
			self::HIGH   => self::HIGH,
		};
	}

	/**
	 * The greater of two risk levels.
	 *
	 * @param self $other Risk to compare against.
	 * @return self
	 */
	public function max( self $other ): self {
		return $this->rank() >= $other->rank() ? $this : $other;
	}
}
