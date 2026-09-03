<?php
/**
 * Builds the plan the user is shown before anything happens.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Recommend;

use Debloater\Contracts\Decision;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Contracts\Tweak;
use Debloater\Contracts\TweakKind;
use Debloater\Registry\Profile;
use Debloater\Registry\Registry;

/**
 * The one place a plan can be built (BUILD-SPEC §7.4, §17 Phase 4).
 *
 * Four invariants govern what may be in a plan. Two are structural and
 * `Contracts\PreviewPlan` enforces them in its constructor, so no code path can
 * produce a plan that violates them:
 *
 * 1. A tweak appears at most once.
 * 2. Two conflicting tweaks are never both present.
 *
 * The other two need the findings and the facts, which is why this class exists
 * and why it is the only thing allowed to build a plan:
 *
 * 3. **No tweak named by an active `dont_touch` finding.** Not filtered
 *    afterwards — excluded before selection, so it cannot be reintroduced by a
 *    profile, an explicit id, or a caller passing the tweak directly.
 * 4. **No tweak with unresolved `requires`.** Including fact predicates, where
 *    a fact the scan never observed counts as unresolved rather than satisfied.
 *
 * On top of those, the "Fix Safe Issues" plan adds one more: nothing
 * destructive, ever, whatever the profile says. That is the promise behind a
 * single button, and it is checked here rather than assumed from the profile
 * having excluded it.
 *
 * Every exclusion is recorded with a reason. A plan that silently contains less
 * than the user expected is worse than one that explains itself.
 */
final class PreviewPlanner {

	/**
	 * The registry tweaks are resolved from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Facts from the scan.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Tweak ids refused by an active dont_touch finding, mapped to the reason.
	 *
	 * The findings themselves are not kept. This map is everything the planner
	 * needs from them, and holding the rest would invite a later change to start
	 * reading a finding's severity or confidence here — neither of which has any
	 * business deciding what goes into a plan.
	 *
	 * @var array<string,string>
	 */
	private array $refused;

	/**
	 * Constructor.
	 *
	 * @param Registry           $registry Registry to resolve tweaks from.
	 * @param FactSet            $facts    Facts from the scan.
	 * @param array<int,Finding> $findings Findings from the analysis.
	 */
	public function __construct( Registry $registry, FactSet $facts, array $findings = array() ) {
		$this->registry = $registry;
		$this->facts    = $facts;

		$refused = array();

		foreach ( $findings as $finding ) {
			$tweak_id = $finding->recommendedTweakId();

			if ( null !== $tweak_id && Decision::DONT_TOUCH === $finding->decision ) {
				$refused[ $tweak_id ] = (string) $finding->decision_reason;
			}
		}

		ksort( $refused, SORT_STRING );

		$this->refused = $refused;
	}

	/**
	 * Build a plan from a set of candidate tweaks.
	 *
	 * @param array<int,Tweak> $candidates Tweaks to consider.
	 * @param Profile|null     $profile    Profile to filter by, or null for none.
	 * @param bool             $safe_only  Whether this is the "Fix Safe Issues" plan.
	 * @return PlanResult
	 */
	public function plan( array $candidates, ?Profile $profile = null, bool $safe_only = false ): PlanResult {
		$accepted = array();
		$excluded = array();

		// Ordered before anything is decided, so which of two conflicting tweaks
		// survives is a property of the tweaks rather than of the order a caller
		// happened to pass them in.
		$candidates = $this->inPreferenceOrder( $candidates );

		foreach ( $candidates as $tweak ) {
			$reason = $this->exclusionReason( $tweak, $accepted, $profile, $safe_only );

			if ( null !== $reason ) {
				$excluded[ $tweak->id ] = $reason;
				continue;
			}

			$accepted[ $tweak->id ] = $tweak;
		}

		// Requirements are only satisfied by tweaks that are actually in the
		// plan, and excluding one can leave another's requirement unmet. Repeat
		// until nothing more drops out: a single pass would let a tweak stay
		// because something that was *considered* satisfied it, when that
		// something was itself excluded a moment later.
		$accepted = $this->dropUnmetRequirements( $accepted, $excluded );

		$tweaks = array_values( $accepted );

		return new PlanResult(
			new PreviewPlan( $tweaks, $this->willChange( $tweaks ), $this->willNot( $tweaks, $excluded ) ),
			$excluded
		);
	}

