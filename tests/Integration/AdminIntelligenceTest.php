<?php
/**
 * The admin screens, against a real WordPress install.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use WPDebloat\Analyze\Score;
use WPDebloat\Registry\SchemaValidator;
use WPDebloat\Scan\Sources;

/**
 * BUILD-SPEC §17 Phase 12.
 *
 * Two claims are worth more than the counting: that every admin change can be
 * taken back exactly, and that WP Debloat itself never prints anything into the
 * area it is offering to tidy up.
 */
final class AdminIntelligenceTest extends IntegrationTestCase {

	/**
	 * The tweaks this phase adds.
	 */
	private const ADMIN_TWEAKS = array(
		'admin.remove_dashboard_widgets',
		'admin.remove_welcome_panel',
		'admin.remove_wp_news_widget',
		'admin.hide_update_nags_non_admins',
		'admin.suppress_promo_notices',
	);

	/**
	 * Put the scan inside the admin, where these facts exist at all.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'dashboard' );

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
	}

	/**
	 * Leave no hooks behind.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->unregisterHandlers( self::ADMIN_TWEAKS );

		wp_set_current_user( 0 );

		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Outside the admin, nothing is reported rather than zero.
	 *
	 * A count of zero and "we could not look" are indistinguishable in a number
	 * and mean opposite things, so the keys are simply absent.
	 *
	 * @return void
	 */
	public function test_outside_the_admin_nothing_is_claimed(): void {
		set_current_screen( 'front' );

		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts->toArray();

		foreach ( array_keys( $facts ) as $key ) {
			$this->assertStringStartsNotWith(
				'admin.',
				(string) $key,
				'an admin fact collected outside the admin would be a guess'
			);
		}
	}

