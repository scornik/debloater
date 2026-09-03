<?php
/**
 * One test per security rule in BUILD-SPEC §13.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Brand;
use Debloater\Security\Capabilities;
use Debloater\Update\SignatureVerifier;

/**
 * BUILD-SPEC §13, §17 Phase 18.
 *
 * §13 is fifteen numbered rules, and most of them already have a test
 * somewhere in this suite — which is where those assertions belong, next to
 * the thing they are about.
 *
 * This file exists anyway, for a reason worth stating: a rule with no test
 * named after it is a rule whose status nobody can look up. When somebody asks
 * "is rule 6 still true", the answer should be a test name rather than an
 * afternoon of reading. So there is one test per rule here, each named after
 * its number, and each either asserting the rule directly or asserting the
 * mechanism that enforces it.
 */
final class SecurityRulesTest extends IntegrationTestCase {

	/**
	 * Routes that change the site.
	 */
	private const WRITE_ROUTES = array( '/scan', '/apply', '/rollback' );

	/**
	 * Routes that only read.
	 */
	private const READ_ROUTES = array( '/status', '/findings', '/preview', '/snapshots' );

	/**
	 * Prepare the REST server and the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->plugin->schema()->ensure();

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );
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
	 * Rule 1 — every route checks the capability, and the capability is mapped
	 * rather than merely named.
	 *
	 * @return void
	 */
	public function test_rule_1_every_route_checks_the_capability(): void {
		$this->assertSame( 'debloater_manage', Brand::CAPABILITY );

		// A subscriber gets nowhere, reading or writing.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( Capabilities::currentUserCanManage() );

		foreach ( array_merge( self::READ_ROUTES, self::WRITE_ROUTES ) as $route ) {
			$method   = in_array( $route, self::WRITE_ROUTES, true ) ? 'POST' : 'GET';
			$response = $this->dispatch( $method, $route, true, $this->wellFormed( $route ) );

			$this->assertContains(
				$response->get_status(),
				array( 401, 403 ),
				sprintf( '§13 rule 1: %s %s must refuse a subscriber.', $method, $route )
			);
		}

		// An administrator gets through, which is what makes the check a check
		// rather than a wall.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( Capabilities::currentUserCanManage() );

		$response = $this->dispatch( 'GET', '/status', true );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Rule 2 — a state-changing request without a nonce is refused.
	 *
	 * @return void
	 */
	public function test_rule_2_write_routes_require_a_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( self::WRITE_ROUTES as $route ) {
			$response = $this->dispatch( 'POST', $route, false, $this->wellFormed( $route ) );

			$this->assertContains(
				$response->get_status(),
				array( 401, 403 ),
				sprintf( '§13 rule 2: POST %s must refuse a request carrying no nonce.', $route )
			);
		}

		// The read routes are unaffected, so the nonce requirement lands on the
		// requests that change something rather than on everything.
		$response = $this->dispatch( 'GET', '/status', false );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Rule 3 — inbound values are validated, and an unknown key is rejected
	 * rather than ignored.
	 *
	 * @return void
	 */
	public function test_rule_3_input_outside_the_schema_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A value outside the declared enum.
		$response = $this->dispatch( 'GET', '/preview', true, array( 'profile' => 'not-a-profile' ) );

		$this->assertSame(
			400,
			$response->get_status(),
			'§13 rule 3: a value outside the schema must be refused, not coerced.'
		);

		// A value of the wrong type.
		$response = $this->dispatch( 'GET', '/preview', true, array( 'run_id' => 'not-a-number' ) );

		$this->assertSame(
			400,
			$response->get_status(),
			'§13 rule 3: a parameter of the wrong type must be refused.'
		);

		// And an unknown key in a validated document is a failure rather than
		// something quietly dropped.
		$errors = $this->plugin->configSchema()->validate(
			array(
				'schema_version' => 1,
				'not_a_real_key' => true,
			)
		);

		$this->assertNotEmpty(
			$errors,
			'§13 rule 3: an unknown key must be rejected.'
		);
	}