	/**
	 * Repeatedly drop tweaks whose requirements are not in the plan.
	 *
	 * @param array<string,Tweak>  $accepted Tweaks accepted so far.
	 * @param array<string,string> $excluded Exclusions, by reference.
	 * @return array<string,Tweak>
	 */
	private function dropUnmetRequirements( array $accepted, array &$excluded ): array {
		$changed = true;

		while ( $changed ) {
			$changed = false;

			foreach ( $accepted as $id => $tweak ) {
				unset( $tweak );

				if ( ! $this->registry->has( $id ) ) {
					continue;
				}

				foreach ( $this->registry->tweak( $id )->requiredTweakIds() as $required_id ) {
					if ( array_key_exists( $required_id, $accepted ) ) {
						continue;
					}

					$excluded[ $id ] = sprintf(
						/* translators: 1: required tweak id, 2: why that tweak is not in the plan. */
						__( 'Requires "%1$s", which is not part of this plan: %2$s', 'debloater' ),
						$required_id,
						$excluded[ $required_id ] ?? __( 'it was not selected.', 'debloater' )
					);

					unset( $accepted[ $id ] );

					$changed = true;
					break;
				}
			}
		}

		return $accepted;
	}

	/**
	 * Order candidates so that conflict resolution is principled.
	 *
	 * When two tweaks conflict, the first one considered wins, so the order is
	 * the decision. Lower risk first, because between two changes that cannot
	 * both be applied the safer one is the better default; then by id, so the
	 * result is deterministic when the risks match.
	 *
	 * @param array<int,Tweak> $candidates Candidate tweaks.
	 * @return array<int,Tweak>
	 */
	private function inPreferenceOrder( array $candidates ): array {
		usort(
			$candidates,
			static function ( Tweak $a, Tweak $b ): int {
				if ( $a->destructive !== $b->destructive ) {
					// A non-destructive change is always the better of two that
					// cannot both be applied.
					return $a->destructive ? 1 : -1;
				}

				if ( $a->risk->rank() !== $b->risk->rank() ) {
					return $a->risk->rank() <=> $b->risk->rank();
				}

				return strcmp( $a->id, $b->id );
			}
		);

		return $candidates;
	}

	/**
	 * Build the "Fix Safe Issues" plan.
	 *
	 * @param array<int,Tweak> $candidates Tweaks to consider.
	 * @return PlanResult
	 */
	public function safePlan( array $candidates ): PlanResult {
		return $this->plan(
			$candidates,
			$this->registry->hasProfile( Profile::SAFE ) ? $this->registry->profile( Profile::SAFE ) : null,
			true
		);
	}

	/**
	 * Tweak ids an active dont_touch finding refuses.
	 *
	 * @return array<string,string> Tweak id to the reason given.
	 */
	public function refusedTweaks(): array {
		return $this->refused;
	}

	/**
	 * Why a tweak cannot be in this plan, or null when it can.
	 *
	 * @param Tweak               $tweak     Tweak to consider.
	 * @param array<string,Tweak> $accepted  Tweaks accepted so far, for conflicts and requirements.
	 * @param Profile|null        $profile   Profile to filter by.
	 * @param bool                $safe_only Whether this is the safe plan.
	 * @return string|null
	 */
	private function exclusionReason(
		Tweak $tweak,
		array $accepted,
		?Profile $profile,
		bool $safe_only
	): ?string {
		// Invariant 3, first, because a refusal outranks everything else.
		if ( array_key_exists( $tweak->id, $this->refused ) ) {
			return sprintf(
				/* translators: %s: the reason the change was refused. */
				__( 'No action recommended here: %s', 'debloater' ),
				$this->refused[ $tweak->id ]
			);
		}

		if ( ! $this->registry->has( $tweak->id ) ) {
			return __( 'This tweak is not in the current registry.', 'debloater' );
		}

		// The safe plan excludes destructive operations outright. Checked here as
		// well as in the profile, because this is the promise behind a single
		// button and it should not depend on a JSON file being right.
		if ( $safe_only && $tweak->destructive ) {
			return __( 'Deleting data is never part of Fix Safe Issues.', 'debloater' );
		}

		if ( $safe_only && ! $tweak->risk->isSafePlanEligible() ) {
			return sprintf(
				/* translators: %s: risk level. */
				__( 'Risk is %s, which needs to be reviewed rather than applied in one click.', 'debloater' ),
				$tweak->risk->value
			);
		}

		if ( null !== $profile && ! $profile->admits( $tweak ) ) {
			return sprintf(
				/* translators: 1: profile title, 2: risk level. */
				__( 'The %1$s profile does not include %2$s-risk changes.', 'debloater' ),
				$profile->title,
				$tweak->risk->value
			);
		}

		// Invariant 4: unresolved requirements, tweak ids and fact predicates.
		// A required tweak counts only when it is already in the plan; anything
		// dropped later is caught by the fixed-point pass in plan().
		$unresolved = $this->unresolvedRequirement( $tweak, array_keys( $accepted ) );

		if ( null !== $unresolved ) {
			return $unresolved;
		}

		// Invariant 2, resolved in both directions. PreviewPlan would refuse to
		// construct anyway; catching it here means the user gets a reason rather
		// than an exception.
		foreach ( $this->registry->conflictsFor( $tweak->id ) as $conflict_id ) {
			if ( array_key_exists( $conflict_id, $accepted ) ) {
				return sprintf(
					/* translators: %s: tweak id. */
					__( 'Cannot be applied alongside "%s", which is already in this plan.', 'debloater' ),
					$conflict_id
				);
			}
		}

		return null;
	}

