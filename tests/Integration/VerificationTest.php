<?php
/**
 * Checking the site after a change.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use WP_Error;
use WPDebloat\Apply\Lock;
use WPDebloat\Apply\RuntimeLoader;
use WPDebloat\Contracts\PreviewPlan;
use WPDebloat\Contracts\ProbeStatus;
use WPDebloat\Contracts\RunState;
use WPDebloat\Verify\HttpClient;
use WPDebloat\Verify\Verifier;

/**
 * BUILD-SPEC §11.
 *
 * Probe behaviour is exercised against fixture responses served through
 * `pre_http_request`, for two reasons that are worth stating rather than
 * hiding.
 *
 * The first is coverage: a real site cannot be made to return "Fatal error", a
 * truncated document and a 502 on demand, and those are exactly the responses a
 * probe exists to recognise.
 *
 * The second is the test environment. wp-env runs the suite in a container
 * separate from the one serving the site, and the site's canonical address
 * (`localhost:8889`) resolves inside the runner to the runner itself — so the
 * site genuinely cannot reach itself here. That is not a workaround for the
 * blocked-loopback test below; it is what the blocked-loopback test is about,
 * and it is the behaviour a shared host with outbound requests disabled will
 * show. A verification over real HTTP against a site with committed state is
 * exercised on the fixture site in Phase 7.
 */
final class VerificationTest extends IntegrationTestCase {

	/**
	 * A body that looks like a page that rendered.
	 */
	private const GOOD_HTML = '<!DOCTYPE html><html><head><title>A site</title></head><body>Hello</body></html>';

	/**
	 * A body that looks like the dashboard.
	 */
	private const GOOD_ADMIN = '<!DOCTYPE html><html><head><title>Dashboard</title></head><body>'
		. '<div id="adminmenu"></div><div id="wpbody">Howdy</div></body></html>';

	/**
	 * A body that looks like the login screen.
	 */
	private const GOOD_LOGIN = '<!DOCTYPE html><html><head><title>Log In</title></head><body>'
		. '<form id="loginform"><input name="log"></form></body></html>';

	/**
	 * Prepare tables and take the acting user's identity.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->plugin->resetServices();
	}

	/**
	 * Stop intercepting HTTP.
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
	 * Every probe passes when the site answers as a working site does.
	 *
	 * @return void
	 */
	public function test_every_probe_passes_on_a_healthy_site(): void {
		$this->serveHealthySite();

		$result = $this->plugin->verifier()->verify();

		foreach ( $result->probes as $probe ) {
			$this->assertContains(
				$probe->status,
				array( ProbeStatus::PASS, ProbeStatus::NOT_TESTED ),
				sprintf( '%s: %s', $probe->probe, $probe->message )
			);
		}

		$this->assertSame( ProbeStatus::PASS, $result->status );
		$this->assertSame(
			array(
				'admin',
				'content_page',
				'home',
				'login',
				'rest',
				'runtime_loaded',
				'woo_account',
				'woo_cart',
				'woo_checkout',
			),
			array_map( static fn ( $probe ): string => $probe->probe, $result->probes ),
			'every registered probe reports, including the ones that do not apply to this site'
		);
	}

	/**
	 * A fatal marker in the body is a failure whatever the status code says.
	 *
	 * @return void
	 */
	public function test_a_fatal_marker_fails_even_with_a_200(): void {
		$this->serveHealthySite(
			array(
				home_url() => array(
					'status' => 200,
					'body'   => '<html><body>There has been a critical error on this website.</body></html>',
				),
			)
		);

		$result = $this->plugin->verifier()->verify();
		$home   = $this->probe( $result, 'home' );

		$this->assertSame( ProbeStatus::FAIL, $home->status );
		$this->assertSame( 'There has been a critical error', $home->evidence['fatal_marker'] );
		$this->assertSame( ProbeStatus::FAIL, $result->status, 'One failure fails the whole verification.' );
	}

