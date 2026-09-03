<?php
/**
 * The MVP acceptance test.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Apply\Lock;
use Debloater\Apply\RuntimeLoader;
use Debloater\Brand;
use Debloater\Contracts\RunState;
use Debloater\Contracts\SnapshotLevel;
use WP_REST_Request;

/**
 * BUILD-SPEC §14, the Phase 9 exit criterion.
 *
 * The whole promise of the product, end to end, on a site seeded to look like a
 * real one: scan and find things, apply the safe ones behind a confirmation,
 * take a recovery point first, verify afterwards, and report what changed in
 * counts.
 *
 * The other half — that a failed verification undoes everything exactly — lives
 * in the forced-failure suite, because the constant that produces the failure
 * cannot be undefined once set.
 */
final class AcceptanceTest extends IntegrationTestCase {

	/**
	 * A page that renders, with a script and a stylesheet to count.
	 */
	private const PAGE = '<!DOCTYPE html><html><head><title>A site</title>'
		. '<link rel="stylesheet" href="https://example.test/style.css">'
		. '<script src="https://example.test/a.js"></script>'
		. '</head><body>Hello<div id="adminmenu"></div><div id="wpbody"></div></body></html>';

	/**
	 * Prepare the tables, seed the site, and act as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->plugin->resetServices();

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );

		$this->seed();
		$this->serveHealthySite();
	}

	/**
	 * Clean up.
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
	 * A scan of a seeded site reports plenty, including something it refuses to
	 * touch.
	 *
	 * @return void
	 */
	public function test_a_scan_reports_findings_including_something_to_leave_alone(): void {
		$run      = $this->plugin->scan();
		$findings = $this->plugin->findingsOf( $run );

		$this->assertGreaterThanOrEqual(
			12,
			count( $findings ),
			'§14 expects at least twelve findings on a seeded site.'
		);

		$leave_alone = array_filter(
			$findings,
			static fn ( $finding ): bool => 'dont_touch' === $finding->decision->value
		);

		$this->assertGreaterThanOrEqual(
			1,
			count( $leave_alone ),
			'§14 expects at least one finding the plugin refuses to act on.'
		);

		foreach ( $findings as $finding ) {
			$this->assertNotSame( '', $finding->why, $finding->id . ' must say why it matters.' );
			$this->assertNotSame( array(), $finding->evidence, $finding->id . ' must carry its evidence.' );
		}
	}

	/**
	 * Fix Safe Issues: preview, confirm, snapshot, apply, verify, report.
	 *
	 * @return void
	 */
	public function test_fix_safe_issues_end_to_end(): void {
		$this->plugin->scan();

		$preview = $this->rest( 'GET', '/preview', array( 'profile' => 'safe' ) );

		$this->assertSame( 200, $preview->get_status() );

		$plan = $preview->get_data();

		$this->assertGreaterThan( 0, $plan['count'], 'The safe plan should propose something.' );
		$this->assertFalse( $plan['destructive'], 'The safe plan never deletes anything.' );
		$this->assertContains( SnapshotLevel::A->value, $plan['plan']['snapshot_levels'] );
		$this->assertNotSame( array(), $plan['plan']['will_change'] );
		$this->assertNotSame( array(), $plan['plan']['will_not'] );

		$applied = $this->rest(
			'POST',
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $plan['confirm'],
			)
		);

		$this->assertSame( 200, $applied->get_status() );
		$this->assertTrue(
			$applied->get_data()['ok'],
			(string) ( $applied->get_data()['result']['error'] ?? '' )
		);

		$run_id = $applied->get_data()['run_id'];

		// A recovery point was taken, and it was taken before the change.
		$snapshots = $this->plugin->snapshots()->forRun( $run_id );

		$this->assertNotSame( array(), $snapshots );
		$this->assertSame( SnapshotLevel::A, $snapshots[0]->level );

		// The change is in place.
		$this->assertFileExists( $this->context()->runtimeFile() );
		$this->assertNotSame( array(), $this->plugin->state()->selection() );

		// The run reads back with its verification and its measurements.
		$detail = $this->rest( 'GET', '/runs/' . $run_id );

		$this->assertSame( 200, $detail->get_status() );

		$data = $detail->get_data();

		$this->assertSame( RunState::COMMITTED->value, $data['status'] );
		$this->assertTrue( $data['finished'] );
		$this->assertSame( 'PASS', $data['result']['verification']['status'] );
		$this->assertContains( RunState::MEASURING_BEFORE->value, $data['history'] );
		$this->assertContains( RunState::MEASURING_AFTER->value, $data['history'] );
		$this->assertContains( RunState::VERIFIED->value, $data['history'] );

