<?php
/**
 * Adjusts a tweak's declared risk for this site.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Recommend;

use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Tweak;

/**
 * Final risk = declared risk, raised where this site warrants it
 * (BUILD-SPEC §17 Phase 4).
 *
 * A tweak's declared risk is written for the general case. This raises it where
 * something about the site makes the same change more likely to go wrong here:
 *
 * - **Something depends on what would change.** Not enough to refuse — that is
 *   `dont_touch`'s job — but enough that this is no longer the general case.
 * - **The host is unrecognised.** Managed hosts apply their own optimisations,
 *   and on a host we cannot identify we do not know what else is already
 *   changing the same behaviour.
 *
 * Risk only ever goes up. A site can never talk a change into being safer than
 * the registry says it is, and neither can a profile: the whole point of a
 * declared risk is that it is not negotiable by the thing being assessed.
 *
 * One level at most, however many reasons apply. Two conditions are a reason for
 * more care, not for treating a safe change as dangerous — and inflating risk
 * until everything looks alarming is its own kind of dishonesty.
 */
final class RiskEngine {

	/**
	 * Facts from the scan.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Constructor.
	 *
	 * Facts only. An earlier draft also took a CompatibilityResolver, until
	 * hasDependents() started reading the count from the finding instead — which
	 * is where the mapping from a change to the capability it touches already
	 * lives. Keeping the parameter would have implied a dependency this class
	 * does not have.
	 *
	 * @param FactSet $facts Facts from the scan.
	 */
	public function __construct( FactSet $facts ) {
		$this->facts = $facts;
	}

	/**
	 * The final risk for a tweak on this site.
	 *
	 * @param Tweak        $tweak   Tweak to assess.
	 * @param Finding|null $finding The finding that recommended it, if any.
	 * @return Risk
	 */
	public function assess( Tweak $tweak, ?Finding $finding = null ): Risk {
		return array() === $this->reasons( $tweak, $finding ) ? $tweak->risk : $tweak->risk->raised();
	}

	/**
	 * A tweak carrying its final risk.
	 *
	 * @param Tweak        $tweak   Tweak to assess.
	 * @param Finding|null $finding The finding that recommended it, if any.
	 * @return Tweak
	 */
	public function apply( Tweak $tweak, ?Finding $finding = null ): Tweak {
		$risk = $this->assess( $tweak, $finding );

		return $risk === $tweak->risk ? $tweak : $tweak->withRisk( $risk );
	}

	/**
	 * Why the risk was raised, in the user's terms.
	 *
	 * Returned so the interface can explain a raised risk rather than showing a
	 * level that disagrees with the documentation for no visible reason.
	 *
	 * @param Tweak        $tweak   Tweak to assess.
	 * @param Finding|null $finding The finding that recommended it, if any.
	 * @return array<int,string>
	 */
	public function reasons( Tweak $tweak, ?Finding $finding = null ): array {
		$reasons = array();

		if ( $this->hasDependents( $tweak, $finding ) ) {
			$reasons[] = __(
				'Something installed on this site depends on what this change would alter.',
				'debloater'
			);
		}

		if ( 'unknown' === $this->facts->value( 'env.host_vendor', 'unknown' ) ) {
			$reasons[] = __(
				'This host was not recognised, so we cannot tell what it already changes for you.',
				'debloater'
			);
		}

		return $reasons;
	}

	/**
	 * Whether anything present depends on what the tweak changes.
	 *
	 * The answer comes from the finding, which is where the mapping from a
	 * change to the capability it touches lives (Analyze\DontTouchRules). Doing
	 * that mapping again here would be a second copy to keep in step, and the
	 * two disagreeing would mean a risk level that contradicts the refusal logic
	 * on the same page.
	 *
	 * A tweak selected directly — from the CLI, or by id — has no finding, and
	 * therefore no known capability. That is reported as "no known dependents"
	 * rather than guessed at: the declared risk already covers the general case,
	 * and raising it on a hunch would make every hand-picked tweak look worse
	 * than the same tweak arrived at through a scan.
	 *
	 * @param Tweak        $tweak   Tweak to assess.
	 * @param Finding|null $finding The finding that recommended it, if any.
	 * @return bool
	 */
	private function hasDependents( Tweak $tweak, ?Finding $finding ): bool {
		unset( $tweak );

		return null !== $finding && $finding->dependencies_detected > 0;
	}
}