	/**
	 * A non-2xx page is a failure.
	 *
	 * @return void
	 */
	public function test_a_server_error_fails(): void {
		$this->serveHealthySite(
			array(
				home_url() => array(
					'status' => 502,
					'body'   => 'Bad Gateway',
				),
			) 
		);

		$this->assertSame( ProbeStatus::FAIL, $this->probe( $this->plugin->verifier()->verify(), 'home' )->status );
	}

	/**
	 * An empty body is a failure, because a blank page is not a working page.
	 *
	 * @return void
	 */
	public function test_an_empty_body_fails(): void {
		$this->serveHealthySite(
			array(
				home_url() => array(
					'status' => 200,
					'body'   => "   \n",
				),
			) 
		);

		$this->assertSame( ProbeStatus::FAIL, $this->probe( $this->plugin->verifier()->verify(), 'home' )->status );
	}

	/**
	 * A page that arrived but looks truncated is a warning, not a failure.
	 *
	 * @return void
	 */
	public function test_missing_render_markers_warn(): void {
		$this->serveHealthySite(
			array(
				home_url() => array(
					'status' => 200,
					'body'   => '<!DOCTYPE html><html><head></head><body>It stops here',
				),
			)
		);

		$result = $this->plugin->verifier()->verify();
		$home   = $this->probe( $result, 'home' );

		$this->assertSame( ProbeStatus::WARN, $home->status );
		$this->assertStringContainsString( '</html>', (string) $home->evidence['missing_markers'] );
		$this->assertSame( ProbeStatus::WARN, $result->status );
	}

	/**
	 * The dashboard answering with a login form proves nothing either way.
	 *
	 * @return void
	 */
	public function test_the_admin_probe_reports_unknown_when_it_is_sent_to_the_login_form(): void {
		$this->serveHealthySite(
			array(
				admin_url() => array(
					'status' => 200,
					'body'   => self::GOOD_LOGIN,
				),
			) 
		);

		$admin = $this->probe( $this->plugin->verifier()->verify(), 'admin' );

		$this->assertSame( ProbeStatus::UNKNOWN, $admin->status );
		$this->assertStringContainsString( 'login form', $admin->message );
	}

	/**
	 * A dashboard missing its furniture is a warning.
	 *
	 * @return void
	 */
	public function test_a_dashboard_without_its_markers_warns(): void {
		$this->serveHealthySite(
			array(
				admin_url() => array(
					'status' => 200,
					'body'   => '<html><title>x</title><body>hi</body></html>',
				),
			)
		);

		$this->assertSame( ProbeStatus::WARN, $this->probe( $this->plugin->verifier()->verify(), 'admin' )->status );
	}

	/**
	 * A REST API closed to anonymous callers is a warning, not a failure: it is
	 * a choice a site is allowed to make.
	 *
	 * @return void
	 */
	public function test_a_closed_rest_api_warns_rather_than_fails(): void {
		$this->serveHealthySite(
			array(
				rest_url() => array(
					'status' => 401,
					'body'   => '{"code":"unauthorized"}',
				),
			) 
		);

		$rest = $this->probe( $this->plugin->verifier()->verify(), 'rest' );

		$this->assertSame( ProbeStatus::WARN, $rest->status );
		$this->assertStringContainsString( 'deliberate', $rest->message );
	}

	/**
	 * Output leaking into a JSON response is a failure.
	 *
	 * @return void
	 */
	public function test_broken_json_from_the_rest_api_fails(): void {
		$this->serveHealthySite(
			array(
				rest_url() => array(
					'status' => 200,
					'body'   => "Notice: something\n{\"name\":\"A site\"}",
				),
			)
		);

		$rest = $this->probe( $this->plugin->verifier()->verify(), 'rest' );

		$this->assertSame( ProbeStatus::FAIL, $rest->status );
		$this->assertStringContainsString( 'Notice', (string) $rest->evidence['body_starts_with'] );
	}

