<?php
/**
 * Every config tweak clears the finding that recommended it.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Contracts\Finding;
use Debloater\Contracts\TweakKind;
use Debloater\Registry\Loader;

/**
 * Apply each config tweak, scan again, and require its finding to be gone.
 *
 * The failure this exists for: `core.disable_embeds`, `core.limit_revisions`
 * and `woo.suppress_marketplace_suggestions` all worked on a real site, and all
 * three findings came back on the next scan, because the scanner read raw
 * configuration — a hook at any priority, a constant, an option — where the
 * tweak had changed the effective answer. Fix Safe Issues offered the same
 * changes again after every scan, and every existing test passed: the tests
 * checked that a tweak did something, and separately that a rule fired, and
 * nothing checked that the one was visible to the other.
 *
 * So each case asserts both halves on a real scan:
 *
 * 1. Before the tweak, a scan **recommends it**. Without this the second half
 *    is vacuous — a finding that never appeared cannot be said to have cleared.
 * 2. With the tweak stored and its handlers registered, as `plugins_loaded`
 *    would on the next request, a fresh scan **no longer recommends it**.
 *
 * The tweak is applied with the parameters its own finding proposed, because
 * that is what Fix Safe Issues applies.
 *
 * A config tweak in the registry with neither a scenario here nor an entry in
 * `NOT_EXERCISED` fails `test_every_config_tweak_is_accounted_for`, so a new
 * tweak cannot arrive without either this check or a stated reason it has none.
 */
final class TweakEffectTest extends IntegrationTestCase {

	/**
	 * Config tweak id to the finding id that recommends it, and the context the
	 * scan has to run in for that finding's facts to exist at all.
	 *
	 * Literals, not rule constants: the pairing is the contract under test.
	 */
	private const SCENARIOS = array(
		'admin.remove_welcome_panel'           => array( 'admin.welcome_panel.visible', 'admin' ),
		'admin.remove_wp_news_widget'          => array( 'admin.news_widget.present', 'admin' ),
		'core.disable_embeds'                  => array( 'wp.embeds.enabled', 'front' ),
		'core.disable_emojis'                  => array( 'wp.emojis.loaded', 'front' ),
		'core.disable_self_pingbacks'          => array( 'wp.self_pingbacks.enabled', 'front' ),
		'core.heartbeat_interval'              => array( 'wp.heartbeat.aggressive', 'front' ),
		'core.limit_revisions'                 => array( 'db.revisions.unlimited', 'front' ),
		'core.remove_generator'                => array( 'wp.generator.exposed', 'front' ),
		'core.remove_jquery_migrate'           => array( 'wp.jquery_migrate.loaded', 'front' ),
		'core.remove_rsd'                      => array( 'wp.rsd.exposed', 'front' ),
		'core.remove_shortlink'                => array( 'wp.shortlink.exposed', 'front' ),
		'woo.disable_admin_analytics'          => array( 'woo.analytics.enabled', 'front' ),
		'woo.suppress_marketplace_suggestions' => array( 'woo.marketplace.suggestions', 'front' ),
	);

	/**
	 * Config tweaks this test does not exercise, and why.
	 *
	 * Each reason is a statement about the product, not about the test being
	 * inconvenient to write, and each is reported in the release notes.
	 */
	private const NOT_EXERCISED = array(
		'admin.remove_dashboard_widgets' => 'No rule recommends it: the dashboard-widgets finding is informational, so there is no finding for it to clear.',
		'admin.suppress_promo_notices'   => 'No rule recommends it since 0.5.0 (D-0079). It was recommended, and this test showed it surviving its own application: the handler acts on admin_head, which a scan never reaches.',
		'core.disable_dashicons_guests'  => 'Its finding is built from pages the scanner fetches over HTTP as a visitor. The test environment cannot fetch its own pages, so the finding never appears here and there is nothing for the handler to change. RulesTest covers the rule on sampled-page facts.',
		'elementor.disable_google_fonts' => 'No rule recommends it, so there is no finding for it to clear.',
		'woo.block_styles_conditional'   => 'Its finding is built from pages the scanner fetches over HTTP as a visitor. The test environment cannot fetch its own pages, so the finding never appears here and there is nothing for the handler to change.',
		'woo.cart_fragments_conditional' => 'Its finding is built from pages the scanner fetches over HTTP as a visitor. The test environment cannot fetch its own pages, so the finding never appears here and there is nothing for the handler to change.',
	);

	/**
	 * Tweak ids registered during a test, to unregister afterwards.
	 *
	 * @var array<int,string>
	 */
	private array $applied = array();