	/**
	 * Rule 4 — output is escaped at the edge.
	 *
	 * Asserted against the admin screen, the only place this plugin prints HTML
	 * at all.
	 *
	 * @return void
	 */
	public function test_rule_4_the_admin_screen_escapes_what_it_prints(): void {
		$screen = $this->source( 'src/Admin/Screen.php' );

		$this->assertStringContainsString( 'esc_html', $screen );
		$this->assertStringContainsString( 'wp_json_encode', $screen );

		$this->assertSame(
			0,
			preg_match( '/\becho\s+\$[a-z_]/i', $screen ),
			'§13 rule 4: nothing may be echoed straight out of a variable.'
		);
	}

	/**
	 * Rule 5 — generated code requires only registry-declared handler paths,
	 * resolved inside the plugin directory.
	 *
	 * @return void
	 */
	public function test_rule_5_generated_code_only_names_declared_handlers(): void {
		$declared = array();

		foreach ( $this->plugin->registry()->all() as $definition ) {
			$declared[] = str_replace( '\\', '/', $definition->handler );
		}

		$tweaks = array(
			'core.remove_generator' => array(),
			'core.disable_emojis'   => array(),
		);

		$this->selectAndGenerate( $tweaks );

		$runtime = (string) file_get_contents( $this->context()->runtimeFile() );
		$root    = str_replace( '\\', '/', (string) realpath( DEBLOATER_TESTS_ROOT ) );

		$this->assertStringContainsString( 'require_once', $runtime );

		$found = preg_match_all( "/require(?:_once)?\s+'([^']+)'/", $runtime, $matches );

		$this->assertGreaterThan( 0, $found, '§13 rule 5: the runtime requires nothing at all.' );

		foreach ( $matches[1] as $path ) {
			$real = realpath( $path );

			$this->assertNotFalse( $real, '§13 rule 5: required path does not exist — ' . $path );

			$real = str_replace( '\\', '/', (string) $real );

			$this->assertStringStartsWith(
				$root . '/',
				$real,
				'§13 rule 5: generated code may only require files inside the plugin.'
			);

			$relative = substr( $real, strlen( $root ) + 1 );

			// The guard is the runtime's own preamble rather than any tweak's
			// handler, so the compiler declares it instead of the registry.
			// Everything else must be a handler somebody declared.
			if ( 'runtime-handlers/runtime-guard.php' === $relative ) {
				continue;
			}

			$this->assertContains(
				$relative,
				$declared,
				'§13 rule 5: ' . $relative . ' is required but no tweak declares it.'
			);
		}

		$this->unregisterHandlers( array_keys( $tweaks ) );
	}

