<?php
/**
 * How much a finding matters.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Finding severity (BUILD-SPEC §6).
 *
 * Severity answers "how much does this matter?" and is independent of Risk,
 * which answers "how dangerous is the change we would make?" (locked decision
 * #4). A high-severity finding may still carry a dont_touch decision.
 */
enum Severity: string {

	case INFO   = 'info';
	case LOW    = 'low';
	case MEDIUM = 'medium';
	case HIGH   = 'high';

	/**
	 * Score penalty contributed by a finding of this severity (BUILD-SPEC §12).
	 *
	 * @return int
	 */
	public function penalty(): int {
		return match ( $this ) {
			self::INFO   => 0,
			self::LOW    => 4,
			self::MEDIUM => 10,
			self::HIGH   => 20,
		};
	}

	/**
	 * Ordering rank, ascending from least to most severe.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ( $this ) {
			self::INFO   => 0,
			self::LOW    => 1,
			self::MEDIUM => 2,
			self::HIGH   => 3,
		};
	}
}
