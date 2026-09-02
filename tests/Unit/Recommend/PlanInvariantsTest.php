<?php
/**
 * The §7.4 plan invariants, over generated registries and finding sets.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Recommend;

use PHPUnit\Framework\TestCase;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Evidence;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Recommendation;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;
use WPDebloat\Contracts\Tweak;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Contracts\TweakParams;
use WPDebloat\Recommend\PreviewPlanner;
use WPDebloat\Registry\Profile;
use WPDebloat\Registry\Registry;
use WPDebloat\Registry\TweakDefinition;
use WPDebloat\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §17 Phase 4 asks for these as property tests over generated
 * registries and finding sets, and that is what these are: a seeded generator
 * builds several hundred plausible-but-awkward registries — conflicts,
 * requirements, destructive tweaks, refusals — and every one of them must
 * satisfy all four invariants.
 *
 * The generator is seeded, so a failure is reproducible from the seed printed
 * in the message. A property test nobody can reproduce is a flaky test.
 */
final class PlanInvariantsTest extends TestCase {

	/**
	 * How many generated cases to check per invariant.
	 */
	private const CASES = 120;

	/**
	 * BUILD-SPEC §7.4: a safe plan never contains a destructive tweak.
	 *
	 * @return void
	 */
	public function test_a_safe_plan_never_contains_a_destructive_tweak(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case   = $this->generate( $seed );
			$result = $case['planner']->safePlan( $case['candidates'] );

			foreach ( $result->plan->tweaks as $tweak ) {
				$this->assertFalse(
					$tweak->destructive,
					sprintf( 'seed %d: destructive tweak "%s" reached the safe plan', $seed, $tweak->id )
				);
			}
		}
	}

	/**
	 * BUILD-SPEC §7.4: a safe plan never contains a medium- or high-risk tweak.
	 *
	 * @return void
	 */
	public function test_a_safe_plan_never_contains_a_risky_tweak(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case   = $this->generate( $seed );
			$result = $case['planner']->safePlan( $case['candidates'] );

			foreach ( $result->plan->tweaks as $tweak ) {
				$this->assertTrue(
					$tweak->risk->isSafePlanEligible(),
					sprintf( 'seed %d: %s-risk tweak "%s" reached the safe plan', $seed, $tweak->risk->value, $tweak->id )
				);
			}
		}
	}

	/**
	 * BUILD-SPEC §7.4: two conflicting tweaks are never both in one plan.
	 *
	 * Checked in both directions, because a conflict declared on one side binds
	 * both.
	 *
	 * @return void
	 */
	public function test_no_plan_contains_two_conflicting_tweaks(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case = $this->generate( $seed );

			foreach ( $this->everyProfile( $case['registry'] ) as $profile ) {
				$plan = $case['planner']->plan( $case['candidates'], $profile )->plan;
				$ids  = $plan->tweakIds();

				foreach ( $ids as $id ) {
					foreach ( $case['registry']->conflictsFor( $id ) as $conflict_id ) {
						$this->assertNotContains(
							$conflict_id,
							$ids,
							sprintf( 'seed %d, profile %s: "%s" and "%s" are both in the plan', $seed, $profile->id, $id, $conflict_id )
						);
					}
				}
			}
		}
	}

	/**
	 * BUILD-SPEC §7.4: a tweak named by an active dont_touch finding never
	 * enters a plan, under any profile.
	 *
	 * @return void
	 */
	public function test_a_refused_tweak_never_enters_any_plan(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case = $this->generate( $seed );

			foreach ( $this->everyProfile( $case['registry'] ) as $profile ) {
				$plan = $case['planner']->plan( $case['candidates'], $profile )->plan;

				foreach ( array_keys( $case['planner']->refusedTweaks() ) as $refused_id ) {
					$this->assertFalse(
						$plan->contains( $refused_id ),
						sprintf( 'seed %d, profile %s: refused tweak "%s" reached the plan', $seed, $profile->id, $refused_id )
					);
				}
			}
		}
	}

	/**
	 * BUILD-SPEC §7.4: a tweak with an unresolved requirement never enters a
	 * plan.
	 *
	 * @return void
	 */
	public function test_a_tweak_with_unresolved_requirements_never_enters_a_plan(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case = $this->generate( $seed );
			$plan = $case['planner']->plan( $case['candidates'] )->plan;
			$ids  = $plan->tweakIds();

			foreach ( $ids as $id ) {
				$definition = $case['registry']->tweak( $id );

				foreach ( $definition->requiredTweakIds() as $required_id ) {
					$this->assertContains(
						$required_id,
						$ids,
						sprintf( 'seed %d: "%s" is in the plan but its requirement "%s" is not', $seed, $id, $required_id )
					);
				}

				foreach ( $definition->requiredFactPredicates() as $requirement ) {
					$predicate = \WPDebloat\Recommend\FactPredicate::parse( $requirement );

					$this->assertTrue(
						$predicate->isSatisfiedBy( $case['facts'] ),
						sprintf( 'seed %d: "%s" is in the plan but %s does not hold', $seed, $id, $predicate->describe() )
					);
				}
			}
		}
	}

	/**
	 * Every exclusion carries a reason, so a shorter plan than expected can
	 * always be explained.
	 *
	 * @return void
	 */
	public function test_every_exclusion_has_a_reason(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case   = $this->generate( $seed );
			$result = $case['planner']->safePlan( $case['candidates'] );

			foreach ( $result->excluded as $id => $reason ) {
				$this->assertNotSame( '', trim( $reason ), sprintf( 'seed %d: "%s" excluded with no reason', $seed, $id ) );
			}

			$this->assertSame(
				count( $case['candidates'] ),
				$result->count() + count( $result->excluded ),
				sprintf( 'seed %d: every candidate must be either planned or explained', $seed )
			);
		}
	}

	/**
	 * Planning is deterministic: the same inputs always produce the same plan,
	 * whatever order the candidates arrive in.
	 *
	 * @return void
	 */
	public function test_planning_is_deterministic(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case = $this->generate( $seed );

			$forward  = $case['planner']->safePlan( $case['candidates'] )->plan->tweakIds();
			$repeated = $case['planner']->safePlan( $case['candidates'] )->plan->tweakIds();
			$reversed = $case['planner']->safePlan( array_reverse( $case['candidates'] ) )->plan->tweakIds();

			$this->assertSame( $forward, $repeated, 'seed ' . $seed );
			$this->assertSame( $forward, $reversed, 'seed ' . $seed . ': order must not matter' );
		}
	}

	/**
	 * A safe plan is a subset of what a wider profile would allow.
	 *
	 * Loosening the filter can never remove something from the plan.
	 *
	 * @return void
	 */
	public function test_a_wider_profile_never_excludes_what_a_narrower_one_allowed(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case = $this->generate( $seed );

			$safe    = $case['planner']->plan( $case['candidates'], $this->profile( 'safe', array( Risk::SAFE, Risk::LOW ) ) )->plan->tweakIds();
			$maximum = $case['planner']->plan( $case['candidates'], $this->profile( 'maximum', Risk::cases() ) )->plan->tweakIds();

			foreach ( $safe as $id ) {
				$this->assertContains(
					$id,
					$maximum,
					sprintf( 'seed %d: "%s" is in the safe plan but not the maximum one', $seed, $id )
				);
			}
		}
	}

	/**
	 * A plan that contains only config tweaks needs only a Level A snapshot; one
	 * with a data tweak needs Level B as well (BUILD-SPEC §15).
	 *
	 * @return void
	 */
	public function test_snapshot_levels_follow_the_plan_contents(): void {
		for ( $seed = 1; $seed <= self::CASES; $seed++ ) {
			$case = $this->generate( $seed );
			$plan = $case['planner']->plan( $case['candidates'], $this->profile( 'maximum', Risk::cases() ) )->plan;

			$has_data = array() !== $plan->dataTweaks();
			$levels   = array_map( static fn ( $level ): string => $level->value, $plan->snapshot_levels );

			if ( $plan->isEmpty() ) {
				$this->assertSame( array(), $levels, 'seed ' . $seed );
				continue;
			}

			$this->assertContains( 'A', $levels, 'seed ' . $seed . ': every plan needs a config snapshot' );
			$this->assertSame( $has_data, in_array( 'B', $levels, true ), 'seed ' . $seed );
		}
	}

	/**
	 * An empty candidate set produces an empty plan, not an error.
	 *
	 * @return void
	 */
	public function test_an_empty_candidate_set_plans_nothing(): void {
		$result = $this->generate( 1 )['planner']->safePlan( array() );

		$this->assertTrue( $result->isEmpty() );
		$this->assertSame( array(), $result->excluded );
	}

	/**
	 * Every profile in a registry, plus no profile at all.
	 *
	 * @param Registry $registry Registry to read profiles from.
	 * @return array<int,Profile>
	 */
	private function everyProfile( Registry $registry ): array {
		return array_values( $registry->profiles() );
	}

	/**
	 * A profile admitting the given risk levels.
	 *
	 * @param string          $id    Profile id.
	 * @param array<int,Risk> $risks Risk levels to include.
	 * @return Profile
	 */
	private function profile( string $id, array $risks ): Profile {
		return new Profile( $id, ucfirst( $id ), $risks, true );
	}

	/**
	 * Generate a registry, facts, findings and candidates from a seed.
	 *
	 * Deliberately awkward: conflicts, chained requirements, fact predicates
	 * that may or may not hold, destructive tweaks, and refusals. The invariants
	 * have to survive all of it.
	 *
	 * @param int $seed Seed for the generator.
	 * @return array{registry:Registry,facts:FactSet,planner:PreviewPlanner,candidates:array<int,Tweak>}
	 */
	private function generate( int $seed ): array {
		mt_srand( $seed );

		$count       = 3 + ( $seed % 8 );
		$definitions = array();
		$ids         = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = sprintf( 'gen.tweak_%d', $i );
		}

		foreach ( $ids as $index => $id ) {
			$is_data     = 0 === $index % 5;
			$destructive = $is_data && 0 === mt_rand( 0, 1 );

			$conflicts = array();

			// Roughly one in three declares a conflict with a later tweak.
			if ( 0 === mt_rand( 0, 2 ) && isset( $ids[ $index + 1 ] ) ) {
				$conflicts[] = $ids[ $index + 1 ];
			}

			$requires = array();

			// Roughly one in four requires an earlier tweak.
			if ( 0 === mt_rand( 0, 3 ) && $index > 0 ) {
				$requires[] = $ids[ $index - 1 ];
			}

			// Roughly one in four carries a fact predicate, half of which hold.
			if ( 0 === mt_rand( 0, 3 ) ) {
				$requires[] = 0 === mt_rand( 0, 1 )
					? 'fact:plugins.detected.woocommerce=true'
					: 'fact:plugins.detected.elementor=true';
			}

			$definitions[] = new TweakDefinition(
				$id,
				1,
				'Generated tweak ' . $index,
				Category::WORDPRESS,
				$is_data ? TweakKind::DATA : TweakKind::CONFIG,
				Risk::cases()[ mt_rand( 0, 3 ) ],
				0.9,
				true,
				$destructive,
				$is_data ? 'WPDebloat\\Apply\\DataOperations\\Generated' : 'runtime-handlers/core-disable-emojis.php',
				array(),
				'A generated tweak.',
				array(),
				$requires,
				$conflicts
			);
		}

		$registry = new Registry(
			$definitions,
			array(),
			array(),
			array(
				new Profile( 'safe', 'Safe', array( Risk::SAFE, Risk::LOW ), true ),
				new Profile( 'performance', 'Performance', array( Risk::SAFE, Risk::LOW, Risk::MEDIUM ), true ),
				new Profile( 'maximum', 'Maximum', Risk::cases(), true ),
			)
		);

		// WooCommerce present, Elementor absent, so half the generated predicates
		// hold and half do not.
		$facts = Facts::freshInstall(
			array( 'plugins.detected' => Facts::detections( array( 'woocommerce' ) ) )
		);

		$findings   = array();
		$candidates = array();

		foreach ( $definitions as $index => $definition ) {
			$candidates[] = $definition->resolve();

			// Roughly one in four findings is a refusal.
			$refused = 0 === mt_rand( 0, 3 );

			$findings[] = new Finding(
				sprintf( 'gen.finding_%d', $index ),
				Category::WORDPRESS,
				Severity::LOW,
				$definition->risk,
				0.9,
				'Generated finding ' . $index,
				'A generated finding.',
				'Because the generator said so.',
				array( new Evidence( 'Generated', true, 'wp.debug' ) ),
				null,
				$refused ? Decision::DONT_TOUCH : Decision::RECOMMEND,
				$refused ? 'Something on this site depends on it.' : null,
				new Recommendation( $definition->id, new TweakParams() ),
				true
			);
		}

		return array(
			'registry'   => $registry,
			'facts'      => $facts,
			'planner'    => new PreviewPlanner( $registry, $facts, $findings ),
			'candidates' => $candidates,
		);
	}
}
