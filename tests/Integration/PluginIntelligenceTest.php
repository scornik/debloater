<?php
/**
 * Plugin intelligence against a real install, and the promise about the
 * network.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Brand;
use Debloater\Registry\SchemaValidator;
use Debloater\Scan\WpOrgUpdates;

/**
 * BUILD-SPEC §13 rule 9 and §17 Phase 11.
 *
 * The load-bearing test in this file is the one that watches HTTP requests. WP
 * Debloat's claim is that a scan reads *this site* and nothing else, and a claim
 * like that is worth exactly as much as the test that would fail if it stopped
 * being true.
 *
 * These assertions used to demand zero requests, which was true when they were
 * written and stopped being true in Phase 13: the asset scan fetches a sample of
 * this site's own pages over loopback, which §13 rule 9 has always allowed. The
 * assertions now state the promise that was actually being made — nothing leaves
 * this server — which is both the real invariant and a stricter one, since it
 * keeps holding however many loopback requests a later phase adds.
 */
final class PluginIntelligenceTest extends IntegrationTestCase {

	/**
	 * Requests the site attempted to make during the current test.
	 *
	 * @var array<int,string>
	 */
	private array $requests = array();

	/**
	 * Watch every outbound request.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requests = array();

		$this->plugin->schema()->ensure();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $args );

				$this->requests[] = (string) $url;

				return $preempt;
			},
			1,
			3
		);
	}

	/**
	 * Stop watching.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			delete_transient( 'debloater_wporg_' . md5( \Debloater\Scan\Scanners\PluginScanner::slugOf( $plugin_file ) ) );
		}

		update_option( 'active_plugins', array() );

		parent::tear_down();
	}

	/**
	 * A scan sends nothing off this server.
	 *
	 * @return void
	 */
	public function test_a_scan_sends_nothing_off_this_server(): void {
		$this->plugin->scanRunner()->collect( $this->context() );

		$this->assertNothingLeftTheSite();
	}

	/**
	 * The whole scan-and-analyze path stays on the machine too.
	 *
	 * The scan is only half of it: the analyzer runs afterwards over the same
	 * facts, and a rule that decided to look something up would be just as much
	 * a broken promise.
	 *
	 * @return void
	 */
	public function test_scanning_and_analyzing_stays_local(): void {
		$this->plugin->scan();

		$this->assertNothingLeftTheSite();
	}

	/**
	 * Every request made so far was to this site.
	 *
	 * @return void
	 */
	private function assertNothingLeftTheSite(): void {
		$home = untrailingslashit( home_url() );

		foreach ( $this->requests as $url ) {
			$this->assertStringStartsWith(
				$home,
				$url,
				'A scan reads this site. Anything else is a request nobody asked for.'
			);
		}
	}

	/**
	 * Without the opt-in, staleness is read locally and the facts say so.
	 *
	 * @return void
	 */
	public function test_without_the_opt_in_the_reading_is_local(): void {
		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts;

		$this->assertSame( 'file_mtime', $facts->value( 'plugins.update_source' ) );
		$this->assertNothingLeftTheSite();

		$meta = $facts->value( 'plugins.meta', array() );

		$this->assertIsArray( $meta );
		$this->assertNotSame( array(), $meta );

		foreach ( $meta as $plugin_file => $entry ) {
			$this->assertArrayNotHasKey(
				'last_updated',
				$entry,
				$plugin_file . ': a release date that was never looked up must be absent, not null'
			);
			$this->assertIsInt( $entry['file_mtime'], $plugin_file );
		}
	}