	/**
	 * The first unmet requirement, or null when all are met.
	 *
	 * @param Tweak             $tweak       Tweak to consider.
	 * @param array<int,string> $planned_ids Ids already in the plan.
	 * @return string|null
	 */
	private function unresolvedRequirement( Tweak $tweak, array $planned_ids ): ?string {
		$definition = $this->registry->tweak( $tweak->id );

		foreach ( $definition->requiredTweakIds() as $required_id ) {
			if ( ! in_array( $required_id, $planned_ids, true ) ) {
				return sprintf(
					/* translators: %s: tweak id. */
					__( 'Requires "%s", which is not part of this plan.', 'debloater' ),
					$required_id
				);
			}
		}

		foreach ( $definition->requiredFactPredicates() as $requirement ) {
			$predicate = FactPredicate::parse( $requirement );

			if ( ! $predicate->isObservableIn( $this->facts ) ) {
				return sprintf(
					/* translators: %s: fact key. */
					__( 'Depends on %s, which the last scan did not observe.', 'debloater' ),
					$predicate->fact
				);
			}

			if ( ! $predicate->isSatisfiedBy( $this->facts ) ) {
				return sprintf(
					/* translators: %s: the condition that was required. */
					__( 'Only applies where %s, which is not true of this site.', 'debloater' ),
					$predicate->describe()
				);
			}
		}

		return null;
	}

	/**
	 * Plain statements of what the plan will change.
	 *
	 * @param array<int,Tweak> $tweaks Tweaks in the plan.
	 * @return array<int,string>
	 */
	private function willChange( array $tweaks ): array {
		$lines = array();

		foreach ( $tweaks as $tweak ) {
			$definition = $this->registry->has( $tweak->id ) ? $this->registry->tweak( $tweak->id ) : null;

			$lines[] = null === $definition ? $tweak->title : $definition->description;
		}

		return $lines;
	}

	/**
	 * Plain statements of what the plan will not change.
	 *
	 * The most important line here is the one about deletion. A user about to
	 * press a button on their live site should not have to infer from an absence
	 * that nothing will be removed.
	 *
	 * @param array<int,Tweak>     $tweaks   Tweaks in the plan.
	 * @param array<string,string> $excluded Tweaks left out, with reasons.
	 * @return array<int,string>
	 */
	private function willNot( array $tweaks, array $excluded ): array {
		$lines       = array();
		$destructive = false;
		$data        = false;

		foreach ( $tweaks as $tweak ) {
			$destructive = $destructive || $tweak->destructive;
			$data        = $data || TweakKind::DATA === $tweak->kind;
		}

		if ( ! $destructive ) {
			$lines[] = __( 'Nothing will be deleted.', 'debloater' );
		}

		if ( ! $data ) {
			$lines[] = __( 'No content, settings or database rows will be changed — only which hooks WordPress registers.', 'debloater' );
		}

		if ( array() !== $this->refused ) {
			$lines[] = sprintf(
				/* translators: %d: number of changes deliberately left alone. */
				_n(
					'%d change was deliberately left alone because something on this site depends on it.',
					'%d changes were deliberately left alone because something on this site depends on them.',
					count( $this->refused ),
					'debloater'
				),
				count( $this->refused )
			);
		}

		$other = count( $excluded ) - count( array_intersect_key( $excluded, $this->refused ) );

		if ( $other > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of changes not included in this plan. */
				_n(
					'%d further change is available but not part of this plan.',
					'%d further changes are available but not part of this plan.',
					$other,
					'debloater'
				),
				$other
			);
		}

		$lines[] = __( 'A recovery point is created before anything is changed, and every change here can be undone.', 'debloater' );

		return $lines;
	}

	/**
	 * The snapshot levels a set of tweaks would require.
	 *
	 * @param array<int,Tweak> $tweaks Tweaks in the plan.
	 * @return array<int,SnapshotLevel>
	 */
	public static function snapshotLevelsFor( array $tweaks ): array {
		$levels = array();

		foreach ( $tweaks as $tweak ) {
			$levels[ $tweak->requiredSnapshotLevel()->value ] = $tweak->requiredSnapshotLevel();
		}

		if ( array() !== $tweaks ) {
			$levels[ SnapshotLevel::A->value ] = SnapshotLevel::A;
		}

		ksort( $levels, SORT_STRING );

		return array_values( $levels );
	}
}
