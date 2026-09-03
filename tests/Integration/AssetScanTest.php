<?php
/**
 * The asset scan, against a real WordPress install.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Analyze\Rules\Cf7AssetsRule;
use Debloater\Contracts\Decision;
use Debloater\Registry\SchemaValidator;
use Debloater\Scan\PageSample;
use Debloater\Scan\SampledPages;
use Debloater\Scan\Scanners\AssetScanner;
use Debloater\Scan\Sources;

/**
 * BUILD-SPEC §13 rule 9 and §17 Phase 13.
 *
 * The pages are served from fixtures rather than fetched for real. That is not a
 * shortcut: wp-env runs the test runner and the web server in separate
 * containers, so the site's own canonical URL does not resolve to the site from
 * where these tests execute, and real loopback is impossible here (an
 * environment limitation, not a property of the code — see docs/TEST-RESULTS.md
 * for Phase 6). What the fixtures cannot measure is network time; what they can
 * measure, and do, is that every request goes to this site and nowhere else,
 * that the parsing and attribution are right, and that the scanner gives up
 * rather than hanging when the site cannot reach itself.
 */
final class AssetScanTest extends IntegrationTestCase {

	/**
	 * Every URL the scan asked for during the current test.
	 *
	 * @var array<int,string>
	 */
	private array $requested = array();

	/**
	 * Serve the fixture page for anything on this site.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requested = array();

		$this->plugin->schema()->ensure();

		$html = (string) file_get_contents( DEBLOATER_TESTS_ROOT . '/tests/Fixtures/html/stacked-page.html' );

		// The fixture is written against example.test; rewrite it to this site so
		// that attribution is exercised against real paths on a real install.
		$html = str_replace( 'http://example.test', untrailingslashit( home_url() ), $html );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $html ) {
				unset( $preempt, $args );

				$this->requested[] = (string) $url;

				return array(
					'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
					'body'     => $html,
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

	/**
	 * Stop serving.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * Every request the asset scan makes is to this site.
	 *
	 * @return void
	 */
	public function test_the_asset_scan_only_ever_asks_this_site(): void {
		$this->scan();

		$this->assertNotSame( array(), $this->requested, 'the scan should have fetched something' );

		foreach ( $this->requested as $url ) {
			$this->assertStringStartsWith(
				untrailingslashit( home_url() ),
				$url,
				'an asset scan may read this site and nothing else'
			);
		}
	}

	/**
	 * The facts validate and describe a sample.
	 *
	 * @return void
	 */
	public function test_the_facts_validate_and_own_up_to_being_a_sample(): void {
		$facts = $this->scan();

		$violations = SchemaValidator::fromFile( DEBLOATER_TESTS_ROOT . '/registry/schemas/fact.schema.json' )
			->validate( $facts->toArray() );

		$this->assertSame( array(), $violations, implode( '; ', array_map( 'strval', $violations ) ) );

		$this->assertTrue( $facts->value( 'assets.available' ) );
		$this->assertGreaterThan( 0, (int) $facts->value( 'assets.pages_sampled' ) );
		$this->assertLessThanOrEqual( PageSample::MAX_URLS, (int) $facts->value( 'assets.pages_offered' ) );
		$this->assertContains( 'home', $facts->value( 'assets.post_types', array() ) );
	}

	/**
	 * Attribution is right for at least 95% of the assets on the fixture stack.
	 *
	 * The exit criterion for this phase. Asserted as a proportion because a
	 * hand-rolled script with no handle and a third-party analytics host are
	 * both genuinely unattributable, and a test demanding perfection would be
	 * demanding a wrong answer for them.
	 *
	 * @return void
	 */
	public function test_attribution_is_at_least_95_percent_accurate(): void {
		$facts = $this->scan();

		$expected = array(
			// Core.
			'wp-block-library'        => 'wordpress',
			'classic-theme-styles'    => 'wordpress',
			'dashicons'               => 'wordpress',
			'jquery-core'             => 'wordpress',
			'jquery-migrate'          => 'wordpress',

			// Plugins.
			'woocommerce-layout'      => 'woocommerce',
			'woocommerce-smallscreen' => 'woocommerce',
			'woocommerce-general'     => 'woocommerce',
			'jquery-blockui'          => 'woocommerce',
			'woocommerce'             => 'woocommerce',
			'wc-cart-fragments'       => 'woocommerce',
			'contact-form-7'          => 'contact-form-7',
			'swv'                     => 'contact-form-7',
			'elementor-frontend'      => 'elementor',

			// Themes.
			'storefront-style'        => 'theme',
			'storefront-icons'        => 'theme',
			'storefront-navigation'   => 'theme',

			// Not on this disk, and correctly not guessed at.
			'google-fonts-1'          => Sources::UNKNOWN,
			'analytics'               => Sources::UNKNOWN,
		);

		$actual = array();

		foreach ( array( 'assets.scripts', 'assets.styles' ) as $key ) {
			foreach ( $facts->value( $key, array() ) as $asset ) {
				if ( '' !== $asset['handle'] ) {
					$actual[ $asset['handle'] ] = $asset['source'];
				}
			}
		}

		$right = 0;
		$wrong = array();

		foreach ( $expected as $handle => $source ) {
			if ( ( $actual[ $handle ] ?? null ) === $source ) {
				++$right;
			} else {
				$wrong[ $handle ] = sprintf( 'expected %s, got %s', $source, $actual[ $handle ] ?? 'nothing' );
			}
		}

		$accuracy = $right / count( $expected );

		$this->assertGreaterThanOrEqual(
			0.95,
			$accuracy,
			sprintf( 'attribution was %.0f%% accurate; wrong: %s', $accuracy * 100, wp_json_encode( $wrong ) )
		);
	}

