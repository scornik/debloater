<?php
/**
 * Every analyzer rule, fixture by fixture.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use WPDebloat\Analyze\Rules;
use WPDebloat\Contracts\AnalyzerRuleInterface;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Registry\Loader;
use WPDebloat\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §17 Phase 3 requires each rule to be tested for firing, not
 * firing, and — where applicable — refusing.
 *
 * The rules are also tested as a set, because several of the guarantees are
 * about the set rather than any one member: one rule per finding id, every
 * recommended tweak actually exists, every finding carries evidence with real
 * provenance.
 */
final class RulesTest extends TestCase {

	/**
	 * Every rule in the set, for per-rule assertions.
	 *
	 * @return array<string,array{0:AnalyzerRuleInterface}>
	 */
	public static function ruleProvider(): array {
		$cases = array();

		foreach ( Rules::all() as $rule ) {
			$cases[ $rule->findingId() ] = array( $rule );
		}

		return $cases;
	}

	/**
	 * No two rules claim the same finding id.
	 *
	 * @return void
	 */
	public function test_one_rule_per_finding_id(): void {
		$ids = array();

		foreach ( Rules::all() as $rule ) {
			$this->assertNotContains( $rule->findingId(), $ids, 'duplicate finding id' );

			$ids[] = $rule->findingId();
		}

		// Derived rather than hard-coded: the invariant is that no two rules
		// claim the same id, and a literal count only says when somebody last
		// updated the number. The floor stops the list being emptied by
		// accident.
		$this->assertCount( count( Rules::all() ), $ids );
		$this->assertGreaterThanOrEqual( 20, count( $ids ), 'rules have gone missing from the set' );
	}

	/**
	 * Every rule declares a base confidence in range.
	 *
	 * @dataProvider ruleProvider
	 * @param AnalyzerRuleInterface $rule Rule under test.
	 * @return void
	 */
	public function test_base_confidence_is_a_probability( AnalyzerRuleInterface $rule ): void {
		$base = $rule->baseConfidence();

		$this->assertGreaterThan( 0.0, $base, $rule->findingId() );
		$this->assertLessThanOrEqual( 1.0, $base, $rule->findingId() );
	}

	/**
	 * Every rule that fires produces a finding whose id matches its own.
	 *
	 * @dataProvider ruleProvider
	 * @param AnalyzerRuleInterface $rule Rule under test.
	 * @return void
	 */
	public function test_a_rule_produces_its_own_finding_id( AnalyzerRuleInterface $rule ): void {
		$finding = $rule->analyze( Facts::busyStore() );

		if ( null === $finding ) {
			$this->addToAssertionCount( 1 );

			return;
		}

		$this->assertSame( $rule->findingId(), $finding->id );
	}

	/**
	 * Every finding a rule produces carries evidence naming a fact that the
	 * scan actually observed (locked decision #5).
	 *
	 * @dataProvider ruleProvider
	 * @param AnalyzerRuleInterface $rule Rule under test.
	 * @return void
	 */
	public function test_every_finding_cites_observed_facts( AnalyzerRuleInterface $rule ): void {
		$facts   = Facts::busyStore();
		$finding = $rule->analyze( $facts );

		if ( null === $finding ) {
			$this->addToAssertionCount( 1 );

			return;
		}

		$this->assertNotEmpty( $finding->evidence, $rule->findingId() . ' must carry evidence' );

		foreach ( $finding->evidence as $evidence ) {
			// Evidence may cite a key inside a map-valued fact, in which case the
			// fact itself is the part before the last segment.
			$observed = $facts->has( $evidence->fact )
				|| $facts->has( (string) preg_replace( '/\.[^.]+$/', '', $evidence->fact ) );

			$this->assertTrue(
				$observed,
				sprintf( '%s cites "%s", which the fact set does not contain', $rule->findingId(), $evidence->fact )
			);
		}
	}

	/**
	 * Every tweak a rule recommends exists in the registry.
	 *
	 * A recommendation naming a tweak that does not exist would produce a plan
	 * that silently drops it.
	 *
	 * @dataProvider ruleProvider
	 * @param AnalyzerRuleInterface $rule Rule under test.
	 * @return void
	 */
	public function test_recommended_tweaks_exist( AnalyzerRuleInterface $rule ): void {
		$registry = ( new Loader( WPDEBLOAT_TESTS_ROOT . '/registry' ) )->load();
		$finding  = $rule->analyze( Facts::busyStore() );

		if ( null === $finding || null === $finding->recommendation ) {
			$this->addToAssertionCount( 1 );

			return;
		}

		$this->assertTrue(
			$registry->has( $finding->recommendation->tweak_id ),
			sprintf( '%s recommends "%s", which is not in the registry', $rule->findingId(), $finding->recommendation->tweak_id )
		);
	}

