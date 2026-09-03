<?php
/**
 * The WooCommerce scan, and the refusal that protects a checkout.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Analyze\Analyzer;
use Debloater\Analyze\DontTouchRules;
use Debloater\Analyze\Rules;
use Debloater\Analyze\Rules\CartFragmentsRule;
use Debloater\Contracts\Decision;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\ProbeStatus;
use Debloater\Registry\SchemaValidator;
use Debloater\Scan\SampledPages;
use Debloater\Scan\Scanners\WooCommerceScanner;

/**
 * BUILD-SPEC §11 and §17 Phase 15.
 *
 * A store is where being wrong is most expensive. Two assertions here are worth
 * more than the rest put together: that a site with a mini-cart is refused the
 * cart-fragments change rather than warned about it, and that the checkout still
 * renders with every WooCommerce change applied at once.
 *
 * WooCommerce is not installed in the test environment, so pages are served from
 * fixtures — the same environment limitation Phase 13 records. What the fixtures
 * cannot exercise is WooCommerce's own conditional tags inside the handlers;
 * what they do exercise is the classification, the refusal and the probes, which
 * is where the decisions are made.
 */
final class WooCommerceScanTest extends IntegrationTestCase {

	/**
	 * Pages the fixture site serves, keyed by path.
	 *
	 * @var array<string,string>
	 */
	private array $routes = array();

	/**
	 * Prepare the tables and pretend WooCommerce is active.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		// The option rather than the WC_VERSION constant: a constant cannot be
		// undone and would follow every later test in the process around.
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
	}

	/**
	 * Leave the site as it was found.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		update_option( 'active_plugins', array() );

		parent::tear_down();
	}

	/**
	 * Pages are classified as shop or not-shop with at least 95% accuracy.
	 *
	 * The exit criterion for this phase.
	 *
	 * @return void
	 */
	public function test_page_classification_is_at_least_95_percent_accurate(): void {
		$expected = array(
			'/'             => false,
			'/hello-world/' => false,
			'/shop/'        => true,
			'/cart/'        => true,
			'/checkout/'    => true,
			'/my-account/'  => true,
		);

		$this->serve(
			array(
				'/'             => 'woo-blog-page.html',
				'/hello-world/' => 'woo-blog-page.html',
				'/shop/'        => 'woo-shop-page.html',
				'/cart/'        => 'woo-cart-page.html',
				'/checkout/'    => 'woo-checkout-page.html',
				'/my-account/'  => 'woo-account-page.html',
			)
		);

		$facts = $this->scan();

		$shop  = $facts->value( 'woo.shop_pages', array() );
		$other = $facts->value( 'woo.other_pages', array() );

		$right = 0;
		$wrong = array();

		foreach ( $expected as $path => $is_shop ) {
			$classified_as_shop  = in_array( $path, $shop, true );
			$classified_as_other = in_array( $path, $other, true );

			if ( ! $classified_as_shop && ! $classified_as_other ) {
				// Not sampled at all — the sample chooses one URL per post type,
				// so not every seeded page is reached. Not a misclassification.
				continue;
			}

			if ( $classified_as_shop === $is_shop ) {
				++$right;
			} else {
				$wrong[ $path ] = $is_shop ? 'should be shop' : 'should not be shop';
			}
		}

		$total = $right + count( $wrong );

		$this->assertGreaterThan( 0, $total, 'some pages should have been classified' );

		$accuracy = $right / $total;

		$this->assertGreaterThanOrEqual(
			0.95,
			$accuracy,
			sprintf( 'classification was %.0f%% accurate; wrong: %s', $accuracy * 100, (string) wp_json_encode( $wrong ) )
		);
	}

	/**
	 * Cart fragments on a page that is not the shop are reported, with the pages
	 * named.
	 *
	 * @return void
	 */
	public function test_fragments_away_from_the_shop_are_reported(): void {
		$this->serve( array( '/' => 'woo-blog-page.html' ) );

		$facts = $this->scan();

		$this->assertContains( '/', $facts->value( 'woo.fragments_on_other', array() ) );

		$finding = ( new CartFragmentsRule() )->analyze( $facts );

		$this->assertNotNull( $finding );
		$this->assertSame( 'woo.cart_fragments_conditional', $finding->recommendedTweakId() );
		$this->assertStringContainsString( '/', $finding->summary );
	}