		$this->assertIsArray( $data['measurements'] );
		$this->assertNotSame( array(), $data['measurements']['deltas'] );

		foreach ( $data['measurements']['deltas'] as $delta ) {
			$this->assertArrayHasKey( 'unit', $delta );
			$this->assertNotSame( '', $delta['unit'], $delta['metric'] . ' must report a unit.' );
		}
	}

	/**
	 * The report never claims the site got faster.
	 *
	 * @return void
	 */
	public function test_the_report_never_claims_speed(): void {
		$this->plugin->scan();

		$preview = $this->rest( 'GET', '/preview', array( 'profile' => 'safe' ) );

		$applied = $this->rest(
			'POST',
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $preview->get_data()['confirm'],
			)
		);

		$detail = $this->rest( 'GET', '/runs/' . $applied->get_data()['run_id'] );
		$json   = strtolower( (string) wp_json_encode( $detail->get_data() ) );

		foreach ( array( 'faster', 'speed up', 'load time', 'seconds saved', 'ms faster' ) as $claim ) {
			$this->assertStringNotContainsString(
				$claim,
				$json,
				sprintf( '§12: the report must never say "%s".', $claim )
			);
		}
	}

	/**
	 * Applying twice in a row does nothing the second time, because there is
	 * nothing left to apply.
	 *
	 * @return void
	 */
	public function test_applying_again_has_nothing_left_to_do(): void {
		$this->plugin->scan();

		$preview = $this->rest( 'GET', '/preview', array( 'profile' => 'safe' ) );

		$this->rest(
			'POST',
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $preview->get_data()['confirm'],
			)
		);

		$this->plugin->scan();

		$second = $this->rest( 'GET', '/preview', array( 'profile' => 'safe' ) );

		$this->assertLessThanOrEqual(
			$preview->get_data()['count'],
			$second->get_data()['count'],
			'A second preview should not propose more than the first did.'
		);
	}

	/**
	 * Give the site something to find: revisions, expired transients, cron
	 * events and autoloaded data.
	 *
	 * @return void
	 */
	private function seed(): void {
		// The site §14 describes: a store, with people working on it. Both facts
		// are seeded the way a real site states them — WooCommerce in
		// active_plugins, and two authors who have edited content this week —
		// because together they are what makes slowing Heartbeat the wrong
		// change *here*, which is the refusal the acceptance test looks for.
		update_option(
			'active_plugins',
			array_values(
				array_unique(
					array_merge(
						(array) get_option( 'active_plugins', array() ),
						array( 'woocommerce/woocommerce.php', 'contact-form-7/wp-contact-form-7.php' )
					)
				)
			)
		);

		foreach ( array( 'editor', 'author' ) as $index => $role ) {
			self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_author'   => self::factory()->user->create( array( 'role' => $role ) ),
					'post_title'    => 'Edited this week ' . $index,
					'post_modified' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				)
			);
		}

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'A seeded post',
				'post_content' => 'The first version.',
			)
		);

		for ( $index = 0; $index < 8; $index++ ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => 'Version ' . $index,
				)
			);
		}

		for ( $index = 0; $index < 12; $index++ ) {
			set_transient( 'wpd_seed_' . $index, str_repeat( 'x', 256 ), 60 );
			update_option( '_transient_timeout_wpd_seed_' . $index, time() - 3600 );
		}

		for ( $index = 0; $index < 6; $index++ ) {
			update_option( 'wpd_seed_autoload_' . $index, str_repeat( 'y', 4096 ), 'yes' );
		}

		for ( $index = 0; $index < 4; $index++ ) {
			wp_schedule_single_event( time() + 3600 + $index, 'wpd_seed_event_' . $index );
		}
	}

	/**
	 * Dispatch a REST request as the current user.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Route path.
	 * @param array<string,mixed> $params Parameters.
	 * @return \WP_REST_Response
	 */
	private function rest( string $method, string $path, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Brand::REST_NAMESPACE . $path );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

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

	/**
	 * Answer every verification and measurement request as a working site.
	 *
	 * @return void
	 */
	private function serveHealthySite(): void {
		$plugin = $this->plugin;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $plugin ) {
				unset( $preempt, $args );

				$body = self::PAGE;

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
					$body = '<html><head><title>Log In</title></head><body>'
						. '<form id="loginform"></form></body></html>';
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
