<?php
/**
 * Nothing the engine throws reaches a user as raw output.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Brand;
use Debloater\Rest\Controller;
use Debloater\Tests\Integration\Support\ThrowingRoute;
use WP_REST_Request;

/**
 * BUILD-SPEC §13 rule 4, §17 Phase 18.
 *
 * The engine throws on purpose. Every contract validates in its constructor and
 * refuses rather than coerces (docs/DECISIONS.md D-0002), which is the right
 * behaviour for a plugin that edits people's sites — but it means several
 * hundred `throw` statements carry a message built from a value, and none of
 * those messages may ever be printed raw.
 *
 * Rule 4 says output is escaped at the edge. This asserts the edges exist and
 * hold, which is what lets the throw sites stay readable rather than being
 * wrapped in `esc_html()` several hundred times — including in
 * `src/Contracts/` and `src/Registry/`, which are forbidden from calling
 * WordPress at all and so could not be wrapped even in principle.
 *
 * Two edges, failing differently:
 *
 *   - **REST.** WordPress does not catch exceptions from a route callback, so
 *     one that escapes becomes a PHP fatal: an empty body to a dashboard
 *     waiting for JSON, and on a site with `display_errors` on, a stack trace
 *     in the response.
 *   - **admin_init.** Crash recovery runs there, on every admin page load, and
 *     it runs precisely when the previous request did not finish — so it reads
 *     the state least likely to be well-formed. An exception would make every
 *     wp-admin page fatal, locking somebody out of the only screen that could
 *     fix it.
 *
 * Both were open until Phase 18. Plugin Check's `ExceptionNotEscaped` is what
 * pointed at them.
 */
final class ExceptionBoundaryTest extends IntegrationTestCase {

	/**
	 * Prepare a REST server and an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->plugin->schema()->ensure();

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A route that throws answers 500, rather than fataling.
	 *
	 * @return void
	 */
	public function test_a_throwing_route_returns_an_error_response(): void {
		$response = $this->dispatchThrowingRoute();

		$this->assertSame(
			500,
			$response->get_status(),
			'A throwing route must answer 500, not take the request down.'
		);

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'debloater_unexpected_error', $data['code'] );
	}

	/**
	 * The reason survives, and it leaves as JSON.
	 *
	 * @return void
	 */
	public function test_the_error_body_is_encoded_on_the_way_out(): void {
		$response = $this->dispatchThrowingRoute();
		$body     = (string) wp_json_encode( $response->get_data() );

		// Whatever the message said, no markup leaves. Note that JSON encoding
		// alone would *not* have achieved this: `wp_json_encode()` passes `<`
		// and `>` straight through, which is why the boundary escapes rather
		// than trusting the serialiser.
		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( '</script>', $body );

		// Neutralised, not discarded. The escaped form is still there, so
		// somebody reading the response can see what actually went wrong — a
		// boundary that throws the reason away makes every failure look
		// identical, which is worse than a fatal because it looks like it is
		// working.
		$this->assertStringContainsString( '&lt;script&gt;', $body );
		$this->assertStringContainsString( 'alert', $body );

		// It says where, too. One generic 500 across nine routes is not a
		// report anybody can act on.
		$data = $response->get_data();

		$this->assertSame( '/test-throws', $data['data']['where'] );
	}

	/**
	 * Every registered route is behind the boundary, not just the test one.
	 *
	 * @return void
	 */
	public function test_every_route_is_wrapped(): void {
		$routes = rest_get_server()->get_routes( Brand::REST_NAMESPACE );

		$this->assertNotEmpty( $routes );

		$checked = 0;

		foreach ( $routes as $path => $handlers ) {
			if ( '/' . Brand::REST_NAMESPACE === $path ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$this->assertInstanceOf(
					\Closure::class,
					$handler['callback'],
					sprintf(
						'%s is registered with a bare callback, so anything it throws would be a fatal.',
						$path
					)
				);

				++$checked;
			}
		}

		$this->assertGreaterThan( 5, $checked, 'this should have covered every route' );
	}

	/**
	 * Crash recovery on admin_init cannot take wp-admin down.
	 *
	 * @return void
	 */
	public function test_boot_recovery_swallows_what_it_cannot_do(): void {
		// The tables are gone. That is not a contrived failure: it is what a
		// half-finished migration or a partly restored database looks like, and
		// it is exactly when somebody most needs the admin to load.
		$this->plugin->schema()->drop();

		$this->assertFalse( $this->plugin->schema()->tablesExist() );

		$this->plugin->recoverOnBoot();

		// Reaching this line is the assertion: nothing escaped.
		$this->assertFalse( $this->plugin->schema()->tablesExist() );

		// Still idempotent, so the next admin page load retries rather than
		// the site staying broken until somebody intervenes.
		$this->plugin->recoverOnBoot();

		$this->plugin->schema()->ensure();

		$this->assertTrue( $this->plugin->schema()->tablesExist() );
		$this->assertSame( array(), $this->plugin->recoverInterruptedRuns() );
	}

	/**
	 * Register a throwing route through the real Controller and call it.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatchThrowingRoute(): \WP_REST_Response {
		$controller = new Controller( $this->plugin, array( new ThrowingRoute() ) );

		$controller->registerRoutes();

		$request = new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . '/test-throws' );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_get_server()->dispatch( $request );
	}
}
