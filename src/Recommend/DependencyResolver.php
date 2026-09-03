<?php
/**
 * Resolves requirements and conflicts between tweaks.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Recommend;

use Debloater\Contracts\FactSet;
use Debloater\Registry\Registry;

/**
 * Decides which tweaks in a candidate set can actually be applied together
 * (BUILD-SPEC §7.4, §17 Phase 4).
 *
 * Three kinds of requirement, and one rule shared between them: anything that
 * cannot be shown to hold excludes the tweak.
 *
 * - **Tweak ids.** The required tweak must also be selected.
 * - **Fact predicates** (`fact:plugins.detected.woocommerce=true`). The fact
 *   must have been observed *and* match. A fact the scan never produced is
 *   unresolved, not satisfied — "we did not look" is not evidence.
 * - **Conflicts.** Two tweaks that conflict never survive together, and the
 *   conflict applies in both directions whichever side declared it.
 *
 * Without facts, the resolver still works: it simply cannot evaluate a fact
 * predicate, and says so. That is how it behaved through Phases 1 to 3, and the
 * behaviour is unchanged — a resolver that guesses "probably fine" is worse
 * than one that says "not without a scan".
 *
 * Resolution is deterministic: candidates are considered in sorted id order, so
 * the same input always yields the same accepted set, including which of two
 * conflicting tweaks wins.
 */
final class DependencyResolver {

	/**
	 * The registry the candidates come from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Facts a predicate can be evaluated against, or null when none are known.
	 *
	 * @var FactSet|null
	 */
	private ?FactSet $facts;

	/**
	 * Constructor.
	 *
	 * @param Registry     $registry Registry to resolve against.
	 * @param FactSet|null $facts    Facts from a scan, when there has been one.
	 */
	public function __construct( Registry $registry, ?FactSet $facts = null ) {
		$this->registry = $registry;
		$this->facts    = $facts;
	}

	/**
	 * A copy of this resolver that can evaluate fact predicates.
	 *
	 * @param FactSet $facts Facts from a scan.
	 * @return self
	 */
	public function withFacts( FactSet $facts ): self {
		return new self( $this->registry, $facts );
	}

	/**
	 * Resolve a candidate set into accepted and rejected tweaks.
	 *
	 * @param array<int,string> $candidates Candidate tweak ids.
	 * @return Resolution
	 */
	public function resolve( array $candidates ): Resolution {
		$wanted = array();

		foreach ( $candidates as $tweak_id ) {
			$wanted[ $tweak_id ] = true;
		}

		ksort( $wanted, SORT_STRING );

		$accepted = array();
		$rejected = array();

		foreach ( array_keys( $wanted ) as $tweak_id ) {
			$reason = $this->rejectionReason( $tweak_id, $wanted, $accepted );

			if ( null !== $reason ) {
				$rejected[ $tweak_id ] = $reason;
				continue;
			}

			$accepted[] = $tweak_id;
		}

		return new Resolution( $accepted, $rejected );
	}

	/**
	 * Why a candidate cannot be accepted, or null when it can.
	 *
	 * @param string                $tweak_id Candidate tweak id.
	 * @param array<string,bool>    $wanted   The whole candidate set.
	 * @param array<int,string>     $accepted Tweaks accepted so far.
	 * @return string|null
	 */
	private function rejectionReason( string $tweak_id, array $wanted, array $accepted ): ?string {
		if ( ! $this->registry->has( $tweak_id ) ) {
			return sprintf( 'No tweak with the id "%s" exists in the registry.', $tweak_id );
		}

		$definition = $this->registry->tweak( $tweak_id );

		foreach ( $definition->requiredTweakIds() as $required_id ) {
			if ( ! array_key_exists( $required_id, $wanted ) ) {
				return sprintf( 'Requires "%s", which is not selected.', $required_id );
			}
		}

		$predicates = $definition->requiredFactPredicates();

		if ( array() !== $predicates && null === $this->facts ) {
			// BUILD-SPEC §7.4: no tweak with unresolved requires enters a plan.
			// Without a scan there are no facts, so these cannot be resolved.
			return sprintf(
				'Requires conditions that cannot be checked without a scan: %s.',
				implode( ', ', $predicates )
			);
		}

		foreach ( $predicates as $requirement ) {
			$predicate = FactPredicate::parse( $requirement );

			if ( null !== $this->facts && ! $predicate->isObservableIn( $this->facts ) ) {
				return sprintf(
					'Requires %s, which this scan did not observe.',
					$predicate->fact
				);
			}

			if ( null !== $this->facts && ! $predicate->isSatisfiedBy( $this->facts ) ) {
				return sprintf( 'Requires %s, which is not true of this site.', $predicate->describe() );
			}
		}

		foreach ( $this->registry->conflictsFor( $tweak_id ) as $conflict_id ) {
			if ( in_array( $conflict_id, $accepted, true ) ) {
				return sprintf( 'Conflicts with "%s", which is already selected.', $conflict_id );
			}
		}

		return null;
	}
}
