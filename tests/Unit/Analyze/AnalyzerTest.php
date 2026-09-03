<?php
/**
 * The analyzer, confidence, refusals and the score.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Debloater\Analyze\Analyzer;
use Debloater\Analyze\ConfidenceCalculator;
use Debloater\Analyze\Rules;
use Debloater\Analyze\Score;
use Debloater\Contracts\Category;
use Debloater\Contracts\Decision;
use Debloater\Contracts\FactSet;
use Debloater\Registry\Loader;
use Debloater\Registry\Registry;
use Debloater\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §6, §12 and §17 Phase 3.
 */
final class AnalyzerTest extends TestCase {

	/**
	 * A fresh install still has plenty to say: every core feature is on.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_produces_findings(): void {
		$result = $this->analyzer()->analyze( Facts::freshInstall() );

		$this->assertGreaterThanOrEqual( 8, $result->count() );
		$this->assertTrue( $result->has( 'wp.generator.exposed' ) );
	}

	/**
	 * BUILD-SPEC §17 Phase 3 exit: a busy site yields at least twelve findings,
	 * including at least one the analyzer refuses to act on.
	 *
	 * @return void
	 */
	public function test_a_busy_store_yields_twelve_findings_including_a_refusal(): void {
		$result = $this->analyzer()->analyze( Facts::busyStore() );

		$this->assertGreaterThanOrEqual( 12, $result->count(), 'BUILD-SPEC §17 Phase 3 exit criterion' );
		$this->assertGreaterThanOrEqual( 1, count( $result->dontTouch() ), 'at least one dont_touch' );
	}

