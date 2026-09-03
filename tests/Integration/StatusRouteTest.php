<?php
/**
 * Tests for GET debloater/v1/status.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Brand;
use Debloater\Security\Capabilities;

/**
 * The status endpoint is what the runtime_loaded probe reads, so it has to
 * report what is actually on disk, and it has to be closed to everyone else
 * (BUILD-SPEC §13 rule 1).
 */
final class StatusRouteTest extends IntegrationTestCase {

	/**
	 * Set up the REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * The route is registered under the specified namespace.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Brand::REST_NAMESPACE . '/status', $routes );
	}

	/**
	 * An anonymous request is refused with 401, not answered.
	 *
	 * The scan and status data describe the site's configuration in detail;
	 * that is not something to hand to an unauthenticated caller.
	 *
	 * @return void
	 */
	public function test_an_anonymous_request_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->request();

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A logged-in user without the capability gets 403.
	 *
	 * @return void
	 */
	public function test_a_subscriber_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->request();

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An administrator gets the status.
	 *
	 * @return void
	 */
	public function test_an_administrator_gets_the_status(): void {
		$this->asAdministrator();

		$response = $this->request();

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( $this->plugin->version(), $data['plugin_version'] );
		$this->assertSame( $this->plugin->registry()->hash(), $data['registry_hash'] );
	}

	/**
	 * The capability is granted by mapping, not by editing roles, so an
	 * administrator has it and the roles table is untouched.
	 *
	 * @return void
	 */
	public function test_the_capability_is_mapped_not_granted(): void {
		$this->asAdministrator();

		$this->assertTrue( current_user_can( Capabilities::MANAGE ) );

		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );
		$this->assertArrayNotHasKey(
			Capabilities::MANAGE,
			$role->capabilities,
			'the capability must be mapped at runtime, never written into the roles table'
		);
	}

	/**
	 * With nothing selected, the status says so plainly.
	 *
	 * @return void
	 */
	public function test_status_reports_no_runtime_when_nothing_is_selected(): void {
		$this->asAdministrator();
		$this->selectAndGenerate( array() );

		$data = $this->request()->get_data();

		$this->assertSame( array(), $data['selection'] );
		$this->assertSame( 0, $data['selection_count'] );
		$this->assertFalse( $data['runtime']['present'] );
		$this->assertTrue( $data['runtime']['intact'] );
		$this->assertTrue( $data['runtime']['matches_state'] );
		$this->assertSame( 'none', $data['loader']['mode'] );
	}

	/**
	 * With a selection, the status reports the runtime and its hash.
	 *
	 * @return void
	 */
	public function test_status_reports_the_runtime_hash(): void {
		$this->asAdministrator();

		$hash = $this->selectAndGenerate(
			array(
				'core.remove_rsd'       => array(),
				'core.remove_generator' => array(),
			)
		);

		$data = $this->request()->get_data();

		$this->assertSame( array( 'core.remove_generator', 'core.remove_rsd' ), $data['selection'] );
		$this->assertSame( 2, $data['selection_count'] );
		$this->assertTrue( $data['runtime']['present'] );
		$this->assertSame( $hash, $data['runtime']['hash'] );
		$this->assertSame( $hash, $data['runtime']['recorded'] );
		$this->assertSame( $hash, $data['runtime']['expected'] );
		$this->assertTrue( $data['runtime']['intact'] );
		$this->assertTrue( $data['runtime']['matches_state'] );
		$this->assertSupportedLoaderMode( $data['loader']['mode'] );
	}

	/**
	 * A runtime edited on disk is reported as not intact, so the dashboard can
	 * say what happened instead of quietly disagreeing with reality.
	 *
	 * @return void
	 */
	public function test_status_reports_a_tampered_runtime(): void {
		$this->asAdministrator();

		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$runtime = $this->context()->runtimeFile();

		file_put_contents( $runtime, file_get_contents( $runtime ) . "\n// tampered\n" );

		$data = $this->request()->get_data();

		$this->assertFalse( $data['runtime']['intact'] );
		$this->assertFalse( $data['runtime']['matches_state'] );
		$this->assertNotSame( $data['runtime']['hash'], $data['runtime']['recorded'] );
	}

	/**
	 * The status response carries no secrets and no personal data.
	 *
	 * @return void
	 */
	public function test_status_exposes_nothing_sensitive(): void {
		$this->asAdministrator();
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$encoded = wp_json_encode( $this->request()->get_data() );

		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( ABSPATH, $encoded, 'absolute paths must not leak through the API' );

		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'NONCE_SALT' ) as $secret ) {
			if ( defined( $secret ) && is_string( constant( $secret ) ) && strlen( (string) constant( $secret ) ) > 16 ) {
				$this->assertStringNotContainsString( (string) constant( $secret ), $encoded, $secret );
			}
		}
	}

	/**
	 * Become an administrator.
	 *
	 * @return void
	 */
	private function asAdministrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatch a status request.
	 *
	 * @return \WP_REST_Response
	 */
	private function request(): \WP_REST_Response {
		return rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . '/status' )
		);
	}
}
