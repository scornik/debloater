<?php
/**
 * Contract for a single analyzer rule.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * One rule, one finding id (BUILD-SPEC §17 Phase 3).
 *
 * A rule reads facts and produces at most one Finding. It changes nothing, and
 * it does not decide whether the finding will be acted on — that is the
 * Recommendation Engine's job. A rule may still return a dont_touch finding when
 * the facts themselves make the change inadvisable.
 */
interface AnalyzerRuleInterface {

	/**
	 * The finding id this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string;

	/**
	 * Whether this rule can evaluate the given facts.
	 *
	 * Returning false means "the facts this rule needs are not present", not
	 * "there is nothing wrong"; a rule that cannot see is not a rule that passed.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return bool
	 */
	public function supports( FactSet $facts ): bool;

	/**
	 * Evaluate the facts, returning a finding or null when the rule does not fire.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding;
}