	/**
	 * Inside the admin, the facts appear, validate, and describe this screen.
	 *
	 * @return void
	 */
	public function test_inside_the_admin_the_facts_are_real(): void {
		$facts = $this->adminFacts();

		$violations = SchemaValidator::fromFile( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/fact.schema.json' )
			->validate( $facts->toArray() );

		$this->assertSame( array(), $violations, implode( '; ', array_map( 'strval', $violations ) ) );

		$widgets = $facts->value( 'admin.dashboard_widgets', array() );

		$this->assertIsArray( $widgets );
		$this->assertNotSame( array(), $widgets, 'a real dashboard has widgets on it' );

		$this->assertSame( count( $widgets ), $facts->value( 'admin.dashboard_widgets.count' ) );

		foreach ( $widgets as $widget ) {
			$this->assertNotSame( '', $widget['id'] );
			$this->assertNotSame( '', $widget['source'] );
		}
	}

	/**
	 * Core's own widgets are attributed to core, not to "unknown".
	 *
	 * Attribution that quietly fails is the failure mode that matters here: a
	 * source list where everything is "unknown" looks like it works.
	 *
	 * @return void
	 */
	public function test_core_widgets_are_attributed_to_core(): void {
		$widgets = $this->adminFacts()->value( 'admin.dashboard_widgets', array() );

		$this->assertIsArray( $widgets );

		$sources = array();

		foreach ( $widgets as $widget ) {
			$sources[ $widget['id'] ] = $widget['source'];
		}

		$this->assertArrayHasKey( 'dashboard_right_now', $sources, 'core registers this on every dashboard' );
		$this->assertSame( Sources::CORE, $sources['dashboard_right_now'] );
	}

	/**
	 * A notice registered by a plugin is attributed to that plugin.
	 *
	 * @return void
	 */
	public function test_a_notice_is_attributed_to_whoever_registered_it(): void {
		// A callback defined inside this test file, which lives under the
		// plugins directory in the fixture install — so it should come back as
		// this plugin's own slug rather than as core or unknown.
		add_action( 'admin_notices', array( self::class, 'print_nothing' ) );

		try {
			$notices = $this->adminFacts()->value( 'admin.notices', array() );
		} finally {
			remove_action( 'admin_notices', array( self::class, 'print_nothing' ) );
		}

		$this->assertIsArray( $notices );

		$sources = array_column( $notices, 'source' );

		$this->assertContains(
			Sources::fromPath( __FILE__ ),
			$sources,
			'a notice registered from a file in a plugin belongs to that plugin'
		);
	}

	/**
	 * A callback for the test above. Prints nothing, which is exactly the point:
	 * the scanner counts callbacks that will run to decide, not visible notices.
	 *
	 * @return void
	 */
	public static function print_nothing(): void {
	}

	/**
	 * Every admin tweak registers hooks and takes every one of them back.
	 *
	 * @return void
	 */
	public function test_every_admin_tweak_unregisters_cleanly(): void {
		foreach ( self::ADMIN_TWEAKS as $tweak_id ) {
			$before = $this->hookSnapshot();

			$this->selectAndGenerate( array( $tweak_id => $this->paramsFor( $tweak_id ) ) );
			$this->loadRuntime();

			$during = $this->hookSnapshot();

			$this->unregisterHandlers( array( $tweak_id ) );

			$after = $this->hookSnapshot();

			$this->assertSame(
				array(),
				$this->hooksAdded( $before, $after ),
				$tweak_id . ' left hooks behind after unregister()'
			);
			$this->assertSame(
				array(),
				$this->hooksRemoved( $before, $after ),
				$tweak_id . ' did not put back a hook it removed'
			);

			unset( $during );
		}
	}

	/**
	 * The welcome panel comes back exactly as it was.
	 *
	 * @return void
	 */
	public function test_the_welcome_panel_is_restored(): void {
		$this->assertTrue( (bool) has_action( 'welcome_panel', 'wp_welcome_panel' ) );

		$this->selectAndGenerate( array( 'admin.remove_welcome_panel' => array() ) );
		$this->loadRuntime();

		$this->assertFalse( has_action( 'welcome_panel', 'wp_welcome_panel' ) );

		$this->unregisterHandlers( array( 'admin.remove_welcome_panel' ) );

		$this->assertTrue( (bool) has_action( 'welcome_panel', 'wp_welcome_panel' ) );
	}

	/**
	 * Named dashboard widgets go, and nothing else does.
	 *
	 * @return void
	 */
	public function test_only_the_named_widgets_are_removed(): void {
		$before = $this->widgetIds();

		$this->assertContains( 'dashboard_right_now', $before );
		$this->assertContains( 'dashboard_activity', $before );

		$this->selectAndGenerate(
			array( 'admin.remove_dashboard_widgets' => array( 'widgets' => array( 'dashboard_right_now' ) ) )
		);
		$this->loadRuntime();

		$after = $this->widgetIds();

		$this->assertNotContains( 'dashboard_right_now', $after );
		$this->assertContains( 'dashboard_activity', $after, 'a widget nobody named must be untouched' );

		$this->unregisterHandlers( array( 'admin.remove_dashboard_widgets' ) );

		$this->assertSame( $before, $this->widgetIds(), 'the dashboard comes back exactly as it was' );
	}

	/**
	 * The update notice is hidden from people who cannot update, and from
	 * nobody else.
	 *
	 * @return void
	 */
	public function test_the_update_notice_still_reaches_whoever_can_act_on_it(): void {
		$administrator = get_current_user_id();
		$author        = self::factory()->user->create( array( 'role' => 'author' ) );

		add_action( 'admin_notices', 'update_nag', 3 );

		$this->selectAndGenerate( array( 'admin.hide_update_nags_non_admins' => array() ) );
		$this->loadRuntime();

		wp_set_current_user( $author );
		\WPDebloat_Handler_Admin_Hide_Update_Nags_Non_Admins::hide_for_others();

		$this->assertFalse(
			has_action( 'admin_notices', 'update_nag' ),
			'an author cannot update, so the instruction is not addressed to them'
		);

		// Put it back and run again as somebody who can act on it.
		add_action( 'admin_notices', 'update_nag', 3 );

		wp_set_current_user( $administrator );
		\WPDebloat_Handler_Admin_Hide_Update_Nags_Non_Admins::hide_for_others();

		$this->assertNotFalse(
			has_action( 'admin_notices', 'update_nag' ),
			'the person who can run the update must always see it'
		);

		$this->unregisterHandlers( array( 'admin.hide_update_nags_non_admins' ) );

		remove_action( 'admin_notices', 'update_nag', 3 );
	}

	/**
	 * Hiding a plugin's notices hides that plugin's notices and no others.
	 *
	 * @return void
	 */
	public function test_suppression_reaches_only_the_selected_plugin(): void {
		// A notice registered from this file, which is not inside the
		// WooCommerce directory. The handler decides by where the code lives,
		// which is the property under test.
		$ours = array( self::class, 'print_nothing' );

		add_action( 'admin_notices', $ours );

		$plugin_slug = Sources::fromPath( __FILE__ );

		$this->selectAndGenerate(
			array( 'admin.suppress_promo_notices' => array( 'sources' => array( 'woocommerce' ) ) )
		);
		$this->loadRuntime();

		\WPDebloat_Handler_Admin_Suppress_Promo_Notices::hide_notices();

		$this->assertNotFalse(
			has_action( 'admin_notices', $ours ),
			sprintf( 'a notice from "%s" must survive a selection of woocommerce', $plugin_slug )
		);

		$this->unregisterHandlers( array( 'admin.suppress_promo_notices' ) );

		remove_action( 'admin_notices', $ours );
	}

	/**
	 * A source outside the allowlist is refused before it reaches generated
	 * code.
	 *
	 * @return void
	 */
	public function test_a_source_outside_the_allowlist_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/some-plugin-nobody-vetted/' );

		// The refusal comes from the tweak's own parameter schema, whose enum is
		// the allowlist. Nothing further down has to be trusted to check it, and
		// nothing outside the allowlist can reach generated code (§13 rule 5).
		$this->plugin->registry()->tweak( 'admin.suppress_promo_notices' )->resolve(
			array( 'sources' => array( 'some-plugin-nobody-vetted' ) )
		);
	}