	/**
	 * Tables exist for scans to be stored.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();
	}

	/**
	 * Undo every hook, option and screen a case set up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->unregisterHandlers( $this->applied );
		$this->applied = array();

		remove_all_filters( 'pre_http_request' );
		remove_filter( 'heartbeat_settings', array( self::class, 'aggressive_heartbeat' ) );

		update_option( 'active_plugins', array() );

		wp_set_current_user( 0 );
		set_current_screen( 'front' );

		$this->freshAssetRegistries();

		parent::tear_down();
	}

	/**
	 * Every config tweak in the registry.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function configTweaks(): array {
		$root     = defined( 'DEBLOATER_TESTS_ROOT' ) ? DEBLOATER_TESTS_ROOT : dirname( __DIR__, 2 );
		$registry = ( new Loader( $root . '/registry' ) )->load();
		$cases    = array();

		foreach ( $registry->all() as $definition ) {
			if ( TweakKind::CONFIG === $definition->kind ) {
				$cases[ $definition->id ] = array( $definition->id );
			}
		}

		ksort( $cases, SORT_STRING );

		return $cases;
	}

	/**
	 * No config tweak is silently missing from this test.
	 *
	 * @return void
	 */
	public function test_every_config_tweak_is_accounted_for(): void {
		$ids = array_keys( self::configTweaks() );

		$accounted = array_merge( array_keys( self::SCENARIOS ), array_keys( self::NOT_EXERCISED ) );
		sort( $accounted, SORT_STRING );

		$this->assertSame( $ids, $accounted, 'every config tweak needs a scenario here or a stated reason it has none' );
		$this->assertSame( array(), array_intersect( array_keys( self::SCENARIOS ), array_keys( self::NOT_EXERCISED ) ) );
	}