	/**
	 * Parameters a rule proposes satisfy the tweak's declared schema.
	 *
	 * @dataProvider ruleProvider
	 * @param AnalyzerRuleInterface $rule Rule under test.
	 * @return void
	 */
	public function test_recommended_parameters_validate( AnalyzerRuleInterface $rule ): void {
		$registry = ( new Loader( WPDEBLOAT_TESTS_ROOT . '/registry' ) )->load();
		$finding  = $rule->analyze( Facts::busyStore() );

		if ( null === $finding || null === $finding->recommendation ) {
			$this->addToAssertionCount( 1 );

			return;
		}

		$definition = $registry->tweak( $finding->recommendation->tweak_id );

		// Throws if the values do not satisfy the declared parameter schema.
		$definition->validateParams( $finding->recommendation->params->toArray() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * On a fresh install, the core-output rules all fire: nothing has been
	 * turned off yet.
	 *
	 * @return void
	 */
	public function test_core_output_rules_fire_on_a_fresh_install(): void {
		$facts = Facts::freshInstall();

		foreach ( array( 'wp.generator.exposed', 'wp.rsd.exposed', 'wp.shortlink.exposed', 'wp.emojis.loaded', 'wp.embeds.enabled' ) as $id ) {
			$this->assertNotNull( $this->rule( $id )->analyze( $facts ), $id . ' should fire on a default install' );
		}
	}

	/**
	 * A feature already switched off produces no finding, so the same
	 * suggestion is never made twice.
	 *
	 * @return void
	 */
	public function test_a_feature_already_removed_produces_no_finding(): void {
		$facts = Facts::freshInstall(
			array(
				'wp.generator_tag'  => false,
				'wp.rsd_link'       => false,
				'wp.shortlink'      => false,
				'wp.emojis_enabled' => false,
				'wp.embeds_enabled' => false,
				'wp.self_pingbacks' => false,
				'wp.jquery_migrate' => false,
			)
		);

		foreach ( array( 'wp.generator.exposed', 'wp.rsd.exposed', 'wp.shortlink.exposed', 'wp.emojis.loaded', 'wp.embeds.enabled', 'wp.self_pingbacks.enabled', 'wp.jquery_migrate.loaded' ) as $id ) {
			$this->assertNull( $this->rule( $id )->analyze( $facts ), $id . ' should not fire when already off' );
		}
	}

	/**
	 * A rule whose facts are missing reports that it cannot evaluate, rather
	 * than reporting nothing wrong.
	 *
	 * @return void
	 */
	public function test_a_rule_without_its_facts_does_not_support_them(): void {
		$rule = $this->rule( 'wp.dashicons.frontend' );

		$this->assertFalse(
			$rule->supports( new FactSet() ),
			'a rule must not claim to have evaluated facts it never saw'
		);
		$this->assertNull( $rule->analyze( new FactSet() ) );
	}

	/**
	 * Heartbeat: the worked example. 15 s polling fires; 60 s does not.
	 *
	 * @return void
	 */
	public function test_heartbeat_fires_only_when_polling_is_frequent(): void {
		$rule = $this->rule( 'wp.heartbeat.aggressive' );

		$this->assertNotNull( $rule->analyze( Facts::freshInstall( array( 'wp.heartbeat_interval' => 15 ) ) ) );
		$this->assertNull( $rule->analyze( Facts::freshInstall( array( 'wp.heartbeat_interval' => 60 ) ) ) );
		$this->assertNull( $rule->analyze( Facts::freshInstall( array( 'wp.heartbeat_interval' => 120 ) ) ) );
	}

	/**
	 * The proposed Heartbeat interval follows the site: 120 s for a quiet blog,
	 * 60 s for a store or a site with several administrators
	 * (BUILD-SPEC §17 Phase 4's worked examples, decided here).
	 *
	 * @return void
	 */
	public function test_heartbeat_proposes_an_interval_suited_to_the_site(): void {
		$rule = $this->rule( 'wp.heartbeat.aggressive' );

		$quiet = $rule->analyze( Facts::freshInstall( array( 'users.admin_count' => 1 ) ) );

		$this->assertNotNull( $quiet );
		$this->assertSame( 120, $quiet->recommendation?->params->int( 'interval' ) );

		$store = $rule->analyze( Facts::busyStore() );

		$this->assertNotNull( $store );
		$this->assertSame( 60, $store->recommendation?->params->int( 'interval' ) );

		$several_admins = $rule->analyze( Facts::freshInstall( array( 'users.admin_count' => 4 ) ) );

		$this->assertNotNull( $several_admins );
		$this->assertSame( 60, $several_admins->recommendation?->params->int( 'interval' ) );
	}

	/**
	 * The Heartbeat impact is a real subtraction, not a decorative number.
	 *
	 * @return void
	 */
	public function test_heartbeat_impact_is_the_actual_difference(): void {
		$finding = $this->rule( 'wp.heartbeat.aggressive' )
			->analyze(
				Facts::freshInstall(
					array(
						'wp.heartbeat_interval' => 15,
						'users.admin_count'     => 1,
					)
				)
			);

		$this->assertNotNull( $finding );
		$this->assertNotNull( $finding->impact );

		// 3600/15 = 240 an hour now, 3600/120 = 30 after, for one administrator.
		$this->assertSame( 210.0, $finding->impact->estimate );
		$this->assertSame( 'requests', $finding->impact->unit );
		$this->assertTrue( $finding->impact->measurable );
	}

	/**
	 * Revisions: unlimited *and* accumulating. Either alone is not a finding.
	 *
	 * @return void
	 */
	public function test_revisions_needs_both_the_setting_and_the_evidence(): void {
		$rule = $this->rule( 'db.revisions.unlimited' );

		$this->assertNotNull(
			$rule->analyze(
				Facts::freshInstall(
					array(
						'wp.revisions_limit' => -1,
						'db.revisions.count' => 4000,
					)
				)
			),
			'unlimited and accumulating should fire'
		);
		$this->assertNull(
			$rule->analyze(
				Facts::freshInstall(
					array(
						'wp.revisions_limit' => -1,
						'db.revisions.count' => 12,
					)
				)
			),
			'unlimited but nothing accumulated is a setting, not a problem'
		);
		$this->assertNull(
			$rule->analyze(
				Facts::freshInstall(
					array(
						'wp.revisions_limit' => 10,
						'db.revisions.count' => 40000,
					)
				)
			),
			'a site that has already set a cap has made this decision'
		);
	}

	/**
	 * Expired transients fire above the threshold and not below it.
	 *
	 * @return void
	 */
	public function test_expired_transients_threshold(): void {
		$rule = $this->rule( 'db.transients.expired' );

		$this->assertNotNull( $rule->analyze( Facts::freshInstall( array( 'db.transients.expired' => 4832 ) ) ) );
		$this->assertNull( $rule->analyze( Facts::freshInstall( array( 'db.transients.expired' => 3 ) ) ) );
		$this->assertNull( $rule->analyze( Facts::freshInstall( array( 'db.transients.expired' => 0 ) ) ) );
	}

	/**
	 * Inactive plugins are reported as information and propose nothing.
	 *
	 * Deleting a plugin is not reversible from here and is not WP Debloat's
	 * decision to make.
	 *
	 * @return void
	 */
	public function test_inactive_plugins_are_information_only(): void {
		$finding = $this->rule( 'plugins.inactive_present' )->analyze( Facts::busyStore() );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertNull( $finding->recommendation );
		$this->assertSame( 0, $finding->scorePenalty(), 'info findings cost nothing' );

		$this->assertNull(
			$this->rule( 'plugins.inactive_present' )->analyze( Facts::freshInstall() ),
			'no inactive plugins, no finding'
		);
	}

	/**
	 * The two configuration findings are information only, because acting on
	 * either would mean editing wp-config.php or guessing at what uses XML-RPC.
	 *
	 * @return void
	 */
	public function test_configuration_findings_propose_nothing(): void {
		foreach ( array( 'wp.file_editor.enabled', 'wp.xmlrpc.enabled' ) as $id ) {
			$finding = $this->rule( $id )->analyze( Facts::freshInstall() );

			$this->assertNotNull( $finding, $id );
			$this->assertSame( Decision::INFO, $finding->decision, $id );
			$this->assertNull( $finding->recommendation, $id );
		}
	}

	/**
	 * The file-editor finding does not fire when the constant is already set.
	 *
	 * @return void
	 */
	public function test_file_editor_does_not_fire_when_already_disabled(): void {
		$this->assertNull(
			$this->rule( 'wp.file_editor.enabled' )->analyze( Facts::freshInstall( array( 'wp.file_editor_enabled' => false ) ) )
		);
	}

	/**
	 * The medium-risk asset rules are rated medium, which is what keeps them out
	 * of "Fix Safe Issues" (BUILD-SPEC §7.4).
	 *
	 * @return void
	 */
	public function test_asset_rules_are_medium_risk(): void {
		$migrate = $this->rule( 'wp.jquery_migrate.loaded' )->analyze( Facts::freshInstall() );

		$this->assertNotNull( $migrate );
		$this->assertFalse( $migrate->risk->isSafePlanEligible(), 'jQuery Migrate must not be safe-plan eligible' );

		$dashicons = $this->rule( 'wp.dashicons.frontend' )
			->analyze( Facts::freshInstall( array( 'wp.dashicons_frontend' => true ) ) );

		$this->assertNotNull( $dashicons );
		$this->assertFalse( $dashicons->risk->isSafePlanEligible(), 'dashicons must not be safe-plan eligible' );
	}

	/**
	 * A rule by finding id.
	 *
	 * @param string $finding_id Finding id.
	 * @return AnalyzerRuleInterface
	 */
	private function rule( string $finding_id ): AnalyzerRuleInterface {
		foreach ( Rules::all() as $rule ) {
			if ( $rule->findingId() === $finding_id ) {
				return $rule;
			}
		}

		$this->fail( 'No rule produces ' . $finding_id );
	}
}
