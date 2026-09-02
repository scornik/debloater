<?php
/**
 * Shared behaviour for analyzer rules.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze\Rules;

use WPDebloat\Analyze\EvidenceBuilder;
use WPDebloat\Contracts\AnalyzerRuleInterface;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Impact;
use WPDebloat\Contracts\Recommendation;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;
use WPDebloat\Contracts\TweakParams;

/**
 * Base for the rules in BUILD-SPEC §17 Phase 3.
 *
 * One rule, one finding id. A rule reads facts and produces at most one Finding,
 * and it does three things and no more: decide whether it fires, describe what
 * it saw, and name the tweak that would change it.
 *
 * What a rule deliberately does **not** decide:
 *
 * - **Confidence.** The rule declares a base; ConfidenceCalculator applies the
 *   site's penalties. A rule cannot know the site is on an unrecognised host.
 * - **Whether the change should happen.** DontTouchRules can turn any
 *   recommendation into a refusal after the fact.
 * - **The final risk.** RiskEngine may raise it in Phase 4.
 *
 * That separation is what stops "this is worth changing" and "this is safe to
 * change here" from being decided in the same place by the same person.
 */
abstract class AbstractRule implements AnalyzerRuleInterface {

	/**
	 * Facts the rule needs before it can say anything.
	 *
	 * supports() returns false when any is missing, which the analyzer reports
	 * as "not evaluated" rather than as "nothing wrong".
	 *
	 * @return array<int,string>
	 */
	abstract protected function requiredFacts(): array;

	/**
	 * Whether the rule can evaluate these facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return bool
	 */
	public function supports( FactSet $facts ): bool {
		foreach ( $this->requiredFacts() as $key ) {
			if ( ! $facts->has( $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The base confidence this rule declares for the ideal case.
	 *
	 * @return float
	 */
	abstract public function baseConfidence(): float;

	/**
	 * An evidence builder over the given facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return EvidenceBuilder
	 */
	protected function evidence( FactSet $facts ): EvidenceBuilder {
		return new EvidenceBuilder( $facts );
	}

	/**
	 * Build a finding that recommends a tweak.
	 *
	 * @param array<string,mixed> $fields Finding fields.
	 * @return Finding
	 */
	protected function recommend( array $fields ): Finding {
		return new Finding(
			$this->findingId(),
			$fields['category'],
			$fields['severity'],
			$fields['risk'],
			$fields['confidence'] ?? $this->baseConfidence(),
			$fields['title'],
			$fields['summary'],
			$fields['why'],
			$fields['evidence'],
			$fields['impact'] ?? null,
			Decision::RECOMMEND,
			null,
			new Recommendation( $fields['tweak_id'], new TweakParams( $fields['params'] ?? array() ) ),
			$fields['undo'] ?? true,
			$fields['requires'] ?? array(),
			$fields['conflicts'] ?? array(),
			$fields['dependencies_detected'] ?? 0
		);
	}

	/**
	 * Build a finding that proposes nothing.
	 *
	 * Info findings exist because "we looked at this and it is worth knowing"
	 * is a useful thing to say without it being a suggestion. They carry no
	 * recommendation and cost nothing in the score.
	 *
	 * @param array<string,mixed> $fields Finding fields.
	 * @return Finding
	 */
	protected function inform( array $fields ): Finding {
		return new Finding(
			$this->findingId(),
			$fields['category'],
			$fields['severity'] ?? Severity::INFO,
			$fields['risk'] ?? Risk::SAFE,
			$fields['confidence'] ?? $this->baseConfidence(),
			$fields['title'],
			$fields['summary'],
			$fields['why'],
			$fields['evidence'],
			$fields['impact'] ?? null,
			Decision::INFO,
			null,
			null,
			$fields['undo'] ?? false,
			$fields['requires'] ?? array(),
			$fields['conflicts'] ?? array(),
			$fields['dependencies_detected'] ?? 0
		);
	}

	/**
	 * A measurable impact.
	 *
	 * @param string $kind     What is being estimated.
	 * @param float  $estimate Magnitude.
	 * @param string $unit     Unit of measure.
	 * @return Impact
	 */
	protected function measurable( string $kind, float $estimate, string $unit ): Impact {
		return new Impact( $kind, $estimate, $unit, true );
	}

	/**
	 * An impact the Meter cannot verify.
	 *
	 * Marked unmeasurable so the report can say so rather than presenting an
	 * estimate as a result.
	 *
	 * @param string $kind     What is being estimated.
	 * @param float  $estimate Magnitude.
	 * @param string $unit     Unit of measure.
	 * @return Impact
	 */
	protected function estimated( string $kind, float $estimate, string $unit ): Impact {
		return new Impact( $kind, $estimate, $unit, false );
	}

	/**
	 * Convenience accessor for the WordPress category.
	 *
	 * @return Category
	 */
	protected function wordpress(): Category {
		return Category::WORDPRESS;
	}
}