	/**
	 * Rule 6 — writes happen only under `wp-content/debloater/` and
	 * `mu-plugins/`, and no write path comes from a request.
	 *
	 * @return void
	 */
	public function test_rule_6_writes_stay_inside_two_directories(): void {
		$files = array(
			'src/Apply/RuntimeWriter.php',
			'src/Apply/RuntimeLoader.php',
			'src/Snapshot/SpillFile.php',
		);

		foreach ( $files as $relative ) {
			$source = $this->source( $relative );

			$this->assertSame(
				0,
				preg_match( '/(file_put_contents|fopen|rename|unlink)\(\s*\$_(GET|POST|REQUEST)/', $source ),
				'§13 rule 6: no write path may come from request input — ' . $relative
			);
		}

		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$allowed = array(
			str_replace( '\\', '/', WP_CONTENT_DIR . '/debloater/' ),
			str_replace( '\\', '/', WP_CONTENT_DIR . '/mu-plugins/' ),
		);

		$written = str_replace( '\\', '/', $this->context()->runtimeFile() );

		$inside = false;

		foreach ( $allowed as $directory ) {
			if ( 0 === strpos( $written, $directory ) ) {
				$inside = true;
			}
		}

		$this->assertTrue( $inside, '§13 rule 6: the runtime was written to ' . $written );

		// Readable by the web server, writable by nobody who could not already
		// write it.
		$mode = fileperms( $this->context()->runtimeFile() ) & 0777;

		$this->assertSame(
			0,
			$mode & 0022,
			sprintf( '§13 rule 6: runtime.php is group- or world-writable (%o).', $mode )
		);

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * Rule 7 — a snapshot is checksum-verified before restore, a corrupt one is
	 * refused, and the site hash must match.
	 *
	 * @return void
	 */
	public function test_rule_7_snapshots_are_verified_before_restore(): void {
		$source = $this->source( 'src/Snapshot/RollbackManager.php' );

		// Each of these three refusals has its own behavioural test in
		// ApplyRollbackTest. What this asserts is that all three still exist to
		// be tested, so removing one cannot pass quietly.
		foreach ( array( 'site_hash', 'CORRUPT', 'verify' ) as $guard ) {
			$this->assertStringContainsString(
				$guard,
				$source,
				'§13 rule 7: the ' . $guard . ' refusal must still exist.'
			);
		}

		$this->assertTrue( method_exists( $this->plugin->snapshotManager(), 'verify' ) );
		$this->assertTrue( method_exists( $this->plugin->snapshotManager(), 'isRestorable' ) );
	}

	/**
	 * Rule 8 — applying needs an explicit confirmation token issued for that
	 * exact plan.
	 *
	 * @return void
	 */
	public function test_rule_8_applying_needs_an_explicit_confirmation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The capability and the nonce are both correct, and it is still
		// refused: the confirmation is a third, separate thing.
		$response = $this->dispatch( 'POST', '/apply', true, array( 'profile' => 'safe' ) );

		$this->assertSame(
			400,
			$response->get_status(),
			'§13 rule 8: an apply with no confirmation token must be refused.'
		);

		$response = $this->dispatch(
			'POST',
			'/apply',
			true,
			array(
				'profile' => 'safe',
				'confirm' => str_repeat( 'a', 64 ),
			)
		);

		$this->assertNotSame(
			200,
			$response->get_status(),
			'§13 rule 8: a token not issued for this plan must be refused.'
		);
	}