	/**
	 * An asset served from this site has its size read off the disk.
	 *
	 * @return void
	 */
	public function test_local_assets_carry_their_size(): void {
		$facts = $this->scan();

		$sizes = array();

		foreach ( $facts->value( 'assets.scripts', array() ) as $asset ) {
			$sizes[ $asset['handle'] ] = $asset['bytes'];
		}

		$this->assertIsInt( $sizes['jquery-core'] ?? null, 'core ships this file, so its size is knowable' );
		$this->assertGreaterThan( 0, $sizes['jquery-core'] );

		// `??` would treat the null we are looking for as an absence, so ask
		// whether the key is there before asking what is in it.
		$this->assertArrayHasKey( 'analytics', $sizes );
		$this->assertNull(
			$sizes['analytics'],
			'a file on somebody else\'s server has no size we can read, and inventing one would need a request nobody asked for'
		);
	}

	/**
	 * External hosts and Google Fonts are noticed.
	 *
	 * @return void
	 */
	public function test_external_hosts_are_counted(): void {
		$facts = $this->scan();

		$hosts = array_column( $facts->value( 'assets.external_hosts', array() ), 'host' );

		$this->assertContains( 'fonts.googleapis.com', $hosts );
		$this->assertContains( 'cdn.example-analytics.test', $hosts );

		$this->assertTrue( $facts->value( 'assets.google_fonts' ) );
	}

	/**
	 * The whole scan stays inside ten seconds.
	 *
	 * @return void
	 */
	public function test_the_scan_stays_under_ten_seconds(): void {
		$started = microtime( true );

		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts;

		$elapsed = microtime( true ) - $started;

		$this->assertLessThan(
			10.0,
			$elapsed,
			sprintf( 'the whole scan took %.2fs', $elapsed )
		);

		$this->assertLessThanOrEqual(
			SampledPages::BUDGET_MS,
			(int) $facts->value( 'assets.elapsed_ms', 0 ) + 1,
			'the asset scan must respect its own budget'
		);
	}

	/**
	 * A site that cannot reach itself says so instead of timing out ten times.
	 *
	 * @return void
	 */
	public function test_an_unreachable_site_gives_up_immediately(): void {
		remove_all_filters( 'pre_http_request' );

		$attempts = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$attempts ) {
				unset( $preempt, $args, $url );

				++$attempts;

				return new \WP_Error( 'http_request_failed', 'Connection refused' );
			},
			10,
			3
		);

		$facts = $this->scan();

		$this->assertFalse( $facts->value( 'assets.available' ) );
		$this->assertNotSame( '', (string) $facts->value( 'assets.unavailable_reason' ) );
		$this->assertSame( 0, $facts->value( 'assets.pages_sampled' ) );

		$this->assertSame(
			1,
			$attempts,
			'one failed loopback check tells us what ten timeouts would, in a fifth of a second'
		);
	}

	/**
	 * The Contact Form 7 finding says how many pages it looked at.
	 *
	 * @return void
	 */
	public function test_the_cf7_finding_reports_the_sample_it_read(): void {
		$facts = $this->scan(
			array(
				'assets.pages_sampled'   => 6,
				'assets.cf7_asset_pages' => 6,
				'assets.cf7_form_pages'  => 1,
			)
		);

		$finding = ( new Cf7AssetsRule() )->analyze( $facts );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertNull( $finding->recommendation, 'Phase 13 adds no unloading tweaks' );

		$this->assertStringContainsString( 'loaded on 6 pages, forms on 1', $finding->title );
		$this->assertStringContainsString( 'Of 6 pages sampled', $finding->summary );
		$this->assertStringContainsString( 'WPCF7_LOAD_JS', $finding->why );
	}

	/**
	 * Assets are still not part of the score.
	 *
	 * @return void
	 */
	public function test_assets_are_reported_but_not_scored(): void {
		$sub_scores = ( new \Debloater\Analyze\Score( array() ) )->subScores();

		$this->assertArrayNotHasKey( 'assets', $sub_scores );
	}

	/**
	 * Run the asset scan and return the facts, optionally overridden.
	 *
	 * @param array<string,mixed> $overrides Facts to replace afterwards.
	 * @return \Debloater\Contracts\FactSet
	 */
	private function scan( array $overrides = array() ): \Debloater\Contracts\FactSet {
		$facts = ( new AssetScanner() )->scan( $this->context(), new \Debloater\Contracts\FactSet() );

		if ( array() === $overrides ) {
			return $facts;
		}

		$merged = array_merge( $facts->toArray(), $overrides );

		return \Debloater\Contracts\FactSet::fromArray( $merged );
	}
}
