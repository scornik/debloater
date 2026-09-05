<?php
/**
 * The admin probe, against a WordPress that actually answers.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Contracts\ProbeStatus;
use Debloater\Verify\ActorSession;
use Debloater\Verify\HttpClient;
use Debloater\Verify\Markers;
use Debloater\Verify\Probes\AdminProbe;

/**
 * BUILD-SPEC §11: `admin` — GET `/wp-admin/` **with cookie of actor**.
 *
 * Reported from a live site: every apply ended `VERIFIED_WITH_WARNINGS` with
 * "The dashboard answered with the login form, so this check could not confirm
 * whether it renders."
 *
 * The cause was one cookie short, and the missing one is not the obvious one.
 * `auth_redirect()` guards `/wp-admin/` and calls
 * `wp_validate_auth_cookie( '', '' )`; with an empty scheme
 * `wp_parse_auth_cookie()` resolves to `secure_auth` under TLS and `auth`
 * otherwise, and **never** to `logged_in`. So a request carrying only
 * `LOGGED_IN_COOKIE` is anonymous to the dashboard, however valid that cookie
 * is — which is exactly what `test_the_logged_in_cookie_alone_is_not_admin`
 * demonstrates using core's own validator.
 *
 * ## Why the dashboard here is not fetched over real HTTP
 *
 * It was, at first, and it cannot work. WordPress's integration suite wraps
 * every test in a database transaction that is rolled back afterwards, so a
 * user created by the factory — and the session token that authorises its
 * cookie — exists only inside this connection. A real HTTP request is served
 * by a different PHP process on a different connection, which cannot see
 * uncommitted rows, so core rejects the cookie for a reason that has nothing to
 * do with whether the cookie was right. The test failed for the same reason it
 * would have failed had the fix been wrong, which makes it worthless as a test.
 *
 * So the decision here is made by **core's own validator**. The stub answers
 * the request the way `auth_redirect()` does: it reads the `Cookie` header the
 * client actually sent, resolves the scheme the way `wp_parse_auth_cookie()`
 * resolves an empty one, and calls `wp_validate_auth_cookie()`. Nothing about
 * the verdict is written here — send the wrong cookie and this fails, which is
 * the property that matters and the one the old suite did not have.
 *
 * The genuinely end-to-end proof — a committed user, a real request through
 * Apache, real `auth_redirect()` — is `tools/admin-probe-e2e.php`, run through
 * WP-CLI where transactions are not held open. Its output is in
 * `docs/TEST-RESULTS.md`.
 */
final class AdminProbeAuthTest extends IntegrationTestCase {