	/**
	 * A mini-cart turns that recommendation into a refusal.
	 *
	 * The assertion this phase exists for. Most shop themes put a cart total in
	 * the header; on such a site the fragments are needed everywhere, and making
	 * them conditional leaves a number that never updates.
	 *
	 * @return void
	 */
	public function test_a_mini_cart_turns_the_recommendation_into_a_refusal(): void {
		$this->serve( array( '/' => 'woo-blog-with-mini-cart.html' ) );

		$facts = $this->scan();

		$this->assertTrue( $facts->value( 'woo.mini_cart' ), 'the header shows a cart' );

		$finding = ( new CartFragmentsRule() )->analyze( $facts );

		$this->assertNotNull( $finding );

		$refused = ( new DontTouchRules( $this->plugin->registry(), $facts ) )->apply( $finding );

		$this->assertSame( Decision::DONT_TOUCH, $refused->decision );
		$this->assertFalse( $refused->isPlannable(), 'a refused finding never reaches a plan' );
		$this->assertStringContainsString( 'shows a cart away from the shop', (string) $refused->decision_reason );
	}

	/**
	 * And without a mini-cart it stays a recommendation.
	 *
	 * A refusal that fired on every store would be as useless as one that never
	 * fired.
	 *
	 * @return void
	 */
	public function test_without_a_mini_cart_the_change_is_still_offered(): void {
		$this->serve( array( '/' => 'woo-blog-page.html' ) );

		$facts   = $this->scan();
		$finding = ( new CartFragmentsRule() )->analyze( $facts );

		$this->assertNotNull( $finding );

		$considered = ( new DontTouchRules( $this->plugin->registry(), $facts ) )->apply( $finding );

		$this->assertSame( Decision::RECOMMEND, $considered->decision );
	}

	/**
	 * The checkout probe passes with every WooCommerce change applied at once.
	 *
	 * The other exit criterion, and the one that matters to a shop owner.
	 *
	 * @return void
	 */
	public function test_the_checkout_probe_passes_with_every_woo_tweak_applied(): void {
		$checkout_id = $this->seedWooPage( 'checkout', 'woocommerce_checkout_page_id' );

		$this->serve( array( get_permalink( $checkout_id ) => 'woo-checkout-page.html' ) );

		$this->selectAndGenerate(
			array(
				'woo.cart_fragments_conditional'       => array(),
				'woo.block_styles_conditional'         => array(),
				'woo.disable_admin_analytics'          => array(),
				'woo.suppress_marketplace_suggestions' => array(),
			)
		);
		$this->loadRuntime();

		try {
			$probe  = new \Debloater\Verify\Probes\WooCheckoutProbe( $this->plugin->httpClient() );
			$result = $probe->run( $this->context() );

			$this->assertSame(
				ProbeStatus::PASS,
				$result->status,
				'a checkout that stops working is worth more than every request this phase saves: ' . $result->message
			);
		} finally {
			$this->unregisterHandlers(
				array(
					'woo.cart_fragments_conditional',
					'woo.block_styles_conditional',
					'woo.disable_admin_analytics',
					'woo.suppress_marketplace_suggestions',
				)
			);
		}
	}

	/**
	 * A checkout page that lost its form is a failure, not a pass.
	 *
	 * The probe is only worth having if it would notice.
	 *
	 * @return void
	 */
	public function test_a_checkout_without_a_form_fails_the_probe(): void {
		$checkout_id = $this->seedWooPage( 'checkout', 'woocommerce_checkout_page_id' );

		$this->serveBody(
			get_permalink( $checkout_id ),
			'<!DOCTYPE html><html><head><title>Checkout</title></head>'
				. '<body class="woocommerce-checkout"><p>Something went wrong.</p></body></html>'
		);

		$probe  = new \Debloater\Verify\Probes\WooCheckoutProbe( $this->plugin->httpClient() );
		$result = $probe->run( $this->context() );

		$this->assertSame( ProbeStatus::FAIL, $result->status );
		$this->assertStringContainsString( 'form', $result->message );
	}

	/**
	 * A store with no checkout page is not tested rather than failed.
	 *
	 * @return void
	 */
	public function test_a_store_without_a_checkout_page_is_not_tested(): void {
		$probe = new \Debloater\Verify\Probes\WooCheckoutProbe( $this->plugin->httpClient() );

		$this->assertFalse( $probe->applies( $this->context(), new FactSet() ) );
	}

	/**
	 * All four Woo tweaks register and unregister cleanly.
	 *
	 * @return void
	 */
	public function test_every_woo_tweak_unregisters_cleanly(): void {
		$tweaks = array(
			'woo.cart_fragments_conditional',
			'woo.block_styles_conditional',
			'woo.disable_admin_analytics',
			'woo.suppress_marketplace_suggestions',
		);

		foreach ( $tweaks as $tweak_id ) {
			$before = $this->hookSnapshot();

			$this->selectAndGenerate( array( $tweak_id => array() ) );
			$this->loadRuntime();
			$this->unregisterHandlers( array( $tweak_id ) );

			$after = $this->hookSnapshot();

			$this->assertSame( array(), $this->hooksAdded( $before, $after ), $tweak_id );
			$this->assertSame( array(), $this->hooksRemoved( $before, $after ), $tweak_id );
		}
	}

