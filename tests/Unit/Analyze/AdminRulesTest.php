<?php
/**
 * What the admin rules will and will not say.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use WPDebloat\Analyze\Rules\DashboardWidgetsRule;
use WPDebloat\Analyze\Rules\NewsWidgetRule;
use WPDebloat\Analyze\Rules\PluginNoticesRule;
use WPDebloat\Analyze\Rules\UpdateNagRule;
use WPDebloat\Analyze\Rules\WelcomePanelRule;
use WPDebloat\Analyze\Score;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Risk;
use WPDebloat\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §17 Phase 12.
 */
final class AdminRulesTest extends TestCase {

	/**
	 * The welcome panel is reported when it is there, and not when it is not.
	 *
	 * @return void
	 */
	public function test_the_welcome_panel_is_reported_only_when_present(): void {
		$rule = new WelcomePanelRule();

		$finding = $rule->analyze( Facts::freshInstall() );

		$this->assertNotNull( $finding );
		$this->assertSame( Category::ADMIN, $finding->category );
		$this->assertSame( 'admin.remove_welcome_panel', $finding->recommendedTweakId() );

		$this->assertNull( $rule->analyze( Facts::freshInstall( array( 'admin.welcome_panel' => false ) ) ) );
	}

	/**
	 * The news widget is reported from the widget list, not from a count.
	 *
	 * @return void
	 */
	public function test_the_news_widget_is_found_in_the_widget_list(): void {
		$rule = new NewsWidgetRule();

		$this->assertNotNull( $rule->analyze( Facts::freshInstall() ) );

		$without = Facts::freshInstall(
			array(
				'admin.dashboard_widgets' => array(
					array(
						'id'     => 'dashboard_activity',
						'source' => 'wordpress',
					),
				),
			)
		);

		$this->assertNull( $rule->analyze( $without ) );
	}

	/**
	 * The update notice is only worth hiding when somebody would be spared it.
	 *
	 * @return void
	 */
	public function test_the_update_notice_is_left_alone_on_a_one_person_site(): void {
		$rule = new UpdateNagRule();

		$alone = Facts::freshInstall(
			array(
				'admin.update_nag'        => true,
				'users.admin_count'       => 1,
				'users.recent_editors_7d' => 1,
			)
		);

		$this->assertNull(
			$rule->analyze( $alone ),
			'with nobody to hide it from, offering to hide it would be inventing a problem'
		);

		$shared = Facts::freshInstall(
			array(
				'admin.update_nag'        => true,
				'users.admin_count'       => 1,
				'users.recent_editors_7d' => 4,
			)
		);

		$finding = $rule->analyze( $shared );

		$this->assertNotNull( $finding );
		$this->assertSame( 'admin.hide_update_nags_non_admins', $finding->recommendedTweakId() );
		$this->assertStringContainsString( 'still sees it', $finding->why );
	}

	/**
	 * Notice suppression is offered only for plugins on the allowlist, and only
	 * once there are enough of them to be worth a medium-risk change.
	 *
	 * @return void
	 */
	public function test_suppression_is_offered_for_allowlisted_plugins_only(): void {
		$rule = new PluginNoticesRule();

		$finding = $rule->analyze( Facts::busyStore() );

		$this->assertNotNull( $finding );
		$this->assertSame( 'admin.suppress_promo_notices', $finding->recommendedTweakId() );
		$this->assertSame( Risk::MEDIUM, $finding->risk, 'this must stay out of Fix Safe Issues' );

		$params = $finding->recommendation?->params->toArray() ?? array();

		$this->assertSame(
			array( 'woocommerce', 'wordpress-seo' ),
			$params['sources'] ?? array(),
			'only the sources that are both allowlisted and actually printing notices'
		);
	}

	/**
	 * The reasoning says what will be missed, not only what will be gained.
	 *
	 * @return void
	 */
	public function test_suppression_says_what_it_hides(): void {
		$finding = ( new PluginNoticesRule() )->analyze( Facts::busyStore() );

		$this->assertNotNull( $finding );

		foreach ( array( 'not only the marketing', 'database updates or expiring licences' ) as $phrase ) {
			$this->assertStringContainsString( $phrase, $finding->why, $phrase );
		}
	}

	/**
	 * A site with nothing allowlisted printing notices is left alone.
	 *
	 * @return void
	 */
	public function test_suppression_is_not_offered_without_a_vendor(): void {
		$this->assertNull( ( new PluginNoticesRule() )->analyze( Facts::freshInstall() ) );
	}

	/**
	 * A crowded dashboard is reported and never acted on.
	 *
	 * The tweak exists and takes a list of ids. Which ids is a question about
	 * what a person reads every morning, and guessing it would remove things
	 * people rely on.
	 *
	 * @return void
	 */
	public function test_a_crowded_dashboard_is_reported_and_not_chosen_for(): void {
		$rule = new DashboardWidgetsRule();

		$this->assertNull(
			$rule->analyze( Facts::freshInstall() ),
			'four widgets is a default dashboard, not a problem'
		);

		$widgets = Facts::coreDashboard();

		foreach ( array( 'woo_one', 'woo_two', 'seo_one' ) as $id ) {
			$widgets[] = array(
				'id'     => $id,
				'source' => 'woocommerce',
			);
		}

		$finding = $rule->analyze( Facts::freshInstall( array( 'admin.dashboard_widgets' => $widgets ) ) );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertNull( $finding->recommendation, 'which widgets to remove is not ours to decide' );
		$this->assertStringContainsString( 'woocommerce', $finding->summary );
		$this->assertStringContainsString( '7 widgets', $finding->title );
	}

	/**
	 * Every admin rule needs facts that only exist inside the admin, so a scan
	 * from WP-CLI reports them as unevaluated rather than clean.
	 *
	 * @return void
	 */
	public function test_admin_rules_cannot_evaluate_without_admin_facts(): void {
		$outside = Facts::freshInstall(
			array(
				'admin.welcome_panel'     => null,
				'admin.update_nag'        => null,
				'admin.dashboard_widgets' => null,
				'admin.notices'           => null,
				'admin.notice_vendors'    => null,
			)
		);

		foreach ( array( new WelcomePanelRule(), new NewsWidgetRule(), new UpdateNagRule(), new PluginNoticesRule(), new DashboardWidgetsRule() ) as $rule ) {
			$this->assertNull( $rule->analyze( $outside ), $rule->findingId() );
		}
	}

	/**
	 * Admin counts towards the score now.
	 *
	 * @return void
	 */
	public function test_admin_findings_count_towards_the_score(): void {
		$this->assertSame( '2.0', Score::RUBRIC_VERSION );

		$this->assertContains( Category::ADMIN, Score::scoredCategories() );

		$clean = ( new Score( array() ) )->subScore( Category::ADMIN );

		$this->assertSame( 100, $clean );
	}
}
