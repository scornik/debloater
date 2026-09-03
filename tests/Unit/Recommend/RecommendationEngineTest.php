<?php
/**
 * The recommendation engine, intent, risk and the worked examples.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Recommend;

use PHPUnit\Framework\TestCase;
use Debloater\Analyze\Analyzer;
use Debloater\Analyze\Rules;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Decision;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Risk;
use Debloater\Recommend\CompatibilityResolver;
use Debloater\Recommend\FactPredicate;
use Debloater\Recommend\IntentProfile;
use Debloater\Recommend\PreviewPlanner;
use Debloater\Recommend\RecommendationEngine;
use Debloater\Recommend\RiskEngine;
use Debloater\Registry\Loader;
use Debloater\Registry\Profile;
use Debloater\Registry\Registry;
use Debloater\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §17 Phase 4, including the two worked Heartbeat examples.
 */
final class RecommendationEngineTest extends TestCase {

	/**
	 * The worked example: a blog with one administrator and no WooCommerce gets
	 * 120 s.
	 *
	 * @return void
	 */
	public function test_heartbeat_is_120_seconds_for_a_quiet_blog(): void {
		$facts = Facts::freshInstall( array( 'users.admin_count' => 1 ) );
		$tweak = $this->recommendedTweak( $facts, 'core.heartbeat_interval' );

		$this->assertNotNull( $tweak );
		$this->assertSame( 120, $tweak->params->int( 'interval' ) );
	}

	/**
	 * The other worked example: a store gets 60 s.
	 *
	 * @return void
	 */
	public function test_heartbeat_is_60_seconds_for_a_store(): void {
		$facts = Facts::busyStore( array( 'users.recent_editors_7d' => 1 ) );
		$tweak = $this->recommendedTweak( $facts, 'core.heartbeat_interval' );

		$this->assertNotNull( $tweak );
		$this->assertSame( 60, $tweak->params->int( 'interval' ) );
	}

	/**
	 * A refused finding contributes no tweak, whatever the profile.
	 *
	 * @return void
	 */
	public function test_a_refused_finding_contributes_no_tweak(): void {
		$facts    = Facts::busyStore();
		$analysis = $this->analyzer()->analyze( $facts );

		$this->assertSame(
			Decision::DONT_TOUCH,
			$analysis->find( 'wp.heartbeat.aggressive' )?->decision,
			'the fixture is meant to trigger the Heartbeat refusal'
		);

		$engine          = new RecommendationEngine( $this->registry(), $facts );
		$recommendations = $engine->recommend( $analysis->findings );

		$this->assertFalse( $recommendations->includes( 'core.heartbeat_interval' ) );
	}

	/**
	 * The risk engine raises a level on an unrecognised host, and only by one.
	 *
	 * @return void
	 */
	public function test_risk_is_raised_once_on_an_unknown_host(): void {
		$known   = $this->riskEngine( Facts::freshInstall( array( 'env.host_vendor' => 'kinsta' ) ) );
		$unknown = $this->riskEngine( Facts::freshInstall( array( 'env.host_vendor' => 'unknown' ) ) );

		$tweak = $this->registry()->tweak( 'core.remove_generator' )->resolve();

		$this->assertSame( Risk::SAFE, $known->assess( $tweak ) );
		$this->assertSame( Risk::LOW, $unknown->assess( $tweak ) );
	}

	/**
	 * Two reasons still raise the risk by only one level: more care, not alarm.
	 *
	 * @return void
	 */
	public function test_risk_is_raised_at_most_one_level(): void {
		$facts    = Facts::busyStore();
		$analysis = $this->analyzer()->analyze( $facts );
		$engine   = $this->riskEngine( $facts );

		$finding = $analysis->find( 'wp.jquery_migrate.loaded' );

		$this->assertNotNull( $finding );

		$tweak = $this->registry()->tweak( 'core.remove_jquery_migrate' )->resolve();

		// Medium raised once is high, and no further however many reasons apply.
		$this->assertSame( Risk::HIGH, $engine->assess( $tweak, $finding->withConfidence( 0.8, 3 ) ) );
	}

	/**
	 * Risk only ever goes up.
	 *
	 * @return void
	 */
	public function test_risk_never_goes_down(): void {
		$engine = $this->riskEngine( Facts::freshInstall( array( 'env.host_vendor' => 'unknown' ) ) );

		foreach ( $this->registry()->all() as $definition ) {
			$tweak = $definition->resolve();

			$this->assertGreaterThanOrEqual(
				$tweak->risk->rank(),
				$engine->assess( $tweak )->rank(),
				$definition->id
			);
		}
	}

	/**
	 * A raised risk comes with a reason, so the level does not silently
	 * contradict the documentation.
	 *
	 * @return void
	 */
	public function test_a_raised_risk_is_explained(): void {
		$engine = $this->riskEngine( Facts::freshInstall( array( 'env.host_vendor' => 'unknown' ) ) );
		$tweak  = $this->registry()->tweak( 'core.remove_generator' )->resolve();

		$this->assertNotEmpty( $engine->reasons( $tweak ) );
	}

