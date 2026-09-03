<?php
/**
 * What the plugin list is allowed to conclude.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use WPDebloat\Analyze\Analyzer;
use WPDebloat\Analyze\HostOptimizerRules;
use WPDebloat\Analyze\Rules;
use WPDebloat\Analyze\Rules\AbandonedPluginsRule;
use WPDebloat\Analyze\Rules\DuplicateFunctionalityRule;
use WPDebloat\Analyze\Rules\HostOptimizerRule;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Severity;
use WPDebloat\Registry\Loader;
use WPDebloat\Registry\Registry;
use WPDebloat\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §17 Phase 11.
 *
 * Every rule here is `info`. None of them proposes a change, and the tests are
 * as interested in that as in the counting: a rule that started recommending
 * plugin deletions would pass a test about how many duplicates it found.
 */
final class PluginIntelligenceTest extends TestCase {

	/**
	 * Two SEO plugins and two cache plugins produce one finding naming both
	 * pairs.
	 *
	 * @return void
	 */
	public function test_two_seo_and_two_cache_plugins_are_reported(): void {
		$finding = ( new DuplicateFunctionalityRule() )->analyze( $this->crowdedSite() );

		$this->assertNotNull( $finding );
		$this->assertSame( 'plugins.duplicate_functionality', $finding->id );
		$this->assertSame( Severity::INFO, $finding->severity );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertNull( $finding->recommendation, 'this rule must never propose a change' );

		$this->assertStringContainsString( '2 jobs', $finding->title );

		foreach ( array( 'wordpress-seo', 'seo-by-rank-math', 'wp-rocket', 'litespeed-cache' ) as $slug ) {
			$this->assertStringContainsString( $slug, $finding->summary, $slug . ' should be named' );
		}

		$this->assertStringContainsString(
			'Page caching',
			$finding->summary,
			'the category is named by its label, not its id'
		);
	}

	/**
	 * The reasoning is specific to the categories that doubled up.
	 *
	 * A generic "you have duplicates" sentence would be useless; what a person
	 * needs is why two of *this* kind matters, which differs by kind.
	 *
	 * @return void
	 */
	public function test_the_reasoning_is_the_one_for_those_categories(): void {
		$finding = ( new DuplicateFunctionalityRule() )->analyze( $this->crowdedSite() );

		$this->assertNotNull( $finding );
		$this->assertStringContainsString( "caches the other's output", $finding->why );
		$this->assertStringContainsString( 'same <head>', $finding->why );
		$this->assertStringContainsString( 'will deactivate or delete', $finding->why );
	}

	/**
	 * One plugin in a category is not a duplicate.
	 *
	 * @return void
	 */
	public function test_one_of_a_kind_says_nothing(): void {
		$facts = Facts::freshInstall(
			array(
				'plugins.active'     => array( 'wordpress-seo/wp-seo.php' ),
				'plugins.categories' => array(
					array(
						'plugin'   => 'wordpress-seo',
						'category' => 'seo',
						'label'    => 'SEO',
					),
				),
			)
		);

		$this->assertNull( ( new DuplicateFunctionalityRule() )->analyze( $facts ) );
	}