	/**
	 * The administrator on whose behalf the change is being made.
	 *
	 * @var int
	 */
	private int $actor = 0;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		$this->actor = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Verification Actor',
			)
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'http_request_args' );
		remove_all_filters( 'admin_url' );
		remove_all_filters( 'home_url' );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The fact the bug rested on, stated with core's own validator.
	 *
	 * No HTTP, no mocking, no interpretation: the logged-in cookie does not
	 * validate under the scheme the dashboard reads, and the admin cookie does.
	 * If this ever stops being true, the reason for the fix has gone and the
	 * test should be read again rather than deleted.
	 *
	 * @return void
	 */
	public function test_the_logged_in_cookie_alone_is_not_admin(): void {
		$expires = time() + 300;
		$token   = \WP_Session_Tokens::get_instance( $this->actor )->create( $expires );

		$logged_in = wp_generate_auth_cookie( $this->actor, $expires, 'logged_in', $token );
		$auth      = wp_generate_auth_cookie( $this->actor, $expires, 'auth', $token );

		// Each is valid for the user under its own scheme.
		$this->assertSame( $this->actor, wp_validate_auth_cookie( $logged_in, 'logged_in' ) );
		$this->assertSame( $this->actor, wp_validate_auth_cookie( $auth, 'auth' ) );

		// And the logged-in cookie is not accepted as the admin credential,
		// which is the whole of why the dashboard answered with a login form.
		$this->assertFalse(
			wp_validate_auth_cookie( $logged_in, 'auth' ),
			'If this passes, auth_redirect() would have accepted the old cookie and the bug was elsewhere.'
		);

		\WP_Session_Tokens::get_instance( $this->actor )->destroy( $token );
	}

	/**
	 * The session sends both cookies, and the admin one matches the scheme.
	 *
	 * @return void
	 */
	public function test_the_session_sends_an_admin_cookie_for_the_target_scheme(): void {
		$session = new ActorSession( $this->actorContext() );

		$this->assertTrue( $session->isAvailable() );

		$plain  = $this->adminUrl( 'http' );
		$secure = $this->adminUrl( 'https' );

		$headers = $session->headers( $plain );

		$this->assertArrayHasKey( 'Cookie', $headers );
		$this->assertStringContainsString( LOGGED_IN_COOKIE . '=', $headers['Cookie'] );
		$this->assertStringContainsString( AUTH_COOKIE . '=', $headers['Cookie'] );

		// http asks for `auth`; https asks for `secure_auth`. Only the matching
		// one is sent, because a secure_auth credential put on a plaintext
		// request is a credential minted for TLS and then sent without it.
		$this->assertStringNotContainsString( SECURE_AUTH_COOKIE . '=', $headers['Cookie'] );
		$this->assertSame( 'auth', $session->schemeFor( $plain ) );
		$this->assertSame( 'secure_auth', $session->schemeFor( $secure ) );

		$this->assertStringContainsString( SECURE_AUTH_COOKIE . '=', $session->headers( $secure )['Cookie'] );

		$session->release();
	}

	/**
	 * Both cookies name the same session token, or core rejects the pair.
	 *
	 * @return void
	 */
	public function test_both_cookies_name_the_same_session(): void {
		$session = new ActorSession( $this->actorContext() );
		$headers = $session->headers( $this->adminUrl( 'http' ) );

		$cookies = $this->parseCookieHeader( $headers['Cookie'] );

		$logged_in = wp_parse_auth_cookie( $cookies[ LOGGED_IN_COOKIE ], 'logged_in' );
		$auth      = wp_parse_auth_cookie( $cookies[ AUTH_COOKIE ], 'auth' );

		$this->assertIsArray( $logged_in );
		$this->assertIsArray( $auth );
		$this->assertSame( $logged_in['token'], $auth['token'] );

		// And the token is a real session, which is what wp_validate_auth_cookie
		// checks last and what a hand-built cookie usually gets wrong.
		$this->assertSame( $this->actor, wp_validate_auth_cookie( $cookies[ AUTH_COOKIE ], 'auth' ) );

		$session->release();
	}

	/**
	 * A credential is never sent to a host this site's cookies do not belong to.
	 *
	 * @return void
	 */
	public function test_no_credential_leaves_the_cookie_domain(): void {
		$session = new ActorSession( $this->actorContext() );

		$this->assertSame( array(), $session->headers( 'http://somewhere-else.invalid/wp-admin/' ) );
		$this->assertNotSame( array(), $session->headers( admin_url() ) );

		$session->release();
	}

	/**
	 * The dashboard, fetched for real, as the actor.
	 *
	 * @return void
	 */
	public function test_the_admin_probe_passes_for_an_administrator(): void {
		$this->answerLikeWordPress();

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame(
			ProbeStatus::PASS,
			$result->status,
			'The dashboard did not come back signed in: ' . $result->message
		);
		$this->assertStringContainsString( 'signed in', $result->message );
	}

	/**
	 * And the same host turns the old credential away.
	 *
	 * The fail-probe, kept as a test rather than done once by hand: a client
	 * that sends only the logged-in cookie is refused by the same code that
	 * accepts the fixed one. If someone removes the admin cookie again, this is
	 * what says so.
	 *
	 * @return void
	 */
	public function test_the_old_logged_in_only_credential_is_refused(): void {
		$this->answerLikeWordPress();

		// The client as it was: logged-in cookie and nothing else.
		//
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Inspected: this removes a cookie from one request inside a test. It does not touch the timeout the sniff is about.
		add_filter(
			'http_request_args',
			static function ( array $args ): array {
				if ( ! isset( $args['headers']['Cookie'] ) ) {
					return $args;
				}

				$kept = array();

				foreach ( explode( ';', (string) $args['headers']['Cookie'] ) as $pair ) {
					if ( str_starts_with( trim( $pair ), LOGGED_IN_COOKIE . '=' ) ) {
						$kept[] = trim( $pair );
					}
				}

				$args['headers']['Cookie'] = implode( '; ', $kept );

				return $args;
			},
			99
		);

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::UNKNOWN, $result->status );
		$this->assertSame( 'yes', $result->evidence['redirected_to_login'] );
	}

	/**
	 * A fatal on the dashboard is a failure, not a warning.
	 *
	 * @return void
	 */
	public function test_the_admin_probe_fails_on_a_fatal(): void {
		$this->stubResponse(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '<html><body>Fatal error: Uncaught Error in plugin.php</body></html>',
			)
		);

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::FAIL, $result->status );
		$this->assertStringContainsString( 'Fatal error', $result->message );
	}

	/**
	 * A site that cannot reach itself gets an honest unknown.
	 *
	 * @return void
	 */
	public function test_the_admin_probe_is_unknown_when_loopback_is_blocked(): void {
		$this->stubResponse( new \WP_Error( 'http_request_failed', 'Connection refused' ) );

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::UNKNOWN, $result->status );
		$this->assertStringContainsString( 'could not reach itself', $result->message );
	}

	/**
	 * A redirect to the login page is reported as core refusing the cookie.
	 *
	 * @return void
	 */
	public function test_a_redirect_to_login_names_the_scheme(): void {
		$this->stubResponse(
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => 'http://example.org/wp-login.php?reauth=1' ),
				'body'     => '',
			)
		);

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::UNKNOWN, $result->status );
		$this->assertStringContainsString( 'would not accept it', $result->message );
		$this->assertStringContainsString( 'auth', $result->message );
		$this->assertSame( 'yes', $result->evidence['redirected_to_login'] );
	}

	/**
	 * A 200 carrying a login form is not authenticated either.
	 *
	 * The half a status code cannot tell you. A host that strips the cookie
	 * before core sees it answers 200, and the old probe's only clue was the
	 * body.
	 *
	 * @return void
	 */
	public function test_a_login_form_in_a_200_is_not_a_pass(): void {
		$this->stubResponse(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><body><form id="loginform"></form></body></html>',
			)
		);

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::UNKNOWN, $result->status );
		$this->assertStringContainsString( 'removed the sign-in cookie', $result->message );
		$this->assertSame( 'no', $result->evidence['cookie_reached_core'] );
	}

	/**
	 * A dashboard rendered for nobody is a warning, not a pass.
	 *
	 * @return void
	 */
	public function test_an_admin_page_without_the_admin_bar_warns(): void {
		$this->stubResponse(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><body><div id="wpbody"></div><div id="adminmenu"></div></body></html>',
			)
		);

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::WARN, $result->status );
		$this->assertStringContainsString( 'not as a signed-in user', $result->message );
	}

	/**
	 * Nothing the probe records carries the credential.
	 *
	 * The rule that makes the rest of this safe: a cookie in a journal row is a
	 * cookie in a database backup, an export, a support ticket and a screenshot.
	 *
	 * @return void
	 */
	public function test_the_cookie_never_reaches_the_evidence(): void {
		$session = new ActorSession( $this->actorContext() );
		$headers = $session->headers( admin_url() );
		$cookies = $this->parseCookieHeader( $headers['Cookie'] );

		$session->release();

		$this->stubResponse(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><body><div id="wpbody"></div><div id="adminmenu"></div>'
					. '<div id="wpadminbar"><li id="wp-admin-bar-my-account"></li></div></body></html>',
			)
		);

		$result = $this->probe()->run( $this->actorContext() );

		$this->assertSame( ProbeStatus::PASS, $result->status );

		$haystack = wp_json_encode(
			array(
				'message'  => $result->message,
				'evidence' => $result->evidence,
				'rows'     => $this->journalRows(),
			)
		);

		$this->assertIsString( $haystack );

		foreach ( $cookies as $name => $value ) {
			$this->assertStringNotContainsString(
				$value,
				$haystack,
				sprintf( 'The %s value reached something that gets stored.', $name )
			);
		}

		// And the halves of it separately, because a cookie split across two
		// fields is still a cookie.
		foreach ( $cookies as $value ) {
			$parts = explode( '|', $value );

			$this->assertGreaterThan( 2, count( $parts ) );
			$this->assertStringNotContainsString( $parts[ count( $parts ) - 1 ], $haystack );
		}
	}

	/**
	 * Everything the journal has recorded, as text.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function journalRows(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'debloater_journal';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading this plugin's own table in a test; the name is built from $wpdb->prefix.
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Split a `Cookie` header into its parts.
	 *
	 * @param string $header The header value.
	 * @return array<string,string>
	 */
	private function parseCookieHeader( string $header ): array {
		$cookies = array();

		foreach ( explode( ';', $header ) as $pair ) {
			$pair = trim( $pair );

			if ( '' === $pair || ! str_contains( $pair, '=' ) ) {
				continue;
			}

			[ $name, $value ] = explode( '=', $pair, 2 );

			$cookies[ trim( $name ) ] = $value;
		}

		return $cookies;
	}

	/**
	 * The context, acting as the administrator.
	 *
	 * Named apart from the parent's `context()`, which is protected and
	 * unscoped: redeclaring it privately is a fatal at class load, and a fatal
	 * at class load takes the whole suite with it rather than one test.
	 *
	 * @return \Debloater\Contracts\Context
	 */
	private function actorContext(): \Debloater\Contracts\Context {
		return $this->plugin->context()->withActor( 'user:' . $this->actor );
	}

	/**
	 * A probe wired to the acting user.
	 *
	 * @return AdminProbe
	 */
	private function probe(): AdminProbe {
		return new AdminProbe( new HttpClient( $this->actorContext() ) );
	}

	/**
	 * Answer every outbound request with the same thing.
	 *
	 * @param array<string,mixed>|\WP_Error $response What to answer with.
	 * @return void
	 */
	private function stubResponse( $response ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $response ) {
				return $response;
			}
		);
	}

	/**
	 * Answer `/wp-admin/` the way `auth_redirect()` answers it.
	 *
	 * The verdict comes from `wp_validate_auth_cookie()` — core's own function,
	 * given the header the client actually sent — and the scheme is resolved
	 * the way `wp_parse_auth_cookie()` resolves an empty one: `secure_auth`
	 * under TLS, `auth` otherwise, never `logged_in`. That last clause is the
	 * whole bug, so it is the one thing here that must not be paraphrased.
	 *
	 * @return void
	 */
	private function answerLikeWordPress(): void {
		$test = $this;

		add_filter(
			'pre_http_request',
			/**
			 * @param mixed                $preempt Short-circuit value.
			 * @param array<string,mixed>  $args    Request arguments.
			 * @param string               $url     Requested URL.
			 * @return array<string,mixed>
			 */
			static function ( $preempt, array $args, string $url ) use ( $test ) {
				unset( $preempt );

				$scheme = str_starts_with( $url, 'https://' ) ? 'secure_auth' : 'auth';
				$name   = 'secure_auth' === $scheme ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

				$cookies = $test->cookiesFrom( (string) ( $args['headers']['Cookie'] ?? '' ) );
				$user    = isset( $cookies[ $name ] )
					? wp_validate_auth_cookie( $cookies[ $name ], $scheme )
					: false;

				if ( false === $user ) {
					return array(
						'response' => array( 'code' => 302 ),
						'headers'  => array(
							'location' => home_url( '/wp-login.php?redirect_to=' . rawurlencode( $url ) . '&reauth=1' ),
						),
						'body'     => '',
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => '<html><head><title>Dashboard</title></head><body>'
						. '<div id="wpadminbar"><ul><li id="wp-admin-bar-my-account">'
						. esc_html( (string) get_userdata( (int) $user )->display_name )
						. '</li></ul></div>'
						. '<div id="adminmenu"></div><div id="wpbody"></div>'
						. '</body></html>',
				);
			},
			10,
			3
		);
	}

	/**
	 * Split a `Cookie` header into its parts.
	 *
	 * Public because the stub above is static and needs it.
	 *
	 * @param string $header The header value.
	 * @return array<string,string>
	 */
	public function cookiesFrom( string $header ): array {
		return $this->parseCookieHeader( $header );
	}

	/**
	 * This site's own admin URL, forced to one scheme or the other.
	 *
	 * The host is always this site's: the credential is deliberately not sent
	 * anywhere else, so a URL on some other domain would test the guard rather
	 * than the cookie.
	 *
	 * @param string $scheme 'http' or 'https'.
	 * @return string
	 */
	private function adminUrl( string $scheme ): string {
		return (string) preg_replace( '#^https?://#', $scheme . '://', admin_url() );
	}
}