	/**
	 * The "no rule recommends it" reasons are still true.
	 *
	 * If a rule starts recommending one of them, it belongs in `SCENARIOS`.
	 *
	 * @return void
	 */
	public function test_the_unrecommended_tweaks_are_still_unrecommended(): void {
		$rules = '';

		foreach ( (array) glob( DEBLOATER_TESTS_ROOT . '/src/Analyze/Rules/*.php' ) as $file ) {
			$rules .= (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading source, in a test.
		}

		foreach ( array( 'admin.remove_dashboard_widgets', 'admin.suppress_promo_notices', 'elementor.disable_google_fonts' ) as $tweak_id ) {
			$this->assertStringNotContainsString( "'" . $tweak_id . "'", $rules, $tweak_id . ' is recommended by a rule now; give it a scenario' );
		}
	}

	/**
	 * Applying a config tweak clears the finding that recommended it.
	 *
	 * @dataProvider configTweaks
	 *
	 * @param string $tweak_id Tweak under test.
	 * @return void
	 */
	public function test_applying_a_tweak_clears_its_finding( string $tweak_id ): void {
		if ( isset( self::NOT_EXERCISED[ $tweak_id ] ) ) {
			// Counted, not skipped: the reason is asserted to exist above, and a
			// skip here would read as "could not run" in a suite where that is a
			// failure (P2).
			$this->assertNotSame( '', self::NOT_EXERCISED[ $tweak_id ] );

			return;
		}

		list( $finding_id, $context ) = self::SCENARIOS[ $tweak_id ];

		$this->arrange( $tweak_id, $context );

		$before = $this->recommending( $tweak_id, $this->scan( $context ) );

		$this->assertNotNull(
			$before,
			sprintf( 'before applying %s, no scan recommended it, so its effect cannot be checked', $tweak_id )
		);
		$this->assertSame( $finding_id, $before->id, 'the finding that recommends ' . $tweak_id );

		$params = null === $before->recommendation ? array() : $before->recommendation->params->toArray();

		$this->selectAndGenerate( array( $tweak_id => $params ) );
		$this->applied[] = $tweak_id;

		$this->assertTrue( $this->loadRuntime(), $tweak_id . ' did not register' );

		$after = $this->recommending( $tweak_id, $this->scan( $context ) );

		$this->assertNull(
			$after,
			sprintf(
				'%s is applied and registered, and the next scan still recommends it through %s: the scanner cannot see the change',
				$tweak_id,
				null === $after ? '' : $after->id
			)
		);
	}

	/**
	 * Config tweaks whose registry `effect` a request can observe.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function observableEffects(): array {
		$root     = defined( 'DEBLOATER_TESTS_ROOT' ) ? DEBLOATER_TESTS_ROOT : dirname( __DIR__, 2 );
		$registry = ( new Loader( $root . '/registry' ) )->load();
		$cases    = array();

		foreach ( $registry->all() as $definition ) {
			if ( null !== $definition->effect && $definition->effect->observable ) {
				$cases[ $definition->id ] = array( $definition->id );
			}
		}

		ksort( $cases, SORT_STRING );

		return $cases;
	}

	/**
	 * A declared effect does not hold before the tweak, and does once it is registered.
	 *
	 * What verification's `effects_observed` probe relies on: if a declaration
	 * were wrong, a working change would be reported as not observed on every
	 * site, or a broken one as observed. Checked through `Plugin::effectReport()`,
	 * the same code the status route runs, with the parameters Fix Safe Issues
	 * would use where the default would already hold.
	 *
	 * @dataProvider observableEffects
	 *
	 * @param string $tweak_id Tweak under test.
	 * @return void
	 */
	public function test_a_declared_effect_is_observed_once_applied( string $tweak_id ): void {
		$params = self::EFFECT_PARAMS[ $tweak_id ] ?? array();

		if ( str_starts_with( $tweak_id, 'woo.' ) ) {
			update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		}

		$this->plugin->state()->setSelection( array( $tweak_id => $params ) );
		$this->freshAssetRegistries();

		$before = $this->effectRow( $tweak_id );

		$this->assertSame( 'not_observed', $before['status'], $tweak_id . ' already reads as applied before it is: ' . wp_json_encode( $before ) );

		$this->selectAndGenerate( array( $tweak_id => $params ) );
		$this->applied[] = $tweak_id;

		$this->assertTrue( $this->loadRuntime(), $tweak_id . ' did not register' );
		$this->freshAssetRegistries();

		$after = $this->effectRow( $tweak_id );

		$this->assertSame( 'observed', $after['status'], $tweak_id . ' is registered and its declared effect does not show: ' . wp_json_encode( $after ) );
	}

	/**
	 * Parameters under which a declared effect is not already true on a fresh site.
	 *
	 * Heartbeat's default interval is core's 60, so applying it at 60 would read
	 * as observed before anything happened.
	 */
	private const EFFECT_PARAMS = array(
		'core.heartbeat_interval' => array( 'interval' => 120 ),
	);

	/**
	 * This request's effect row for one tweak.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return array<string,mixed>
	 */
	private function effectRow( string $tweak_id ): array {
		foreach ( $this->plugin->effectReport() as $row ) {
			if ( $tweak_id === $row['tweak'] ) {
				return $row;
			}
		}

		$this->fail( 'no effect row for ' . $tweak_id );
	}

	/**
	 * Make the finding for a tweak appear.
	 *
	 * @param string $tweak_id Tweak under test.
	 * @param string $context  Scan context.
	 * @return void
	 */
	private function arrange( string $tweak_id, string $context ): void {
		if ( 'admin' === $context ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			set_current_screen( 'dashboard' );

			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		switch ( $tweak_id ) {
			case 'admin.remove_welcome_panel':
				if ( ! has_action( 'welcome_panel', 'wp_welcome_panel' ) ) {
					add_action( 'welcome_panel', 'wp_welcome_panel' );
				}
				break;

			case 'core.heartbeat_interval':
				// A site that has asked for a fast Heartbeat, so the finding does
				// not depend on what the scanner assumes the default is.
				add_filter( 'heartbeat_settings', array( self::class, 'aggressive_heartbeat' ) );
				break;

			case 'core.limit_revisions':
				$parent = self::factory()->post->create();

				self::factory()->post->create_many(
					210,
					array(
						'post_type'   => 'revision',
						'post_status' => 'inherit',
						'post_parent' => $parent,
					)
				);
				break;

			case 'woo.disable_admin_analytics':
			case 'woo.suppress_marketplace_suggestions':
				update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
				break;
		}
	}

	/**
	 * A fresh scan's findings, in the given context.
	 *
	 * The asset registries and the dashboard are rebuilt first. On a real site
	 * each scan is its own request, which builds them after the runtime has
	 * registered; in one PHP process they would otherwise keep what an earlier
	 * scan built, and a handler acting on `wp_default_scripts` or
	 * `wp_dashboard_setup` would never get the chance.
	 *
	 * @param string $context Scan context.
	 * @return array<int,Finding>
	 */
	private function scan( string $context ): array {
		$this->freshAssetRegistries();

		if ( 'admin' === $context ) {
			global $wp_meta_boxes;

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilding the dashboard as a new request would.
			$wp_meta_boxes = array();

			set_current_screen( 'dashboard' );
			wp_dashboard_setup();
		}

		return $this->plugin->findingsOf( $this->plugin->scan() );
	}

	/**
	 * The recommend finding for a tweak, if the scan made one.
	 *
	 * @param string             $tweak_id Tweak id.
	 * @param array<int,Finding> $findings Findings.
	 * @return Finding|null
	 */
	private function recommending( string $tweak_id, array $findings ): ?Finding {
		foreach ( $findings as $finding ) {
			if ( null !== $finding->recommendation && $tweak_id === $finding->recommendation->tweak_id && $finding->isPlannable() ) {
				return $finding;
			}
		}

		return null;
	}

	/**
	 * Throw away the script and style registries so the next use rebuilds them.
	 *
	 * @return void
	 */
	private function freshAssetRegistries(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A new request starts with neither.
		$GLOBALS['wp_scripts'] = null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above.
		$GLOBALS['wp_styles'] = null;
	}

	/**
	 * Heartbeat settings asking for a 15-second interval.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public static function aggressive_heartbeat( $settings ): array {
		$settings             = is_array( $settings ) ? $settings : array();
		$settings['interval'] = 15;

		return $settings;
	}
}