	/**
	 * Rule 9 — no outbound HTTP except loopback, and both remote lookups are
	 * opt-in.
	 *
	 * @return void
	 */
	public function test_rule_9_nothing_leaves_the_server_by_default(): void {
		$requests = array();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requests ) {
				unset( $preempt, $args );

				$requests[] = (string) $url;

				return new \WP_Error( 'debloater_test_offline', 'No network in this test.' );
			},
			1,
			3
		);

		$this->plugin->scan();

		remove_all_filters( 'pre_http_request' );

		$home = untrailingslashit( home_url() );

		foreach ( $requests as $url ) {
			$this->assertStringStartsWith(
				$home,
				$url,
				'§13 rule 9: a scan may read this site and nothing else — it asked for ' . $url
			);
		}

		$this->assertFalse(
			$this->plugin->wpOrgUpdates()->enabled(),
			'§13 rule 9: the wordpress.org check is opt-in.'
		);
		$this->assertFalse(
			$this->plugin->registryUpdater()->enabled(),
			'§13 rule 9: the registry fetch is opt-in.'
		);
	}

	/**
	 * Rule 10 — uninstall always removes the runtime and the loader, and drops
	 * data only on opt-in.
	 *
	 * @return void
	 */
	public function test_rule_10_uninstall_always_removes_the_runtime(): void {
		$uninstall = $this->source( 'uninstall.php' );

		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', $uninstall );
		$this->assertStringContainsString( 'runtime.php', $uninstall );
		$this->assertStringContainsString( 'debloater-loader.php', $uninstall );
		$this->assertStringContainsString( 'uninstall_cleanup', $uninstall );

		// The two halves are separate functions, called unconditionally in that
		// order, so the always-half cannot be made conditional by accident.
		$this->assertStringContainsString( 'function debloater_uninstall_runtime', $uninstall );
		$this->assertStringContainsString( 'function debloater_uninstall_data', $uninstall );

		$always = strpos( $uninstall, 'debloater_uninstall_runtime();' );
		$maybe  = strpos( $uninstall, 'debloater_uninstall_data();' );

		$this->assertNotFalse( $always, '§13 rule 10: the runtime removal must actually be called.' );
		$this->assertNotFalse( $maybe, '§13 rule 10: the data cleanup must actually be called.' );
		$this->assertLessThan(
			$maybe,
			$always,
			'§13 rule 10: the unconditional half must run first.'
		);

		// And the opt-in is off by default, so a plain uninstall keeps the
		// recovery points.
		$this->assertFalse( $this->plugin->state()->uninstallCleanup() );
	}

	/**
	 * Rule 11 — the kill-switch bypass is read-only and never logs request
	 * contents.
	 *
	 * @return void
	 */
	public function test_rule_11_the_kill_switch_is_read_only(): void {
		$guard = $this->source( 'runtime-handlers/runtime-guard.php' );

		$this->assertStringContainsString( 'DEBLOATER_DISABLE', $guard );

		$forbidden = array(
			'update_option',
			'add_option',
			'set_transient',
			'error_log',
			'file_put_contents',
			'$wpdb',
			'wp_remote_',
		);

		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$guard,
				'§13 rule 11: the guard must write nothing — found ' . $needle
			);
		}

		// It reads the query string, and what it keeps is one boolean. Nothing
		// from the request is retained, so nothing from the request can later
		// be written out.
		$this->assertStringContainsString( '$deferred = false', $guard );
		$this->assertSame(
			0,
			preg_match( '/=\s*\$_GET/', $guard ),
			'§13 rule 11: nothing from the request may be assigned to state.'
		);

		// Same for the mu-plugin loader, which runs on every request whether
		// the plugin is active or not.
		$loader = $this->source( 'mu-loader/debloater-loader.php' );

		foreach ( array( 'update_option', 'error_log', '$_POST', '$_REQUEST', '$wpdb' ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$loader,
				'§13 rule 11: the loader must write nothing and log no request — found ' . $needle
			);
		}
	}

	/**
	 * Rule 12 — the journal records the actor and nothing else about a person.
	 *
	 * @return void
	 */
	public function test_rule_12_the_journal_holds_no_personal_data(): void {
		$journal = $this->source( 'src/Journal/Journal.php' );

		$pii = array(
			'user_email',
			'user_login',
			'user_nicename',
			'display_name',
			'REMOTE_ADDR',
			'HTTP_USER_AGENT',
		);

		foreach ( $pii as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$journal,
				'§13 rule 12: the journal must hold no PII beyond the actor id — found ' . $needle
			);
		}

		// The actor is an id, and the only thing that produces one says so.
		$this->assertStringContainsString(
			'currentActor',
			$this->source( 'src/Security/Capabilities.php' )
		);
	}

	/**
	 * Rule 13 — licensing is provider-agnostic, and the free plugin asks nobody
	 * for permission.
	 *
	 * @return void
	 */
	public function test_rule_13_the_free_plugin_needs_no_licence(): void {
		// The suite itself is the proof of the second half: it runs green with
		// no licensing platform present at all.
		$this->assertFalse( function_exists( 'fs_dynamic_init' ) );

		$platforms = array( 'freemius', 'lemonsqueezy', 'lemon_squeezy', 'license_key', 'licence_key' );

		foreach ( $this->shippedSources() as $path => $source ) {
			$lowered = strtolower( $source );

			foreach ( $platforms as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$lowered,
					'§13 rule 13: ' . $path . ' names a licensing platform; the free plugin has none.'
				);
			}
		}

		// And the pipeline works with nothing installed beyond WordPress.
		$this->assertNotNull( $this->plugin->scan() );
		$this->assertNotNull( $this->plugin->preview( 'safe' ) );
	}

	/**
	 * Rule 14 — the cloud is optional, and nothing shipped names it.
	 *
	 * @return void
	 */
	public function test_rule_14_the_cloud_is_optional(): void {
		$hosts = array(
			'cloud.hakeemify.com',
			'license.hakeemify.com',
			'api.hakeemify.com',
			'app.hakeemify.com',
			'registry.hakeemify.com',
		);

		foreach ( $this->shippedSources() as $path => $source ) {
			foreach ( $hosts as $host ) {
				$this->assertStringNotContainsString(
					$host,
					$source,
					'§13 rule 14: ' . $path . ' must not name ' . $host . '.'
				);
			}
		}
	}

	/**
	 * Rule 15 — nothing secret ships, and the one embeddable key is public.
	 *
	 * @return void
	 */
	public function test_rule_15_nothing_secret_ships(): void {
		foreach ( $this->shippedSources() as $path => $source ) {
			$this->assertSame(
				0,
				preg_match( '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $source ),
				'§13 rule 15: ' . $path . ' looks like it holds a private key.'
			);

			$this->assertSame(
				0,
				preg_match( '/\b(sk_live|sk_test|pk_live|ghp_)[A-Za-z0-9]{8}/', $source ),
				'§13 rule 15: ' . $path . ' looks like it holds an API secret.'
			);
		}

		// A public verification key may ship. It is empty until a release is
		// signed, and empty means the verifier fails closed rather than open.
		$this->assertSame( '', SignatureVerifier::PUBLIC_KEY_HEX );
		$this->assertFalse( ( new SignatureVerifier() )->isAvailable() );
	}

	/**
	 * A body that satisfies a route's schema without being a real request.
	 *
	 * Needed because WordPress validates required parameters *before* it calls
	 * the permission callback. An apply with no confirmation token therefore
	 * answers 400 whoever asks, which tells you nothing about whether the
	 * capability check works — the request never reached it. Filling in the
	 * shape leaves authorisation as the only thing wrong, which is the thing
	 * these two tests are about.
	 *
	 * The token is deliberately not a valid one. It has the right length and
	 * nothing else, so a route that got as far as checking it would refuse it
	 * anyway.
	 *
	 * @param string $route Route path.
	 * @return array<string,mixed>
	 */
	private function wellFormed( string $route ): array {
		if ( in_array( $route, array( '/apply', '/rollback' ), true ) ) {
			return array( 'confirm' => str_repeat( 'a', 64 ) );
		}

		return array();
	}

	/**
	 * Read a file that ships, by path relative to the plugin root.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function source( string $relative ): string {
		$path = DEBLOATER_TESTS_ROOT . '/' . $relative;

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Every PHP file that ships, keyed by relative path.
	 *
	 * @return array<string,string>
	 */
	private function shippedSources(): array {
		$sources = array();
		$root    = str_replace( '\\', '/', (string) realpath( DEBLOATER_TESTS_ROOT ) );

		foreach ( array( 'src', 'runtime-handlers', 'mu-loader' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					DEBLOATER_TESTS_ROOT . '/' . $directory,
					\FilesystemIterator::SKIP_DOTS
				)
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = substr(
					str_replace( '\\', '/', $file->getPathname() ),
					strlen( $root ) + 1
				);

				$sources[ $relative ] = (string) file_get_contents( $file->getPathname() );
			}
		}

		foreach ( array( 'uninstall.php', 'debloater.php' ) as $relative ) {
			$sources[ $relative ] = (string) file_get_contents( DEBLOATER_TESTS_ROOT . '/' . $relative );
		}

		return $sources;
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Route path.
	 * @param bool                $nonce  Whether to send a valid nonce.
	 * @param array<string,mixed> $params Parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $method, string $path, bool $nonce, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Brand::REST_NAMESPACE . $path );

		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		if ( 'GET' === $method ) {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
