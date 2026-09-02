<?php
/**
 * Resolves requirements and conflicts between tweaks.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Recommend;

use WPDebloat\Registry\Registry;

/**
 * Decides which tweaks in a candidate set can actually be applied together
 * (BUILD-SPEC §7.4, §17 Phase 1).
 *
 * This is v1: it understands requirements expressed as tweak ids and conflicts
 * between tweak ids. Fact predicates ("fact:plugins.detected.woocommerce=true")
 * need a scan, so they arrive with v2 in Phase 4. Anything it cannot yet
 * evaluate is excluded rather than assumed satisfied — a resolver that guesses
 * "probably fine" is worse than one that says "not yet".
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
	 * Constructor.
	 *
	 * @param Registry $registry Registry to resolve against.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
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

		// A fact predicate cannot be evaluated without a scan, and this resolver
		// has no facts. Excluding is the safe direction (BUILD-SPEC §7.4: no
		// tweak with unresolved requires enters a plan).
		$predicates = $definition->requiredFactPredicates();

		if ( array() !== $predicates ) {
			return sprintf(
				'Requires conditions that cannot be checked without a scan: %s.',
				implode( ', ', $predicates )
			);
		}

		foreach ( $this->registry->conflictsFor( $tweak_id ) as $conflict_id ) {
			if ( in_array( $conflict_id, $accepted, true ) ) {
				return sprintf( 'Conflicts with "%s", which is already selected.', $conflict_id );
			}
		}

		return null;
	}
}