	/**
	 * The unstated intent is the cautious one and unlocks nothing.
	 *
	 * @return void
	 */
	public function test_the_default_intent_is_cautious(): void {
		$intent = IntentProfile::unstated();

		$this->assertSame( 'other', $intent->site_type );
		$this->assertSame( 'balanced', $intent->priority );
		$this->assertFalse( $intent->isStated() );
		$this->assertSame( 'safe', $intent->suggestedProfile() );
	}

	/**
	 * Only an explicitly aggressive, non-transactional site suggests a wider
	 * profile. A store never does, whatever it says.
	 *
	 * @return void
	 */
	public function test_only_an_aggressive_non_transactional_site_widens_the_profile(): void {
		$this->assertSame( 'performance', ( new IntentProfile( 'blog', 'aggressive' ) )->suggestedProfile() );
		$this->assertSame( 'safe', ( new IntentProfile( 'blog', 'balanced' ) )->suggestedProfile() );
		$this->assertSame( 'safe', ( new IntentProfile( 'store', 'aggressive' ) )->suggestedProfile() );
		$this->assertSame( 'safe', ( new IntentProfile( 'membership', 'aggressive' ) )->suggestedProfile() );
	}

	/**
	 * A malformed stored profile falls back to the defaults rather than
	 * throwing: a broken option should not break the dashboard.
	 *
	 * @return void
	 */
	public function test_a_malformed_stored_intent_falls_back(): void {
		$intent = IntentProfile::fromArray(
			array(
				'site_type' => 'spaceship',
				'priority'  => 'reckless',
			)
		);

		$this->assertSame( 'other', $intent->site_type );
		$this->assertSame( 'balanced', $intent->priority );
	}

	/**
	 * User input, by contrast, is rejected rather than quietly corrected.
	 *
	 * @return void
	 */
	public function test_malformed_user_input_is_rejected(): void {
		$this->expectException( ContractViolation::class );

		IntentProfile::fromInput( array( 'site_type' => 'spaceship' ) );
	}

	/**
	 * No profile admits a destructive tweak, whatever its flag says.
	 *
	 * @return void
	 */
	public function test_no_profile_admits_a_destructive_tweak(): void {
		$destructive = \Debloater\Tests\Unit\Support\Build::destructiveTweak();

		foreach ( $this->registry()->profiles() as $id => $profile ) {
			$this->assertFalse( $profile->admits( $destructive ), $id );
		}

		// Even a profile that claims otherwise.
		$permissive = new Profile( 'permissive', 'Permissive', Risk::cases(), false );

		$this->assertFalse( $permissive->admits( $destructive ) );
	}

	/**
	 * The three shipped profiles widen in the documented order.
	 *
	 * @return void
	 */
	public function test_the_shipped_profiles_widen_in_order(): void {
		$registry = $this->registry();

		$this->assertSame( Risk::LOW, $registry->profile( 'safe' )->maximumRisk() );
		$this->assertSame( Risk::MEDIUM, $registry->profile( 'performance' )->maximumRisk() );
		$this->assertSame( Risk::HIGH, $registry->profile( 'maximum' )->maximumRisk() );
	}

	/**
	 * A fact predicate that holds is satisfied; one that does not is not.
	 *
	 * @return void
	 */
	public function test_fact_predicates_evaluate(): void {
		$facts = Facts::freshInstall(
			array( 'plugins.detected' => Facts::detections( array( 'woocommerce' ) ) )
		);

		$this->assertTrue(
			FactPredicate::parse( 'fact:plugins.detected.woocommerce=true' )->isSatisfiedBy( $facts )
		);
		$this->assertFalse(
			FactPredicate::parse( 'fact:plugins.detected.elementor=true' )->isSatisfiedBy( $facts )
		);
		$this->assertTrue(
			FactPredicate::parse( 'fact:wp.debug=false' )->isSatisfiedBy( $facts )
		);
		$this->assertTrue(
			FactPredicate::parse( 'fact:wp.heartbeat_interval=15' )->isSatisfiedBy( $facts )
		);
	}

	/**
	 * A fact the scan never observed is unresolved, not satisfied.
	 *
	 * @return void
	 */
	public function test_an_unobserved_fact_is_not_satisfied(): void {
		$predicate = FactPredicate::parse( 'fact:wp.dashicons_frontend=true' );

		$this->assertFalse( $predicate->isObservableIn( new FactSet() ) );
		$this->assertFalse( $predicate->isSatisfiedBy( new FactSet() ) );
	}

	/**
	 * A malformed predicate is refused at parse time.
	 *
	 * @return void
	 */
	public function test_a_malformed_predicate_is_refused(): void {
		$this->expectException( ContractViolation::class );

		FactPredicate::parse( 'fact:no_equals_sign' );
	}