	/**
	 * The Heartbeat refusal: several recent editors on a WooCommerce store.
	 *
	 * @return void
	 */
	public function test_heartbeat_is_refused_on_a_collaborative_store(): void {
		$finding = $this->analyzer()->analyze( Facts::busyStore() )->find( 'wp.heartbeat.aggressive' );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::DONT_TOUCH, $finding->decision );
		$this->assertNotNull( $finding->decision_reason );
		$this->assertStringContainsString( 'Heartbeat', (string) $finding->decision_reason );
		$this->assertFalse( $finding->isPlannable(), 'a refused finding must never reach a plan' );
	}

	/**
	 * The same store with one recent editor is not refused: the refusal is about
	 * collaboration, not about being a store.
	 *
	 * @return void
	 */
	public function test_heartbeat_is_not_refused_without_collaboration(): void {
		$finding = $this->analyzer()
			->analyze( Facts::busyStore( array( 'users.recent_editors_7d' => 1 ) ) )
			->find( 'wp.heartbeat.aggressive' );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::RECOMMEND, $finding->decision );
	}

	/**
	 * Nor is it refused on a collaborative site that is not a store, since the
	 * checkout-session half of the argument does not apply.
	 *
	 * @return void
	 */
	public function test_heartbeat_is_not_refused_on_a_collaborative_blog(): void {
		$finding = $this->analyzer()
			->analyze(
				Facts::freshInstall(
					array(
						'users.recent_editors_7d' => 4,
						'users.admin_count'       => 4,
					)
				)
			)
			->find( 'wp.heartbeat.aggressive' );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::RECOMMEND, $finding->decision );
	}

	/**
	 * A capability dependency refuses a finding and names the dependent.
	 *
	 * Elementor and WooCommerce both declare a dependency on jQuery, and
	 * jQuery Migrate rides with it.
	 *
	 * @return void
	 */
	public function test_a_declared_dependency_refuses_a_finding(): void {
		$facts = Facts::freshInstall(
			array(
				'plugins.detected'  => Facts::detections( array( 'contact-form-7' ) ),
				'wp.embeds_enabled' => true,
			)
		);

		$result  = $this->analyzer()->analyze( $facts );
		$finding = $result->find( 'wp.jquery_migrate.loaded' );

		$this->assertNotNull( $finding );

		// Contact Form 7 declares jquery, not jquery-migrate, so Migrate is not
		// refused by it — the vocabulary is precise on purpose.
		$this->assertSame( Decision::RECOMMEND, $finding->decision );
	}

	/**
	 * Detected dependents lower confidence even when they do not amount to a
	 * refusal.
	 *
	 * @return void
	 */
	public function test_dependents_lower_confidence(): void {
		$calculator = new ConfidenceCalculator( Facts::freshInstall() );

		$this->assertGreaterThan(
			$calculator->calculate( 0.95, 2 ),
			$calculator->calculate( 0.95, 0 ),
			'more dependents must mean less confidence'
		);
	}

	/**
	 * Confidence penalties apply for the reasons docs/SCORING.md gives.
	 *
	 * @return void
	 */
	public function test_confidence_penalties(): void {
		$clear = new ConfidenceCalculator( Facts::freshInstall() );

		$this->assertSame( array(), $clear->penalties(), 'a recognised host with no cache has nothing in the way' );
		$this->assertSame( 0.95, $clear->calculate( 0.95 ) );

		$murky = new ConfidenceCalculator(
			Facts::freshInstall(
				array(
					'env.host_vendor'  => 'unknown',
					'env.cache_plugin' => 'wp-rocket',
				)
			),
			true
		);

		$penalties = $murky->penalties( 1 );

		$this->assertArrayHasKey( 'unknown_host', $penalties );
		$this->assertArrayHasKey( 'cache_plugin', $penalties );
		$this->assertArrayHasKey( 'dependents', $penalties );
		$this->assertArrayHasKey( 'custom_code', $penalties );

		$this->assertLessThan( $clear->calculate( 0.95 ), $murky->calculate( 0.95, 1 ) );
		$this->assertCount( 4, $murky->reasons( 1 ) );
	}

	/**
	 * The dependent penalty stops compounding, so a caution never becomes a
	 * silent refusal.
	 *
	 * @return void
	 */
	public function test_the_dependent_penalty_is_capped(): void {
		$calculator = new ConfidenceCalculator( Facts::freshInstall() );

		$this->assertSame(
			$calculator->calculate( 0.95, ConfidenceCalculator::MAX_DEPENDENTS_COUNTED ),
			$calculator->calculate( 0.95, 50 )
		);
	}

	/**
	 * Confidence never falls to zero through penalties alone.
	 *
	 * @return void
	 */
	public function test_confidence_has_a_floor(): void {
		$calculator = new ConfidenceCalculator(
			Facts::freshInstall(
				array(
					'env.host_vendor'  => 'unknown',
					'env.cache_plugin' => 'wp-rocket',
				)
			),
			true
		);

		$this->assertGreaterThanOrEqual( ConfidenceCalculator::FLOOR, $calculator->calculate( 0.4, 10 ) );
	}

	/**
	 * Confidence is rounded, so the same site always prints the same figure.
	 *
	 * @return void
	 */
	public function test_confidence_is_deterministic(): void {
		$first  = $this->analyzer()->analyze( Facts::busyStore() );
		$second = $this->analyzer()->analyze( Facts::busyStore() );

		foreach ( $first->findings as $index => $finding ) {
			$this->assertSame( $finding->confidence, $second->findings[ $index ]->confidence );
		}
	}

	/**
	 * The whole analysis is deterministic: same facts, same findings, in the
	 * same order (BUILD-SPEC §2).
	 *
	 * @return void
	 */
	public function test_analysis_is_deterministic(): void {
		$first  = $this->analyzer()->analyze( Facts::busyStore() )->toArray();
		$second = $this->analyzer()->analyze( Facts::busyStore() )->toArray();

		$this->assertSame( $first, $second );
	}

	/**
	 * A rule that cannot evaluate is reported as such, not as silence.
	 *
	 * @return void
	 */
	public function test_rules_that_cannot_evaluate_are_reported(): void {
		$result = $this->analyzer()->analyze( new FactSet() );

		$this->assertSame( array(), $result->findings );
		$this->assertCount(
			count( \Debloater\Analyze\Rules::all() ),
			$result->not_evaluated,
			'with no facts, every rule must report that it could not evaluate'
		);
		$this->assertFalse( $result->isComplete() );
	}

	/**
	 * A complete scan reports nothing unevaluated.
	 *
	 * @return void
	 */
	public function test_a_complete_fact_set_evaluates_every_rule(): void {
		$result = $this->analyzer()->analyze( Facts::busyStore() );

		$this->assertSame( array(), $result->not_evaluated );
		$this->assertSame( array(), $result->failed );
		$this->assertTrue( $result->isComplete() );
	}

	/**
	 * Two rules claiming one finding id is refused.
	 *
	 * @return void
	 */
	public function test_duplicate_finding_ids_are_refused(): void {
		$rules    = Rules::all();
		$analyzer = new Analyzer( array_merge( $rules, array( $rules[0] ) ), $this->registry() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/One rule, one finding id/' );

		$analyzer->assertRulesAreDistinct();
	}

	/**
	 * The shipped rule set has no duplicates.
	 *
	 * @return void
	 */
	public function test_the_shipped_rule_set_is_distinct(): void {
		$this->analyzer()->assertRulesAreDistinct();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A perfect site scores 100.
	 *
	 * @return void
	 */
	public function test_a_site_with_no_findings_scores_full_marks(): void {
		$score = new Score( array() );

		$this->assertSame( 100, $score->headline() );

		foreach ( $score->subScores() as $category => $value ) {
			$this->assertSame( 100, $value, $category );
		}
	}

	/**
	 * Penalties follow the severity table in BUILD-SPEC §12.
	 *
	 * @return void
	 */
	public function test_score_penalties_follow_the_rubric(): void {
		$score = $this->analyzer()->analyze( Facts::busyStore() )->score();

		$penalties = $score->penaltiesFor( Category::DATABASE );

		foreach ( $penalties as $id => $penalty ) {
			$this->assertContains( $penalty, array( 0, 4, 10, 20 ), $id . ' must use a rubric penalty' );
		}
	}

	/**
	 * BUILD-SPEC §12: a refused finding contributes nothing to the score.
	 *
	 * Penalising a site for a configuration we have decided not to change would
	 * mean showing a number the user cannot improve without ignoring us.
	 *
	 * @return void
	 */
	public function test_refused_findings_do_not_lower_the_score(): void {
		$collaborative = $this->analyzer()->analyze( Facts::busyStore() );
		$solo          = $this->analyzer()->analyze( Facts::busyStore( array( 'users.recent_editors_7d' => 1 ) ) );

		$refused     = $collaborative->find( 'wp.heartbeat.aggressive' );
		$recommended = $solo->find( 'wp.heartbeat.aggressive' );

		$this->assertNotNull( $refused );
		$this->assertNotNull( $recommended );
		$this->assertSame( Decision::DONT_TOUCH, $refused->decision );
		$this->assertSame( Decision::RECOMMEND, $recommended->decision );

		$this->assertGreaterThanOrEqual(
			$solo->score()->subScore( Category::WORDPRESS ),
			$collaborative->score()->subScore( Category::WORDPRESS ),
			'refusing a change must not cost the site points'
		);
	}

	/**
	 * The score is deterministic and bounded.
	 *
	 * @return void
	 */
	public function test_score_is_deterministic_and_bounded(): void {
		$result = $this->analyzer()->analyze( Facts::busyStore() );

		$this->assertSame( $result->score()->toArray(), $result->score()->toArray() );

		$headline = $result->score()->headline();

		$this->assertGreaterThanOrEqual( 0, $headline );
		$this->assertLessThanOrEqual( 100, $headline );

		foreach ( $result->score()->subScores() as $category => $value ) {
			$this->assertGreaterThanOrEqual( 0, $value, $category );
			$this->assertLessThanOrEqual( 100, $value, $category );
		}
	}

	/**
	 * The sub-scores are the ones the rubric names, and Performance is not among
	 * them (BUILD-SPEC §12, locked decision #1).
	 *
	 * v1 had the five §12 lists. v2 adds Admin, which became scoreable in
	 * Phase 12 once there were admin findings to put in it — a sub-score over an
	 * empty category is a perfect ten awarded for nothing. Assets is still
	 * absent and stays absent until Phase 13.
	 *
	 * Performance is the one that must never appear. The score is a
	 * configuration score; a "performance" sub-score would be a speed claim
	 * dressed as a measurement, which is exactly what the Meter exists to do
	 * properly instead.
	 *
	 * @return void
	 */
	public function test_the_sub_scores_are_the_ones_the_rubric_names(): void {
		$score = new Score( array() );

		$this->assertSame(
			array( 'wordpress', 'configuration', 'database', 'plugins', 'maintenance', 'admin' ),
			array_keys( $score->subScores() )
		);
		$this->assertArrayNotHasKey( 'performance', $score->subScores() );
		$this->assertArrayNotHasKey( 'assets', $score->subScores() );
		$this->assertSame( '2.0', Score::RUBRIC_VERSION );
	}

	/**
	 * Findings in a category v1 does not score are reported rather than dropped.
	 *
	 * @return void
	 */
	public function test_unscored_categories_are_reported(): void {
		$unscored = $this->analyzer()->analyze( Facts::freshInstall() )->score()->unscoredCategories();

		$this->assertArrayHasKey( 'assets', $unscored, 'asset findings must still be visible' );
		$this->assertGreaterThan( 0, $unscored['assets'] );
	}

	/**
	 * The dont-touch count is reported separately, for the dashboard's
	 * "No action recommended" line.
	 *
	 * @return void
	 */
	public function test_counts_by_decision(): void {
		$result = $this->analyzer()->analyze( Facts::busyStore() );
		$counts = $result->score()->countsByDecision();

		$this->assertSame( count( $result->recommended() ), $counts['recommend'] );
		$this->assertSame( count( $result->dontTouch() ), $counts['dont_touch'] );
		$this->assertSame( count( $result->informational() ), $counts['info'] );
		$this->assertSame( $result->count(), array_sum( $counts ) );
	}

	/**
	 * Risk counts only cover findings that actually propose something.
	 *
	 * @return void
	 */
	public function test_counts_by_risk_only_include_actionable_findings(): void {
		$result = $this->analyzer()->analyze( Facts::busyStore() );

		$this->assertSame( count( $result->recommended() ), array_sum( $result->score()->countsByRisk() ) );
	}

	/**
	 * The analysis round-trips into a run payload without loss.
	 *
	 * @return void
	 */
	public function test_findings_survive_serialisation(): void {
		$result = $this->analyzer()->analyze( Facts::busyStore() );

		foreach ( $result->toArray()['findings'] as $index => $data ) {
			$this->assertEquals( $result->findings[ $index ], \Debloater\Contracts\Finding::fromArray( $data ) );
		}
	}

	/**
	 * An analyzer over the shipped rule set and registry.
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