	/**
	 * The site still works with every admin tweak applied at once.
	 *
	 * @return void
	 */
	public function test_verification_passes_with_every_admin_tweak_applied(): void {
		$this->serveHealthySite();

		$selection = array();

		foreach ( self::ADMIN_TWEAKS as $tweak_id ) {
			$selection[ $tweak_id ] = $this->paramsFor( $tweak_id );
		}

		$this->selectAndGenerate( $selection );
		$this->loadRuntime();

		$result = $this->plugin->verifier()->verify();

		$this->assertFalse( $result->isFailure(), (string) wp_json_encode( $result->toArray() ) );

		$this->unregisterHandlers( self::ADMIN_TWEAKS );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Admin is a scored sub-score now, and Performance still is not.
	 *
	 * @return void
	 */
	public function test_admin_is_scored(): void {
		$this->assertSame( '2.0', Score::RUBRIC_VERSION );

		$sub_scores = ( new Score( array() ) )->subScores();

		$this->assertArrayHasKey( 'admin', $sub_scores );
		$this->assertArrayNotHasKey( 'performance', $sub_scores );
	}

	/**
	 * WP Debloat prints nothing into the admin notice area, on any screen.
	 *
	 * The whole phase is about other people's interruptions. Adding one of our
	 * own while doing it would be the single most embarrassing possible bug, so
	 * it is asserted rather than intended.
	 *
	 * @return void
	 */
	public function test_wp_debloat_registers_no_admin_notices(): void {
		$notices = $this->adminFacts()->value( 'admin.notices', array() );

		$this->assertIsArray( $notices );

		$ours = Sources::fromPath( WPDEBLOAT_TESTS_ROOT . '/src/Plugin.php' );

		foreach ( $notices as $notice ) {
			$this->assertNotSame(
				$ours,
				$notice['source'],
				'WP Debloat must never put anything in the admin notice area'
			);
		}
	}

	/**
	 * Facts from a scan taken on the dashboard.
	 *
	 * @return \WPDebloat\Contracts\FactSet
	 */
	private function adminFacts(): \WPDebloat\Contracts\FactSet {
		set_current_screen( 'dashboard' );

		wp_dashboard_setup();

		return $this->plugin->scanRunner()->collect( $this->context() )->facts;
	}

	/**
	 * Dashboard widget ids currently registered.
	 *
	 * @return array<int,string>
	 */
	private function widgetIds(): array {
		global $wp_meta_boxes;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clearing the registry so wp_dashboard_setup() rebuilds it is the whole measurement; without it every call would return what the previous one left behind.
		$wp_meta_boxes = array();

		wp_dashboard_setup();

		$ids = array();

		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $context_boxes ) {
			foreach ( (array) $context_boxes as $priority_boxes ) {
				foreach ( (array) $priority_boxes as $id => $box ) {
					if ( is_array( $box ) ) {
						$ids[] = (string) $id;
					}
				}
			}
		}

		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Parameters that exercise a tweak rather than leaving it inert.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return array<string,mixed>
	 */
	private function paramsFor( string $tweak_id ): array {
		if ( 'admin.remove_dashboard_widgets' === $tweak_id ) {
			return array( 'widgets' => array( 'dashboard_right_now' ) );
		}

		if ( 'admin.suppress_promo_notices' === $tweak_id ) {
			return array( 'sources' => array( 'woocommerce' ) );
		}

		return array();
	}

	/**
	 * Answer every verification request as a working site would.
	 *
	 * @return void
	 */
	private function serveHealthySite(): void {
		$plugin = $this->plugin;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $plugin ) {
				unset( $preempt, $args );

				if ( 0 === strpos( $url, rest_url( 'wpdebloat/v1/status' ) ) ) {
					$body = (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => $plugin->state()->runtimeHash() ),
							'loader'  => array( 'mode' => \WPDebloat\Apply\RuntimeLoader::MODE_MU_PLUGIN ),
						)
					);
				} elseif ( 0 === strpos( $url, rest_url() ) ) {
					$body = (string) wp_json_encode( array( 'name' => 'A site' ) );
				} elseif ( 0 === strpos( $url, wp_login_url() ) ) {
					$body = '<html><head><title>Log In</title></head><body>'
						. '<form id="loginform"></form></body></html>';
				} else {
					$body = '<!DOCTYPE html><html><head><title>A site</title></head><body>'
						. 'Hello<div id="adminmenu"></div><div id="wpbody"></div></body></html>';
				}

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}
}