	/**
	 * The compatibility resolver only counts what is present.
	 *
	 * @return void
	 */
	public function test_compatibility_only_counts_what_is_present(): void {
		$without = new CompatibilityResolver( $this->registry(), Facts::freshInstall() );
		$with    = new CompatibilityResolver( $this->registry(), Facts::busyStore() );

		$this->assertSame( array(), $without->dependentsOn( 'rest:public' ) );
		$this->assertSame( array( 'contact-form-7' ), $with->dependentNames( 'rest:public' ) );
		$this->assertTrue( $with->isSpokenFor( 'jquery' ) );
		$this->assertFalse( $without->isSpokenFor( 'jquery' ) );
	}

	/**
	 * Components with no compatibility rule are reported rather than assumed
	 * harmless.
	 *
	 * @return void
	 */
	public function test_unmapped_components_are_reported(): void {
		$resolver = new CompatibilityResolver(
			$this->registry(),
			Facts::freshInstall( array( 'plugins.detected' => Facts::detections( array( 'rank-math', 'woocommerce' ) ) ) )
		);

		$this->assertSame( array( 'rank-math' ), $resolver->unmappedComponents() );
	}

	/**
	 * The safe plan over a real analysis contains only safe and low-risk,
	 * non-destructive changes.
	 *
	 * @return void
	 */
	public function test_the_safe_plan_over_a_real_analysis(): void {
		$facts    = Facts::freshInstall();
		$analysis = $this->analyzer()->analyze( $facts );
		$engine   = new RecommendationEngine( $this->registry(), $facts );
		$planner  = new PreviewPlanner( $this->registry(), $facts, $analysis->findings );

		$result = $planner->safePlan( $engine->recommend( $analysis->findings )->tweaks );

		$this->assertGreaterThan( 0, $result->count(), 'a default install has safe changes available' );

		foreach ( $result->plan->tweaks as $tweak ) {
			$this->assertTrue( $tweak->risk->isSafePlanEligible(), $tweak->id );
			$this->assertFalse( $tweak->destructive, $tweak->id );
		}

		$this->assertFalse( $result->includes( 'core.remove_jquery_migrate' ), 'medium risk stays out' );
	}

	/**
	 * The plan says, in words, that nothing will be deleted.
	 *
	 * @return void
	 */
	public function test_the_plan_states_what_it_will_not_do(): void {
		$facts    = Facts::freshInstall();
		$analysis = $this->analyzer()->analyze( $facts );
		$engine   = new RecommendationEngine( $this->registry(), $facts );
		$planner  = new PreviewPlanner( $this->registry(), $facts, $analysis->findings );

		$plan = $planner->safePlan( $engine->recommend( $analysis->findings )->tweaks )->plan;

		$this->assertContains( 'Nothing will be deleted.', $plan->will_not );
		$this->assertNotEmpty( $plan->will_change );
		$this->assertFalse( $plan->destructive );
	}

	/**
	 * The report never claims speed. BUILD-SPEC §17 Phase 9 forbids the word
	 * outright, and the plan text is the first place it could creep in.
	 *
	 * @return void
	 */
	public function test_plan_text_never_claims_speed(): void {
		$facts    = Facts::busyStore();
		$analysis = $this->analyzer()->analyze( $facts );
		$engine   = new RecommendationEngine( $this->registry(), $facts );
		$planner  = new PreviewPlanner( $this->registry(), $facts, $analysis->findings );

		$plan = $planner->plan(
			$engine->recommend( $analysis->findings )->tweaks,
			$this->registry()->profile( 'maximum' )
		)->plan;

		$text = strtolower( implode( ' ', array_merge( $plan->will_change, $plan->will_not ) ) );

		foreach ( array( 'faster', 'speed up', 'speeds up', 'quicker', 'load time', 'seconds faster' ) as $claim ) {
			$this->assertStringNotContainsString( $claim, $text, 'plan text must not claim speed' );
		}
	}

	/**
	 * The recommended tweak for a finding, or null.
	 *
	 * @param FactSet $facts    Facts to analyze.
	 * @param string  $tweak_id Tweak id to look for.
	 * @return \Debloater\Contracts\Tweak|null
	 */
	private function recommendedTweak( FactSet $facts, string $tweak_id ): ?\Debloater\Contracts\Tweak {
		$analysis = $this->analyzer()->analyze( $facts );
		$engine   = new RecommendationEngine( $this->registry(), $facts );

		return $engine->recommend( $analysis->findings )->get( $tweak_id );
	}

	/**
	 * A risk engine over the given facts.
	 *
	 * @param FactSet $facts Facts from a scan.
	 * @return RiskEngine
	 */
	private function riskEngine( FactSet $facts ): RiskEngine {
		return new RiskEngine( $facts );
	}

	/**
	 * An analyzer over the shipped rule set.
	 *
	 * @return Analyzer
	 */
	private function analyzer(): Analyzer {
		return new Analyzer( Rules::all(), $this->registry() );
	}

	/**
	 * The shipped registry.
	 *
	 * @return Registry
	 */
	private function registry(): Registry {
		return ( new Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();
	}
}