	/**
	 * The facts validate against the schema.
	 *
	 * @return void
	 */
	public function test_the_facts_validate(): void {
		$this->serve( array( '/' => 'woo-blog-page.html' ) );

		$violations = SchemaValidator::fromFile( DEBLOATER_TESTS_ROOT . '/registry/schemas/fact.schema.json' )
			->validate( $this->scan()->toArray() );

		$this->assertSame( array(), $violations, implode( '; ', array_map( 'strval', $violations ) ) );
	}

	/**
	 * Every WooCommerce tweak names the cart, checkout and account probes.
	 *
	 * A change to a store that is not verified against the three pages a store
	 * cannot lose is a change nobody should apply.
	 *
	 * @return void
	 */
	public function test_every_frontend_woo_tweak_is_verified_against_the_shop(): void {
		foreach ( array( 'woo.cart_fragments_conditional', 'woo.block_styles_conditional' ) as $tweak_id ) {
			$probes = $this->plugin->registry()->tweak( $tweak_id )->probes;

			foreach ( array( 'woo_cart', 'woo_checkout', 'woo_account' ) as $probe ) {
				$this->assertContains( $probe, $probes, $tweak_id . ' must be verified by ' . $probe );
			}
		}
	}

	/**
	 * With no shop, the namespace holds one fact and claims nothing else.
	 *
	 * @return void
	 */
	public function test_a_site_without_woocommerce_claims_nothing(): void {
		update_option( 'active_plugins', array() );

		$facts = $this->scan()->toArray();

		$this->assertSame( array( 'woo.present' => false ), $facts );
	}

	/**
	 * Facts from a WooCommerce scan.
	 *
	 * @return FactSet
	 */
	private function scan(): FactSet {
		$sample = new SampledPages();

		$sample->forget();

		return ( new WooCommerceScanner( $sample ) )->scan( $this->context(), new FactSet() );
	}

	/**
	 * Create a published page and point a WooCommerce page option at it.
	 *
	 * @param string $slug   Page slug.
	 * @param string $option WooCommerce option holding the page id.
	 * @return int
	 */
	private function seedWooPage( string $slug, string $option ): int {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => $slug,
				'post_title'  => ucfirst( $slug ),
			)
		);

		update_option( $option, $page_id );

		return $page_id;
	}

	/**
	 * Serve fixture pages, keyed by path or absolute URL.
	 *
	 * Anything not named gets the blog page, so the sample always has something
	 * to read.
	 *
	 * @param array<string,string> $routes Path or URL to fixture file name.
	 * @return void
	 */
	private function serve( array $routes ): void {
		$this->routes = array();

		foreach ( $routes as $path => $fixture ) {
			$body = (string) file_get_contents( DEBLOATER_TESTS_ROOT . '/tests/Fixtures/html/' . $fixture );

			$this->routes[ $this->normalise( (string) $path ) ] = str_replace(
				'http://example.test',
				untrailingslashit( home_url() ),
				$body
			);
		}

		$fallback = (string) file_get_contents( DEBLOATER_TESTS_ROOT . '/tests/Fixtures/html/woo-blog-page.html' );
		$fallback = str_replace( 'http://example.test', untrailingslashit( home_url() ), $fallback );

		$this->intercept( $fallback );
	}

	/**
	 * Serve one exact body for one URL, and the blog page for anything else.
	 *
	 * @param string $url  URL to answer.
	 * @param string $body Body to answer with.
	 * @return void
	 */
	private function serveBody( string $url, string $body ): void {
		$this->routes = array( $this->normalise( $url ) => $body );

		$this->intercept( '<!DOCTYPE html><html><head><title>A page</title></head><body>Hello</body></html>' );
	}

	/**
	 * Answer every outbound request from the route table.
	 *
	 * @param string $fallback Body for anything not in the table.
	 * @return void
	 */
	private function intercept( string $fallback ): void {
		remove_all_filters( 'pre_http_request' );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $fallback ) {
				unset( $preempt, $args );

				$path = $this->normalise( (string) $url );

				return array(
					'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
					'body'     => $this->routes[ $path ] ?? $fallback,
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
	 * A URL reduced to the path the route table is keyed on.
	 *
	 * @param string $url Path or absolute URL.
	 * @return string
	 */
	private function normalise( string $url ): string {
		$home = untrailingslashit( home_url() );

		if ( '' !== $home && 0 === strpos( $url, $home ) ) {
			$url = substr( $url, strlen( $home ) );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}
}