	/**
	 * With the opt-in, the lookup happens and the reading changes.
	 *
	 * The answer is a fixture rather than the real wordpress.org: the point of
	 * the test is that the request is made and understood, and a test that
	 * depended on somebody else's uptime would fail for reasons that have
	 * nothing to do with this code.
	 *
	 * @return void
	 */
	public function test_the_opt_in_looks_up_release_dates(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				unset( $preempt, $args );

				if ( 0 !== strpos( $url, WpOrgUpdates::ENDPOINT ) ) {
					return false;
				}

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( array( 'last_updated' => '2019-03-04 8:12pm GMT' ) ),
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

		$this->activateSomething();
		$this->plugin->wpOrgUpdates()->setEnabled( true );

		try {
			$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts;
		} finally {
			$this->plugin->wpOrgUpdates()->setEnabled( false );
		}

		$this->assertSame( 'wp_org', $facts->value( 'plugins.update_source' ) );

		$off_site = array();

		foreach ( $this->requests as $url ) {
			if ( 0 !== strpos( $url, untrailingslashit( home_url() ) ) ) {
				$off_site[] = $url;
			}
		}

		$this->assertNotSame( array(), $off_site, 'the opt-in should have asked wordpress.org something' );

		foreach ( $off_site as $url ) {
			$this->assertStringStartsWith(
				WpOrgUpdates::ENDPOINT,
				$url,
				'the only thing that may leave this server is a question about plugin information'
			);
		}

		$meta   = $facts->value( 'plugins.meta', array() );
		$active = $facts->value( 'plugins.active', array() );

		$this->assertIsArray( $meta );
		$this->assertIsArray( $active );
		$this->assertNotSame( array(), $active, 'the fixture site has active plugins' );

		foreach ( $active as $plugin_file ) {
			$this->assertSame(
				'2019-03-04',
				$meta[ $plugin_file ]['last_updated'] ?? null,
				$plugin_file . ': the release date should have been recorded as an ISO date'
			);
		}
	}

	/**
	 * Activate one plugin, so a lookup has something to look up.
	 *
	 * A fresh WordPress install has plugins on disk and none of them switched
	 * on, and a test asserting that requests were made would otherwise pass
	 * whether or not the code works, by asking about an empty list.
	 *
	 * @return void
	 */
	private function activateSomething(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_keys( get_plugins() );

		$this->assertNotSame( array(), $installed, 'the fixture install ships some plugins' );

		update_option( 'active_plugins', array( $installed[0] ) );

		$active = $this->plugin->scanRunner()->collect( $this->context() )->facts->value( 'plugins.active', array() );

		$this->assertSame( array( $installed[0] ), $active, 'the plugin should now be active' );

		$this->requests = array();
	}

	/**
	 * The opt-in does not survive the scan that asked for it.
	 *
	 * @return void
	 */
	public function test_the_opt_in_is_not_remembered(): void {
		$this->plugin->scan( true );

		$this->assertFalse(
			$this->plugin->wpOrgUpdates()->enabled(),
			'consent is for the action that made the request, not for every scan afterwards'
		);

		$this->requests = array();

		$facts = $this->plugin->scan()->facts();

		$this->assertNothingLeftTheSite();
		$this->assertSame( 'file_mtime', $facts->value( 'plugins.update_source' ) );
	}

	/**
	 * The REST route defaults to off, and its parameter is what turns it on.
	 *
	 * @return void
	 */
	public function test_the_rest_route_defaults_to_no_network(): void {
		global $wp_rest_server;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'POST', '/' . Brand::REST_NAMESPACE . '/scan' );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{}' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNothingLeftTheSite();

		wp_set_current_user( 0 );
	}

	/**
	 * The new facts are real facts: they validate, and they describe this site.
	 *
	 * @return void
	 */
	public function test_the_new_facts_validate_and_describe_the_site(): void {
		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts;

		$violations = SchemaValidator::fromFile( DEBLOATER_TESTS_ROOT . '/registry/schemas/fact.schema.json' )
			->validate( $facts->toArray() );

		$this->assertSame( array(), $violations, implode( '; ', array_map( 'strval', $violations ) ) );

		$categories = $facts->value( 'plugins.categories', array() );
		$active     = $facts->value( 'plugins.active', array() );

		$this->assertIsArray( $categories );
		$this->assertIsArray( $active );

		$slugs = array();

		foreach ( $active as $plugin_file ) {
			$slugs[] = strtok( (string) $plugin_file, '/' );
		}

		foreach ( $categories as $row ) {
			$this->assertContains(
				$row['plugin'],
				$slugs,
				'a category row must be about a plugin that is actually active here'
			);
		}

		$this->assertIsArray( $facts->value( 'plugins.host_optimizers', array() ) );
	}

	/**
	 * The environment scanner and the plugin scanner agree about the host.
	 *
	 * They read it through the same code precisely so they cannot drift, and
	 * this is the assertion that would notice if somebody re-implemented one of
	 * them.
	 *
	 * @return void
	 */
	public function test_both_readings_of_the_host_agree(): void {
		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts;

		$this->assertSame(
			\Debloater\Scan\HostVendor::identify(),
			$facts->value( 'env.host_vendor' )
		);
	}
}
