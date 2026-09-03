<?php
/**
 * What the analyzer decided about a finding.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Analyzer decision for a finding (BUILD-SPEC §6, locked decision #6).
 *
 * DONT_TOUCH is a first-class outcome, not an absence of one. Such findings are
 * shown and counted separately ("No action recommended") and can never enter a
 * plan (BUILD-SPEC §7.4).
 */
enum Decision: string {

	case RECOMMEND  = 'recommend';
	case DONT_TOUCH = 'dont_touch';
	case INFO       = 'info';

	/**
	 * Whether a finding with this decision may contribute a tweak to a plan.
	 *
	 * @return bool
	 */
	public function isPlannable(): bool {
		return self::RECOMMEND === $this;
	}

	/**
	 * Whether this decision requires a decision_reason (BUILD-SPEC §6).
	 *
	 * @return bool
	 */
	public function requiresReason(): bool {
		return self::DONT_TOUCH === $this;
	}

	/**
	 * Whether a finding with this decision must carry a recommendation.
	 *
	 * Info findings have no recommendation. Dont-touch findings may name the
	 * tweak they are refusing, so the UI can explain what was declined.
	 *
	 * @return bool
	 */
	public function requiresRecommendation(): bool {
		return self::RECOMMEND === $this;
	}

	/**
	 * Whether a finding with this decision is allowed to carry a recommendation.
	 *
	 * @return bool
	 */
	public function allowsRecommendation(): bool {
		return self::INFO !== $this;
	}
}
