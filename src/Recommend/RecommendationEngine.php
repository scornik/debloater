<?php
/**
 * Turns findings into a set of tweaks to propose.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Recommend;

use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Tweak;
use Debloater\Registry\Profile;
use Debloater\Registry\Registry;

/**
 * Findings + intent + compatibility + registry → tweaks (BUILD-SPEC §2, §17
 * Phase 4).
 *
 * Deterministic by construction: the same facts, the same findings, the same
 * profile and the same registry always produce the same set, in the same order.
 * No time, no randomness, no network, no AI. That is not a performance
 * consideration — it is what makes a plan something a user can review and a
 * support conversation something that can be reproduced.
 *
 * The engine's whole job is selection. It decides *which* tweaks to propose and
 * *with what parameters*; it does not decide whether they are safe to apply
 * together — that is PreviewPlanner enforcing the §7.4 invariants — and it does
 * not apply anything.
 *
 * Two rules it will not bend:
 *
 * - A finding the analyzer refused (`dont_touch`) never contributes a tweak,
 *   whatever the profile says. A profile is a filter on what is offered, not a
 *   way to overrule a refusal.
 * - A profile that admits a risk level does not make a tweak that risk level.
 *   The RiskEngine assesses first, and the profile filters on the result.
 */
final class RecommendationEngine {

	/**
	 * The registry tweaks are resolved from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * What the owner said the site is for.
	 *
	 * @var IntentProfile
	 */
	private IntentProfile $intent;

	/**
	 * Site-specific risk assessment.
	 *
	 * @var RiskEngine
	 */
	private RiskEngine $risk;

	/**
	 * Constructor.
	 *
	 * @param Registry           $registry Registry to resolve tweaks from.
	 * @param FactSet            $facts    Facts from the scan.
	 * @param IntentProfile|null $intent   Stated intent, or the unstated default.
	 */
	public function __construct( Registry $registry, FactSet $facts, ?IntentProfile $intent = null ) {
		$this->registry = $registry;
		$this->intent   = $intent ?? IntentProfile::unstated();
		$this->risk     = new RiskEngine( $facts );
	}

	/**
	 * Every tweak the findings support, with its final risk, before filtering.
	 *
	 * Ordered by tweak id, so the caller gets the same list every time.
	 *
	 * @param array<int,Finding> $findings Findings from the analysis.
	 * @return Recommendations
	 */
	public function recommend( array $findings ): Recommendations {
		$tweaks  = array();
		$sources = array();
		$skipped = array();

		foreach ( $findings as $finding ) {
			$reason = $this->rejectionReason( $finding );

			if ( null !== $reason ) {
				if ( '' !== $reason ) {
					$skipped[ $finding->id ] = $reason;
				}

				continue;
			}

			$recommendation = $finding->recommendation;

			// Guarded above by isPlannable(); named here so the type is obvious.
			if ( null === $recommendation ) {
				continue;
			}

			$definition = $this->registry->tweak( $recommendation->tweak_id );
			$tweak      = $this->risk->apply(
				$definition->resolve( $recommendation->params->toArray() ),
				$finding
			);

			$tweaks[ $tweak->id ]  = $tweak;
			$sources[ $tweak->id ] = $finding->id;
		}

		ksort( $tweaks, SORT_STRING );
		ksort( $sources, SORT_STRING );
		ksort( $skipped, SORT_STRING );

		return new Recommendations( array_values( $tweaks ), $sources, $skipped );
	}

	/**
	 * The tweaks a profile would admit, from a set of recommendations.
	 *
	 * @param Recommendations $recommendations Everything the findings support.
	 * @param Profile         $profile         Profile to filter by.
	 * @return array<int,Tweak>
	 */
	public function admitted( Recommendations $recommendations, Profile $profile ): array {
		$admitted = array();

		foreach ( $recommendations->tweaks as $tweak ) {
			if ( $profile->admits( $tweak ) ) {
				$admitted[] = $tweak;
			}
		}

		foreach ( $profile->tweaks as $tweak_id ) {
			if ( $recommendations->includes( $tweak_id ) || ! $this->registry->has( $tweak_id ) ) {
				continue;
			}

			// A profile may name tweaks the findings did not raise. They still go
			// through the RiskEngine and the profile's own filter, so naming one
			// cannot smuggle in something the profile would otherwise exclude.
			$tweak = $this->risk->apply(
				$this->registry->tweak( $tweak_id )->resolve( $profile->paramsFor( $tweak_id ) )
			);

			if ( $profile->admits( $tweak ) ) {
				$admitted[] = $tweak;
			}
		}

		usort( $admitted, static fn ( Tweak $a, Tweak $b ): int => strcmp( $a->id, $b->id ) );

		return $admitted;
	}

	/**
	 * The profile to use when the user has not chosen one.
	 *
	 * @return Profile
	 */
	public function defaultProfile(): Profile {
		$suggested = $this->intent->suggestedProfile();

		return $this->registry->hasProfile( $suggested )
			? $this->registry->profile( $suggested )
			: $this->registry->profile( Profile::SAFE );
	}

	/**
	 * The risk engine, for callers that need to explain a raised level.
	 *
	 * @return RiskEngine
	 */
	public function riskEngine(): RiskEngine {
		return $this->risk;
	}

	/**
	 * The stated intent.
	 *
	 * @return IntentProfile
	 */
	public function intent(): IntentProfile {
		return $this->intent;
	}

	/**
	 * Why a finding contributes no tweak, or null when it does.
	 *
	 * An empty string means "no tweak, and nothing worth telling the user":
	 * an info finding proposes nothing by design, and listing it as skipped
	 * would be noise.
	 *
	 * @param Finding $finding Finding to consider.
	 * @return string|null
	 */
	private function rejectionReason( Finding $finding ): ?string {
		if ( ! $finding->isPlannable() ) {
			// A refusal is already explained on the finding itself, and an info
			// finding was never a proposal.
			return '';
		}

		$recommendation = $finding->recommendation;

		if ( null === $recommendation ) {
			return '';
		}

		if ( ! $this->registry->has( $recommendation->tweak_id ) ) {
			return sprintf(
				/* translators: %s: tweak id. */
				__( 'The tweak "%s" is not in this version of the registry.', 'debloater' ),
				$recommendation->tweak_id
			);
		}

		return null;
	}
}
