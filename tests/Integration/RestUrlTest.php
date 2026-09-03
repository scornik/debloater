<?php
/**
 * The URLs the admin screen actually asks for.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Rest\Controller;

/**
 * BUILD-SPEC §17 Phase 8, docs/DECISIONS.md D-0041.
 *
 * Every other REST test in this suite builds a `WP_REST_Request` by hand and
 * dispatches it. That checks the routes, the capabilities and the payloads —
 * and it never once builds a URL, which is why it did not notice that the admin
 * screen could not reach any of them on a site with plain permalinks.
 *
 * Plain permalinks are WordPress's default. `rest_url()` returns
 * `…/index.php?rest_route=/` there rather than `…/wp-json/`, and a client that
 * joins a namespaced root onto a path produces `…/debloater/v1//status`, which
 * matches no route. Every screen showed "No route was found matching the URL",
 * and the first thing to catch it was a browser opening the page.
 *
 * So this test does what the browser does: it composes the URL from the same
 * two values the screen hands the bundle, parses it the way WordPress parses an
 * incoming request, and dispatches it. Under both permalink structures.
 */
final class RestUrlTest extends IntegrationTestCase {

	/**
	 * The permalink structure the site had before a test changed it.
	 *
	 * @var string
	 */
	private string $permalinks = '';

	/**
	 * Prepare the REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->plugin->schema()->ensure();
		$this->permalinks = (string) get_option( 'permalink_structure' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Put the permalinks back.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->setPermalinks( $this->permalinks );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Every route the screen asks for resolves, under both permalink
	 * structures.
	 *
	 * @dataProvider permalinkProvider
	 *
	 * @param string $structure Permalink structure to use.
	 * @param string $label     What that structure is called.
	 * @return void
	 */
	public function test_every_screen_request_resolves( string $structure, string $label ): void {
		$this->setPermalinks( $structure );

		foreach ( array( '/status', '/findings', '/snapshots' ) as $path ) {
			$url = $this->urlTheScreenWouldAsk( $path );

			$request = WP_REST_Request::from_url( $url );

			$this->assertNotFalse(
				$request,
				sprintf( '%s: WordPress could not parse "%s" as a REST request.', $label, $url )
			);

			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_get_server()->dispatch( $request );

			$this->assertNotSame(
				404,
				$response->get_status(),
				sprintf(
					'%s: the screen would ask for "%s" and get "No route was found matching the URL".',
					$label,
					$url
				)
			);
		}
	}

	/**
	 * A query string on a request survives the join too.
	 *
	 * The findings screen filters by risk and decision, and on plain permalinks
	 * those parameters have to be appended with `&` rather than `?` — the URL
	 * already has one. Getting that wrong loses the filter silently, which is
	 * worse than an error.
	 *
	 * @dataProvider permalinkProvider
	 *
	 * @param string $structure Permalink structure to use.
	 * @param string $label     What that structure is called.
	 * @return void
	 */
	public function test_a_filtered_request_keeps_its_parameters( string $structure, string $label ): void {
		$this->setPermalinks( $structure );

		$url     = $this->urlTheScreenWouldAsk( '/findings' ) . ( str_contains( $this->urlTheScreenWouldAsk( '/findings' ), '?' ) ? '&' : '?' ) . 'risk=safe';
		$request = WP_REST_Request::from_url( $url );

		$this->assertNotFalse( $request, $label . ': ' . $url );
		$this->assertSame( 'safe', $request->get_param( 'risk' ), $label . ': the filter was lost in the URL' );
	}

	/**
	 * The bootstrap hands the screen a bare root and the namespace separately.
	 *
	 * This is the assertion that pins the fix. A namespaced root is exactly
	 * what broke, and it broke in a way that looked fine in every server-side
	 * test.
	 *
	 * @return void
	 */
	public function test_the_bootstrap_root_carries_no_namespace(): void {
		$bootstrap = $this->bootstrap();

		$this->assertArrayHasKey( 'root', $bootstrap );
		$this->assertArrayHasKey( 'namespace', $bootstrap );

		$this->assertStringNotContainsString(
			Controller::NAMESPACE,
			$bootstrap['root'],
			'the root must not carry the namespace; the client joins the two'
		);

		$this->assertSame( Controller::NAMESPACE, $bootstrap['namespace'] );
	}

	/**
	 * Permalink structures worth checking.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function permalinkProvider(): array {
		return array(
			// The default on a fresh WordPress, and the one that was broken.
			'plain'  => array( '', 'plain permalinks' ),
			'pretty' => array( '/%postname%/', 'pretty permalinks' ),
		);
	}

	/**
	 * The URL the admin bundle would request for a path.
	 *
	 * Composed exactly as `admin-ui/src/api/client.js` composes it: the root
	 * with a trailing slash, then the namespace, then the path.
	 *
	 * @param string $path Path such as "/status".
	 * @return string
	 */
	private function urlTheScreenWouldAsk( string $path ): string {
		$bootstrap = $this->bootstrap();

		$root      = rtrim( (string) $bootstrap['root'], '/' ) . '/';
		$namespace = trim( (string) $bootstrap['namespace'], '/' );

		// api-fetch's root middleware strips the leading slash from the path
		// before joining, and turns a `?` in the path into `&` when the root
		// already has one.
		return $root . $namespace . $path;
	}

	/**
	 * The data the admin screen hands its bundle.
	 *
	 * @return array<string,mixed>
	 */
	private function bootstrap(): array {
		$screen = new \ReflectionClass( \Debloater\Admin\Screen::class );
		$method = $screen->getMethod( 'bootstrapData' );

		$method->setAccessible( true );

		/** @var array<string,mixed> $data */
		$data = $method->invoke( new \Debloater\Admin\Screen( $this->plugin ) );

		return $data;
	}

	/**
	 * Change the permalink structure and rebuild the rewrite rules.
	 *
	 * @param string $structure Permalink structure.
	 * @return void
	 */
	private function setPermalinks( string $structure ): void {
		global $wp_rewrite;

		update_option( 'permalink_structure', $structure );

		$wp_rewrite->init();
		$wp_rewrite->flush_rules();
	}
}