	/**
	 * A login page with no form on it is a warning.
	 *
	 * @return void
	 */
	public function test_a_login_page_without_a_form_warns(): void {
		$this->serveHealthySite(
			array(
				wp_login_url() => array(
					'status' => 200,
					'body'   => self::GOOD_HTML,
				),
			) 
		);

		$this->assertSame( ProbeStatus::WARN, $this->probe( $this->plugin->verifier()->verify(), 'login' )->status );
	}

	/**
	 * A runtime on disk that is not the one we generated is a failure: the site
	 * is running something other than what the user approved.
	 *
	 * @return void
	 */
	public function test_a_runtime_hash_mismatch_fails(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->serveHealthySite(
			array(
				rest_url( 'wpdebloat/v1/status' ) => array(
					'status' => 200,
					'body'   => (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => str_repeat( 'b', 64 ) ),
							'loader'  => array( 'mode' => RuntimeLoader::MODE_MU_PLUGIN ),
						)
					),
				),
			)
		);

		$probe = $this->probe( $this->plugin->verifier()->verify(), 'runtime_loaded' );

		$this->assertSame( ProbeStatus::FAIL, $probe->status );
		$this->assertStringContainsString( 'not the one this change generated', $probe->message );
	}

	/**
	 * A selection with no runtime behind it is a failure, not a pass: the site
	 * would be reporting changes it is not making.
	 *
	 * @return void
	 */
	public function test_a_selection_with_no_runtime_fails(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->serveHealthySite(
			array(
				rest_url( 'wpdebloat/v1/status' ) => array(
					'status' => 200,
					'body'   => (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => '' ),
							'loader'  => array( 'mode' => RuntimeLoader::MODE_NONE ),
						)
					),
				),
			)
		);

		$this->assertSame(
			ProbeStatus::FAIL,
			$this->probe( $this->plugin->verifier()->verify(), 'runtime_loaded' )->status
		);
	}

	/**
	 * The fallback loader works, but later in the request than it could, so it
	 * is reported as a warning.
	 *
	 * @return void
	 */
	public function test_the_fallback_loader_warns(): void {
		$hash = $this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->serveHealthySite(
			array(
				rest_url( 'wpdebloat/v1/status' ) => array(
					'status' => 200,
					'body'   => (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => $hash ),
							'loader'  => array( 'mode' => RuntimeLoader::MODE_FALLBACK ),
						)
					),
				),
			)
		);

		$probe = $this->probe( $this->plugin->verifier()->verify(), 'runtime_loaded' );

		$this->assertSame( ProbeStatus::WARN, $probe->status );
		$this->assertStringContainsString( 'mu-plugin', $probe->message );
	}

	/**
	 * With nothing selected there is nothing to load, and finding nothing is
	 * the correct answer rather than a missing runtime.
	 *
	 * @return void
	 */
	public function test_an_empty_selection_passes_without_a_runtime(): void {
		$this->serveHealthySite();

		$this->assertSame(
			ProbeStatus::PASS,
			$this->probe( $this->plugin->verifier()->verify(), 'runtime_loaded' )->status
		);
	}

	/**
	 * A site that cannot reach itself reports UNKNOWN everywhere, and that
	 * aggregates to a warning rather than a failure.
	 *
	 * @return void
	 */
	public function test_blocked_loopback_reports_unknown_and_warns(): void {
		// So that every probe that could apply does, and none of them reports
		// NOT_TESTED for a reason unrelated to the loopback.
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->blockLoopback();

		$result = $this->plugin->verifier()->verify();

		$unknown = 0;

		foreach ( $result->probes as $probe ) {
			// A probe with nothing to check on this site — the WooCommerce
			// probes on a site with no shop — was never going to run, blocked
			// loopback or not. What must not happen is a verdict: a site we
			// could not reach is not a site we checked.
			if ( ProbeStatus::NOT_TESTED === $probe->status ) {
				continue;
			}

			$this->assertSame(
				ProbeStatus::UNKNOWN,
				$probe->status,
				sprintf( '%s should be UNKNOWN when the site cannot reach itself.', $probe->probe )
			);

			$this->assertTrue( (bool) $probe->evidence['loopback_blocked'] );

			++$unknown;
		}

		$this->assertGreaterThan( 0, $unknown, 'some probes should have been attempted' );

		$this->assertSame( ProbeStatus::WARN, $result->status );
	}

	/**
	 * An apply on a site with blocked loopback keeps the change and says the
	 * checks could not run.
	 *
	 * @return void
	 */
	public function test_an_apply_with_blocked_loopback_commits_with_warnings(): void {
		$this->blockLoopback();

		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );
		$this->assertNotSame( array(), $result->warnings, 'The user must be told the checks could not run.' );
		$this->assertNotNull( $result->verification );
		$this->assertSame( ProbeStatus::WARN, $result->verification->status );

		$run = $this->plugin->runs()->find( $result->run_id );

		$this->assertSame( RunState::COMMITTED->value, $run->status );
		$this->assertContains(
			RunState::VERIFIED_WITH_WARNINGS->value,
			$run->payload['history'],
			'A run whose checks warned must record that it passed with warnings.'
		);
	}

	/**
	 * A clean verification commits without warnings.
	 *
	 * @return void
	 */
	public function test_a_clean_verification_commits_quietly(): void {
		$this->serveHealthySite();

		$result = $this->plugin->apply( $this->planOf( array( 'core.remove_generator' ) ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );
		$this->assertSame( array(), $result->warnings );
		$this->assertNotNull( $result->verification );
		$this->assertSame( ProbeStatus::PASS, $result->verification->status );
		$this->assertContains( RunState::VERIFIED->value, $this->historyOf( $result->run_id ) );
	}

	/**
	 * Every verification request carries the rules §11 sets out.
	 *
	 * @return void
	 */
	public function test_requests_carry_the_timeout_and_the_header(): void {
		$seen = array();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$seen ) {
				$seen[] = array(
					'url'       => $url,
					'timeout'   => $args['timeout'] ?? null,
					'header'    => $args['headers'][ HttpClient::HEADER ] ?? null,
					'sslverify' => $args['sslverify'] ?? null,
				);

				return array(
					'headers'  => array(),
					'body'     => self::GOOD_HTML,
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

		( new HttpClient( $this->context() ) )->get( home_url() );

		$this->assertCount( 1, $seen );
		$this->assertSame( HttpClient::TIMEOUT, $seen[0]['timeout'] );
		$this->assertSame( 15, HttpClient::TIMEOUT, '§11 fixes the timeout at fifteen seconds.' );
		$this->assertSame( '1', $seen[0]['header'] );
		$this->assertTrue( $seen[0]['sslverify'], 'sslverify follows the site setting, which defaults to on.' );
	}

	/**
	 * The site's own SSL setting is honoured rather than overridden.
	 *
	 * @return void
	 */
	public function test_ssl_verification_follows_the_site_setting(): void {
		$seen = null;

		add_filter( 'https_local_ssl_verify', '__return_false' );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$seen ) {
				$seen = $args['sslverify'] ?? null;

				return array(
					'headers'  => array(),
					'body'     => self::GOOD_HTML,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			2
		);

		( new HttpClient( $this->context() ) )->get( home_url() );

		$this->assertFalse( $seen );

		remove_filter( 'https_local_ssl_verify', '__return_false' );
	}

	/**
	 * A probe that has no content to check reports NOT_TESTED, and that does
	 * not drag the aggregate down.
	 *
	 * @return void
	 */
	public function test_a_probe_with_nothing_to_check_is_not_counted(): void {
		foreach ( get_posts(
			array(
				'post_type'   => array( 'post', 'page' ),
				'numberposts' => 100,
			) 
		) as $post ) {
			wp_delete_post( $post->ID, true );
		}

		$this->serveHealthySite();

		$result = $this->plugin->verifier()->verify();

		$this->assertSame( ProbeStatus::NOT_TESTED, $this->probe( $result, 'content_page' )->status );
		$this->assertSame( ProbeStatus::PASS, $result->status, 'NOT_TESTED must not affect the aggregate.' );
	}

	/**
	 * The forced-failure constant is read from the documented name.
	 *
	 * The behaviour it produces is covered by the separate fail-probe suite,
	 * which defines the constant before WordPress loads; a constant cannot be
	 * undefined once set, so it cannot be exercised in the same process as the
	 * tests that expect an apply to commit.
	 *
	 * @return void
	 */
	public function test_the_forced_failure_constant_has_the_documented_name(): void {
		$this->assertSame( 'WPDEBLOAT_TEST_FAIL_PROBE', Verifier::TEST_FAIL_CONSTANT );
	}

	/**
	 * The states a run passed through.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,string>
	 */
	private function historyOf( int $run_id ): array {
		$run     = $this->plugin->runs()->find( $run_id );
		$history = $run->payload['history'] ?? array();

		return is_array( $history ) ? array_values( array_filter( $history, 'is_string' ) ) : array();
	}

	/**
	 * One probe's result, by name.
	 *
	 * @param \WPDebloat\Contracts\VerificationResult $result The verification.
	 * @param string                                  $name   Probe name.
	 * @return \WPDebloat\Contracts\ProbeResult
	 */
	private function probe( $result, string $name ) {
		foreach ( $result->probes as $probe ) {
			if ( $name === $probe->probe ) {
				return $probe;
			}
		}

		$this->fail( sprintf( 'The verification did not include the "%s" probe.', $name ) );
	}

	/**
	 * Answer every verification request as a working site would, with the given
	 * exceptions.
	 *
	 * @param array<string,array{status:int,body:string}> $overrides URL to response.
	 * @return void
	 */
	private function serveHealthySite( array $overrides = array() ): void {
		$plugin = $this->plugin;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $overrides, $plugin ) {
				unset( $preempt, $args );

				foreach ( $overrides as $match => $response ) {
					// Exact match: home_url() is a prefix of every other URL, so
					// a prefix match would let one override answer everything.
					if ( untrailingslashit( $url ) === untrailingslashit( $match ) ) {
						return array(
							'headers'  => array(),
							'body'     => $response['body'],
							'response' => array(
								'code'    => $response['status'],
								'message' => '',
							),
							'cookies'  => array(),
							'filename' => null,
						);
					}
				}

				return array(
					'headers'  => array(),
					'body'     => self::bodyFor( $url, $plugin ),
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
	 * A healthy response body for a given URL.
	 *
	 * @param string                $url    Requested URL.
	 * @param \WPDebloat\Plugin     $plugin The plugin, for the runtime hash.
	 * @return string
	 */
	private static function bodyFor( string $url, $plugin ): string {
		if ( 0 === strpos( $url, rest_url( 'wpdebloat/v1/status' ) ) ) {
			return (string) wp_json_encode(
				array(
					'runtime' => array( 'hash' => $plugin->state()->runtimeHash() ),
					'loader'  => array( 'mode' => RuntimeLoader::MODE_MU_PLUGIN ),
				)
			);
		}

		if ( 0 === strpos( $url, rest_url() ) ) {
			return (string) wp_json_encode( array( 'name' => 'A site' ) );
		}

		if ( 0 === strpos( $url, wp_login_url() ) ) {
			return self::GOOD_LOGIN;
		}

		if ( 0 === strpos( $url, admin_url() ) ) {
			return self::GOOD_ADMIN;
		}

		return self::GOOD_HTML;
	}

	/**
	 * Make every outbound request fail the way a blocked loopback does.
	 *
	 * @return void
	 */
	private function blockLoopback(): void {
		add_filter(
			'pre_http_request',
			static fn (): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ),
			10,
			3
		);
	}

	/**
	 * Build a plan from tweak ids.
	 *
	 * @param array<int,string> $tweak_ids Tweak ids.
	 * @return PreviewPlan
	 */
	private function planOf( array $tweak_ids ): PreviewPlan {
		$tweaks = array();

		foreach ( $tweak_ids as $tweak_id ) {
			$tweaks[] = $this->plugin->registry()->tweak( $tweak_id )->resolve();
		}

		return new PreviewPlan( $tweaks );
	}
}
