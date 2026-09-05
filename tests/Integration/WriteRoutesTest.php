<?php
/**
 * The two endpoints that can change a site.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Apply\Lock;
use Debloater\Apply\RuntimeLoader;
use Debloater\Brand;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Rest\ConfirmationToken;

/**
 * BUILD-SPEC §13 rules 1 and 12, and §17 Phase 8.
 *
 * Three gates, and a test for each: the capability, the nonce, and a
 * confirmation token derived from the exact plan or recovery point being acted
 * on. The last one is the one that is easy to leave out and the one that makes
 * "the user agreed to this" a fact rather than an assumption.
 */
final class WriteRoutesTest extends IntegrationTestCase {

	/**
	 * A body that looks like a page that rendered.
	 */
	private const GOOD_HTML = '<!DOCTYPE html><html><head><title>A site</title></head><body>Hi</body></html>';

	/**
	 * Set up the REST server and the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Clear filters and the lock.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		( new Lock() )->forceRelease();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Both write routes are registered.
	 *
	 * @return void
	 */
	public function test_the_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Brand::REST_NAMESPACE . '/apply', $routes );
		$this->assertArrayHasKey( '/' . Brand::REST_NAMESPACE . '/rollback', $routes );
		$this->assertArrayHasKey( '/' . Brand::REST_NAMESPACE . '/snapshots', $routes );
	}

	/**
	 * An anonymous caller cannot apply anything.
	 *
	 * @return void
	 */
	public function test_an_anonymous_apply_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->post( '/apply', array( 'confirm' => str_repeat( 'a', 64 ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A signed-in user without the capability cannot apply anything, even with
	 * a valid nonce.
	 *
	 * @return void
	 */
	public function test_a_subscriber_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->post(
			'/apply',
			array( 'confirm' => str_repeat( 'a', 64 ) ),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An administrator without a nonce is refused.
	 *
	 * @return void
	 */
	public function test_an_administrator_without_a_nonce_is_refused(): void {
		$this->beAdministrator();

		$response = $this->post( '/apply', array( 'confirm' => str_repeat( 'a', 64 ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'debloater_bad_nonce', $response->get_data()['code'] );
	}

	/**
	 * A stale or invented confirmation token is refused, and nothing is
	 * applied.
	 *
	 * @return void
	 */
	public function test_a_wrong_confirmation_token_is_refused(): void {
		$this->beAdministrator();
		$this->serveHealthySite();
		$this->plugin->scan();

		$response = $this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => str_repeat( 'f', 64 ),
			),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'debloater_stale_confirmation', $response->get_data()['code'] );
		$this->assertSame( array(), $this->plugin->state()->selection() );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
	}

	/**
	 * The token the preview issues applies that plan, and only that plan.
	 *
	 * @return void
	 */
	public function test_the_preview_token_applies_the_plan(): void {
		$this->beAdministrator();
		$this->serveHealthySite();
		$this->plugin->scan();

		$preview = $this->get( '/preview', array( 'profile' => 'safe' ) );

		$this->assertSame( 200, $preview->get_status() );

		$token = $preview->get_data()['confirm'];

		$this->assertIsString( $token );
		$this->assertSame( 64, strlen( $token ) );

		$response = $this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $token,
			),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'], (string) ( $response->get_data()['result']['error'] ?? '' ) );
		$this->assertNotSame( array(), $this->plugin->state()->selection() );
		$this->assertFileExists( $this->context()->runtimeFile() );
	}

	/**
	 * A token issued for one plan does not apply a different one.
	 *
	 * @return void
	 */
	public function test_a_token_for_one_plan_does_not_apply_another(): void {
		$this->beAdministrator();
		$this->serveHealthySite();
		$this->plugin->scan();

		$narrow = $this->get( '/preview', array( 'tweaks' => array( 'core.remove_rsd' ) ) );

		$this->assertSame( 200, $narrow->get_status() );

		$response = $this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $narrow->get_data()['confirm'],
			),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array(), $this->plugin->state()->selection() );
	}

	/**
	 * The snapshots route issues a token for each restorable recovery point,
	 * and that token restores it.
	 *
	 * @return void
	 */
	public function test_snapshots_issue_tokens_that_restore(): void {
		$this->beAdministrator();
		$this->serveHealthySite();
		$this->plugin->scan();

		$preview = $this->get( '/preview', array( 'profile' => 'safe' ) );

		$this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $preview->get_data()['confirm'],
			),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertFileExists( $this->context()->runtimeFile() );

		$listing = $this->get( '/snapshots' );

		$this->assertSame( 200, $listing->get_status() );

		$snapshots = $listing->get_data()['snapshots'];

		$this->assertNotEmpty( $snapshots );
		$this->assertTrue( $snapshots[0]['restorable'] );
		$this->assertIsString( $snapshots[0]['confirm'] );

		$response = $this->post(
			'/rollback',
			array(
				'snapshot_id' => $snapshots[0]['id'],
				'confirm'     => $snapshots[0]['confirm'],
			),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( array(), $this->plugin->state()->selection() );
	}

	/**
	 * A rollback with the wrong token is refused.
	 *
	 * @return void
	 */
	public function test_a_wrong_rollback_token_is_refused(): void {
		$this->beAdministrator();
		$this->serveHealthySite();
		$this->plugin->scan();

		$preview = $this->get( '/preview', array( 'profile' => 'safe' ) );

		$this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $preview->get_data()['confirm'],
			),
			wp_create_nonce( 'wp_rest' )
		);

		$response = $this->post(
			'/rollback',
			array( 'confirm' => str_repeat( '0', 64 ) ),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertFileExists( $this->context()->runtimeFile(), 'Nothing should have been restored.' );
	}

	/**
	 * The token is bound to the snapshot's contents, so it cannot be reused for
	 * a different recovery point.
	 *
	 * @return void
	 */
	public function test_a_snapshot_token_is_bound_to_that_snapshot(): void {
		$this->beAdministrator();
		$this->serveHealthySite();
		$this->plugin->scan();

		$preview = $this->get( '/preview', array( 'profile' => 'safe' ) );

		$this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $preview->get_data()['confirm'],
			),
			wp_create_nonce( 'wp_rest' )
		);

		$snapshot = $this->plugin->snapshots()->latestRestorable( SnapshotLevel::A );

		$this->assertNotNull( $snapshot );
		$this->assertTrue( ConfirmationToken::matchesSnapshot( $snapshot, ConfirmationToken::forSnapshot( $snapshot ) ) );
		$this->assertFalse( ConfirmationToken::matchesSnapshot( $snapshot, str_repeat( 'b', 64 ) ) );
	}

	/**
	 * Applying with nothing to apply is refused rather than recorded as a run.
	 *
	 * @return void
	 */
	public function test_applying_without_a_scan_is_refused(): void {
		$this->beAdministrator();

		$response = $this->post(
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => str_repeat( 'c', 64 ),
			),
			wp_create_nonce( 'wp_rest' )
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'debloater_not_scanned', $response->get_data()['code'] );
		$this->assertSame( 0, $this->plugin->runs()->count() );
	}

	/**
	 * Read routes stay readable without a nonce, so the dashboard's first paint
	 * is not gated on one.
	 *
	 * @return void
	 */
	public function test_read_routes_do_not_require_a_nonce(): void {
		$this->beAdministrator();

		$this->assertSame( 200, $this->get( '/status' )->get_status() );
	}

	/**
	 * Become an administrator.
	 *
	 * @return void
	 */
	private function beAdministrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->plugin->resetServices();
	}

	/**
	 * Perform a GET against the plugin's namespace.
	 *
	 * @param string              $path  Route path.
	 * @param array<string,mixed> $query Query parameters.
	 * @return \WP_REST_Response
	 */
	private function get( string $path, array $query = array() ) {
		$request = new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . $path );

		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Perform a POST against the plugin's namespace.
	 *
	 * @param string              $path  Route path.
	 * @param array<string,mixed> $body  Body parameters.
	 * @param string|null         $nonce Nonce to send, or null for none.
	 * @return \WP_REST_Response
	 */
	private function post( string $path, array $body = array(), ?string $nonce = null ) {
		$request = new WP_REST_Request( 'POST', '/' . Brand::REST_NAMESPACE . $path );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}

		return rest_get_server()->dispatch( $request );
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

				$body = self::GOOD_HTML;

				if ( 0 === strpos( $url, rest_url( 'debloater/v1/status' ) ) ) {
					$body = (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => $plugin->state()->runtimeHash() ),
							'loader'  => array( 'mode' => RuntimeLoader::MODE_MU_PLUGIN ),
						)
					);
				} elseif ( 0 === strpos( $url, rest_url() ) ) {
					$body = (string) wp_json_encode( array( 'name' => 'A site' ) );
				} elseif ( 0 === strpos( $url, wp_login_url() ) ) {
					$body = '<html><head><title>Log In</title></head><body><form id="loginform"></form></body></html>';
				} elseif ( 0 === strpos( $url, admin_url() ) ) {
					$body = '<html><head><title>Dashboard</title></head><body>'
						. '<div id="wpadminbar"><ul><li id="wp-admin-bar-my-account"></li></ul></div><div id="adminmenu"></div><div id="wpbody"></div></body></html>';
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
