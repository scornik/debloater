<?php
/**
 * Base for rules about a core feature being switched on.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Impact;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Severity;

/**
 * Shared shape for "core still emits this, and there is a tweak that stops it".
 *
 * Eight of the MVP rules have the same skeleton: one boolean fact, and a tweak
 * that turns it off. Writing them as eight near-identical files would make the
 * differences that actually matter — the risk level, the honest account of what
 * breaks — harder to see rather than easier.
 *
 * A subclass supplies the fact, the tweak, the wording, and the risk. Everything
 * else is here.
 *
 * The rule fires only when the fact is true. That means a feature already
 * removed — by a theme, another plugin, or a previous Debloater run — produces
 * no finding at all, so the same suggestion is never made twice.
 */
abstract class CoreFeatureRule extends AbstractRule {

	/**
	 * The boolean fact that must be true for this rule to fire.
	 *
	 * @return string
	 */
	abstract protected function fact(): string;

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	abstract protected function tweakId(): string;

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	abstract protected function title(): string;

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	abstract protected function summary(): string;

	/**
	 * Why it matters, in the user's terms.
	 *
	 * @return string
	 */
	abstract protected function why(): string;

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	abstract protected function evidenceLabel(): string;

	/**
	 * Category this finding belongs to.
	 *
	 * @return Category
	 */
	protected function category(): Category {
		return Category::WORDPRESS;
	}

	/**
	 * How much this matters.
	 *
	 * @return Severity
	 */
	protected function severity(): Severity {
		return Severity::LOW;
	}

	/**
	 * How dangerous the change is.
	 *
	 * @return Risk
	 */
	protected function risk(): Risk {
		return Risk::SAFE;
	}

	/**
	 * The estimated impact, or null when none can be stated honestly.
	 *
	 * @return Impact|null
	 */
	protected function impact(): ?Impact {
		return null;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( $this->fact() );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( $this->fact() ) ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => $this->category(),
				'severity' => $this->severity(),
				'risk'     => $this->risk(),
				'title'    => $this->title(),
				'summary'  => $this->summary(),
				'why'      => $this->why(),
				'evidence' => $this->evidence( $facts )
					->formatted( $this->evidenceLabel(), __( 'Enabled', 'debloater' ), $this->fact() )
					->build(),
				'impact'   => $this->impact(),
				'tweak_id' => $this->tweakId(),
			)
		);
	}
}