	/**
	 * Abandonment is detected with the network switched off, and says so.
	 *
	 * @return void
	 */
	public function test_abandonment_is_detected_without_the_network(): void {
		$finding = ( new AbandonedPluginsRule() )->analyze( $this->neglectedSite( 'file_mtime' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertStringContainsString( 'Ancient Slider', $finding->summary );
		$this->assertStringNotContainsString( 'Fresh Forms', $finding->summary );

		$this->assertStringContainsString(
			'on this server',
			$finding->title,
			'the weaker reading must not be worded as a release date'
		);

		$this->assertSame(
			AbandonedPluginsRule::CONFIDENCE_FILE_MTIME,
			$finding->confidence,
			'a file date is a much weaker signal than a release date and must be scored as one'
		);
	}

	/**
	 * With the opt-in lookup, the same site is described in stronger terms.
	 *
	 * @return void
	 */
	public function test_the_opt_in_reading_is_about_releases(): void {
		$finding = ( new AbandonedPluginsRule() )->analyze( $this->neglectedSite( 'wp_org' ) );

		$this->assertNotNull( $finding );
		$this->assertStringContainsString( 'no release in two years', $finding->title );
		$this->assertSame( AbandonedPluginsRule::CONFIDENCE_WP_ORG, $finding->confidence );
	}

	/**
	 * A plugin wordpress.org did not answer for is not called abandoned.
	 *
	 * Falling back to the file date here would quietly mix two different claims
	 * inside one finding: the user asked about releases and would be shown a
	 * plugin flagged on file dates without being told.
	 *
	 * @return void
	 */
	public function test_a_plugin_with_no_release_date_is_not_guessed_at(): void {
		$long_ago = time() - ( 5 * 365 * DAY_IN_SECONDS );

		$facts = Facts::freshInstall(
			array(
				'plugins.active'        => array( 'bespoke/bespoke.php' ),
				'plugins.update_source' => 'wp_org',
				'plugins.meta'          => array(
					// On disk for five years, and not on wordpress.org at all.
					'bespoke/bespoke.php' => array(
						'name'       => 'Bespoke',
						'version'    => '1.0.0',
						'file_mtime' => $long_ago,
					),
				),
			)
		);

		$this->assertNull( ( new AbandonedPluginsRule() )->analyze( $facts ) );
	}

	/**
	 * An optimizer on the site is reported, without claiming it has done
	 * anything.
	 *
	 * @return void
	 */
	public function test_an_optimizer_on_the_site_is_reported(): void {
		$finding = ( new HostOptimizerRule() )->analyze( Facts::busyStore() );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertStringContainsString( 'LiteSpeed Cache', $finding->title );

		foreach ( array( 'already handled', 'already dealt', 'has handled' ) as $claim ) {
			$this->assertStringNotContainsStringIgnoringCase(
				$claim,
				$finding->title . ' ' . $finding->summary . ' ' . $finding->why,
				'presence is not a claim that anything has been handled'
			);
		}
	}

	/**
	 * A finding on ground another tool also covers gains a sentence, and keeps
	 * everything else.
	 *
	 * The severity stays where it was on purpose. The finding exists because the
	 * scan observed the thing still happening, so nothing has handled it, and
	 * marking a real cost as `info` would understate what the site is paying.
	 *
	 * @return void
	 */
	public function test_a_covered_finding_gains_a_sentence_and_keeps_its_weight(): void {
		$analysis = ( new Analyzer( Rules::all(), $this->registry() ) )->analyze( Facts::busyStore() );

		$emojis = null;

		foreach ( $analysis->findings as $finding ) {
			if ( 'wp.emojis.loaded' === $finding->id ) {
				$emojis = $finding;
			}
		}

		$this->assertNotNull( $emojis, 'the busy store still has emojis enabled' );
		$this->assertStringContainsString( 'LiteSpeed Cache', $emojis->why );
		$this->assertStringContainsString( 'HTML Settings', $emojis->why );

		$this->assertSame(
			Decision::RECOMMEND,
			$emojis->decision,
			'the choice stays with the user rather than being taken away'
		);
		$this->assertNotSame(
			Severity::INFO,
			$emojis->severity,
			'the emoji script is still loading, so the cost is still real'
		);
	}

	/**
	 * A finding nothing covers is returned untouched.
	 *
	 * @return void
	 */
	public function test_an_uncovered_finding_is_left_alone(): void {
		$rules   = new HostOptimizerRules( $this->registry(), Facts::busyStore() );
		$finding = \WPDebloat\Tests\Unit\Support\Build::finding( 'wp.shortlink.exposed' );

		$this->assertFalse( $rules->covers( 'wp.shortlink.exposed' ) );
		$this->assertSame( $finding->why, $rules->apply( $finding )->why );
	}

	/**
	 * With no optimizers on the site, nothing is added to anything.
	 *
	 * @return void
	 */
	public function test_a_site_with_no_optimizer_is_untouched(): void {
		$rules   = new HostOptimizerRules( $this->registry(), Facts::freshInstall() );
		$finding = \WPDebloat\Tests\Unit\Support\Build::finding( 'wp.emojis.loaded' );

		$this->assertFalse( $rules->covers( 'wp.emojis.loaded' ) );
		$this->assertSame( $finding->why, $rules->apply( $finding )->why );
	}

	/**
	 * None of the three rules can produce a plannable finding.
	 *
	 * §17 Phase 11 is explicit: all info, no automatic action. This is the
	 * assertion that would fail if somebody later gave one of them a
	 * recommendation.
	 *
	 * @return void
	 */
	public function test_no_plugin_rule_proposes_a_change(): void {
		$facts = Facts::busyStore(
			array_merge(
				$this->crowdedFacts(),
				array( 'plugins.update_source' => 'file_mtime' )
			)
		);

		foreach ( array( new DuplicateFunctionalityRule(), new AbandonedPluginsRule(), new HostOptimizerRule() ) as $rule ) {
			$finding = $rule->analyze( $facts );

			if ( null === $finding ) {
				continue;
			}

			$this->assertSame( Decision::INFO, $finding->decision, $rule->findingId() );
			$this->assertFalse( $finding->isPlannable(), $rule->findingId() );
			$this->assertNull( $finding->recommendedTweakId(), $rule->findingId() );
			$this->assertSame( 0, $finding->scorePenalty(), $rule->findingId() . ' must not cost the site points' );
		}
	}

	/**
	 * A site running two SEO plugins and two page caches.
	 *
	 * @return FactSet
	 */
	private function crowdedSite(): FactSet {
		return Facts::freshInstall( $this->crowdedFacts() );
	}

	/**
	 * The overrides that make a site crowded.
	 *
	 * @return array<string,mixed>
	 */
	private function crowdedFacts(): array {
		return array(
			'plugins.active'     => array(
				'litespeed-cache/litespeed-cache.php',
				'seo-by-rank-math/rank-math.php',
				'wordpress-seo/wp-seo.php',
				'wp-rocket/wp-rocket.php',
			),
			'plugins.categories' => array(
				array(
					'plugin'   => 'litespeed-cache',
					'category' => 'cache',
					'label'    => 'Page caching',
				),
				array(
					'plugin'   => 'wp-rocket',
					'category' => 'cache',
					'label'    => 'Page caching',
				),
				array(
					'plugin'   => 'seo-by-rank-math',
					'category' => 'seo',
					'label'    => 'SEO',
				),
				array(
					'plugin'   => 'wordpress-seo',
					'category' => 'seo',
					'label'    => 'SEO',
				),
			),
		);
	}

	/**
	 * One plugin untouched for years, one updated yesterday.
	 *
	 * @param string $source Which reading is in play.
	 * @return FactSet
	 */
	private function neglectedSite( string $source ): FactSet {
		$long_ago  = time() - ( 4 * 365 * DAY_IN_SECONDS );
		$yesterday = time() - DAY_IN_SECONDS;

		return Facts::freshInstall(
			array(
				'plugins.active'        => array( 'ancient-slider/slider.php', 'fresh-forms/forms.php' ),
				'plugins.update_source' => $source,
				'plugins.meta'          => array(
					'ancient-slider/slider.php' => array(
						'name'         => 'Ancient Slider',
						'version'      => '1.0.0',
						'file_mtime'   => $long_ago,
						'last_updated' => gmdate( 'Y-m-d', $long_ago ),
					),
					'fresh-forms/forms.php'     => array(
						'name'         => 'Fresh Forms',
						'version'      => '9.9.9',
						'file_mtime'   => $yesterday,
						'last_updated' => gmdate( 'Y-m-d', $yesterday ),
					),
				),
			)
		);
	}

	/**
	 * The shipped registry.
	 *
	 * @return Registry
	 */
	private function registry(): Registry {
		return ( new Loader( WPDEBLOAT_TESTS_ROOT . '/registry' ) )->load();
	}
}
