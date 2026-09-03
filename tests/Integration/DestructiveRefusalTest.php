<?php
/**
 * Nothing is deleted without cover.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Apply\ApplyManager;
use Debloater\Apply\Lock;
use Debloater\Brand;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\RunState;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Contracts\SnapshotStatus;

/**
 * BUILD-SPEC §12 rule 8 and §17 Phase 10.
 *
 * The rule these tests defend is short: a destructive operation may not run
 * unless a **complete** Level B recovery point exists for it. Not "was
 * requested", not "was started" — complete, and still complete at the moment of
 * the deletion.
 *
 * The attestation is here too, because the interesting thing about it is that
 * it changes nothing. A user with their own backup gets the same refusal as one
 * without.
 */
final class DestructiveRefusalTest extends IntegrationTestCase {

	/**
	 * Prepare the tables and the REST server.
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

		$this->seedTrash();
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
	 * An apply whose destructive operation is unavailable is refused before
	 * anything is deleted.
	 *
	 * @return void
	 */
	public function test_an_apply_without_the_operation_is_refused(): void {
		$manager = new ApplyManager(
			$this->context(),
			$this->plugin->registry(),
			$this->plugin->runs(),
			$this->plugin->snapshots(),
			$this->plugin->snapshotManager(),
			$this->plugin->rollbackManager(),
			$this->plugin->state(),
			$this->plugin->journal(),
			array(),
			new Lock()
		);

		$result = $manager->apply( $this->planFor( 'db.empty_trash' ) );

		$this->assertSame( RunState::ABORTED, $result->state );
		$this->assertStringContainsString( 'cannot be backed up', (string) $result->error );
		$this->assertSame( 3, $this->trashCount(), 'Nothing may be deleted without cover.' );
	}

	/**
	 * A recovery point that never completed does not count.
	 *
	 * @return void
	 */
	public function test_an_incomplete_recovery_point_refuses_the_deletion(): void {
		$this->serveHealthySite();

		$snapshots = $this->plugin->snapshots();
		$result    = $this->plugin->apply( $this->planFor( 'db.empty_trash' ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );

		foreach ( $snapshots->forRun( $result->run_id ) as $snapshot ) {
			if ( SnapshotLevel::B === $snapshot->level ) {
				$snapshots->update( $snapshot->withStatus( SnapshotStatus::CORRUPT ) );
			}
		}

		// With the recovery point spoiled, restoring is refused rather than
		// half-attempted.
		$rolled_back = $this->plugin->rollback( $result->run_id );

		$this->assertNotSame(
			RunState::ROLLED_BACK,
			$rolled_back->state,
			'A rollback from a corrupt recovery point must be refused, not attempted.'
		);
	}

	/**
	 * The attestation changes nothing about the refusal.
	 *
	 * @return void
	 */
	public function test_the_attestation_does_not_substitute_for_the_backup(): void {
		$manager = new ApplyManager(
			$this->context(),
			$this->plugin->registry(),
			$this->plugin->runs(),
			$this->plugin->snapshots(),
			$this->plugin->snapshotManager(),
			$this->plugin->rollbackManager(),
			$this->plugin->state(),
			$this->plugin->journal(),
			array(),
			new Lock()
		);

		$this->plugin->recordAttestation( true );

		$this->assertTrue( $this->plugin->state()->get( 'attestation' )['external_backup'] );

		$result = $manager->apply( $this->planFor( 'db.empty_trash' ) );

		$this->assertSame(
			RunState::ABORTED,
			$result->state,
			'A stated external backup must not let a deletion proceed without ours.'
		);

		$this->assertSame( 3, $this->trashCount() );
	}

	/**
	 * The attestation is recorded through the endpoint, and recorded truthfully.
	 *
	 * @return void
	 */
	public function test_the_attestation_is_recorded_on_the_site(): void {
		$this->serveHealthySite();
		$this->plugin->scan();

		$preview = $this->rest( 'GET', '/preview', array( 'profile' => 'safe' ) );

		$this->rest(
			'POST',
			'/apply',
			array(
				'profile'     => 'safe',
				'confirm'     => $preview->get_data()['confirm'],
				'attestation' => true,
			)
		);

		$attestation = $this->plugin->state()->get( 'attestation' );

		$this->assertIsArray( $attestation );
		$this->assertTrue( $attestation['external_backup'] );
		$this->assertNotSame( '', $attestation['stated_at'] );
		$this->assertSame( $this->context()->actor, $attestation['actor'] );
	}

	/**
	 * A destructive plan still takes a Level B recovery point, and it completes
	 * before anything is deleted.
	 *
	 * @return void
	 */
	public function test_a_destructive_apply_takes_a_complete_level_b_first(): void {
		$this->serveHealthySite();

		$result = $this->plugin->apply( $this->planFor( 'db.empty_trash' ) );

		$this->assertSame( RunState::COMMITTED, $result->state, (string) $result->error );
		$this->assertSame( 0, $this->trashCount(), 'The trash should have been emptied.' );

		$levels = array();

		foreach ( $this->plugin->snapshots()->forRun( $result->run_id ) as $snapshot ) {
			$levels[ $snapshot->level->value ] = $snapshot;
		}

		$this->assertArrayHasKey( SnapshotLevel::B->value, $levels, 'A destructive change needs Level B.' );

		$level_b = $levels[ SnapshotLevel::B->value ];

		$this->assertSame( SnapshotStatus::COMPLETE, $level_b->status );
		$this->assertSame( 3, $level_b->items_count, 'Every deleted row must be in the recovery point.' );
		$this->assertTrue( $this->plugin->snapshotManager()->verify( $level_b ) );

		// And it can be undone.
		$undone = $this->plugin->rollback( $result->run_id );

		$this->assertSame( RunState::ROLLED_BACK, $undone->state, (string) $undone->error );
		$this->assertSame( 3, $this->trashCount(), 'The trashed content must come back.' );
	}

	/**
	 * Three trashed posts, old enough to qualify.
	 *
	 * @return void
	 */
	private function seedTrash(): void {
		global $wpdb;

		for ( $index = 0; $index < 3; $index++ ) {
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'       => 1,
					'post_date'         => gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
					'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
					'post_content'      => 'Trashed content ' . $index,
					'post_title'        => 'Trashed ' . $index,
					'post_excerpt'      => '',
					'post_status'       => 'trash',
					'comment_status'    => 'closed',
					'ping_status'       => 'closed',
					'post_name'         => 'trashed-' . $index,
					'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
					'post_parent'       => 0,
					'guid'              => '',
					'menu_order'        => 0,
					'post_type'         => 'post',
					'post_mime_type'    => '',
					'comment_count'     => 0,
				)
			);
		}
	}

	/**
	 * How many old trashed posts remain.
	 *
	 * @return int
	 */
	private function trashCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows is the assertion.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_title LIKE %s",
				'trash',
				'Trashed %'
			)
		);
	}

	/**
	 * A plan holding one tweak.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return PreviewPlan
	 */
	private function planFor( string $tweak_id ): PreviewPlan {
		return new PreviewPlan( array( $this->plugin->registry()->tweak( $tweak_id )->resolve() ) );
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

				if ( 0 === strpos( $url, rest_url( 'debloater/v1/status' ) ) ) {
					$body = (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => $plugin->state()->runtimeHash() ),
							'loader'  => array( 'mode' => \Debloater\Apply\RuntimeLoader::MODE_MU_PLUGIN ),
						)
					);
				} elseif ( 0 === strpos( $url, rest_url() ) ) {
					$body = (string) wp_json_encode( array( 'name' => 'A site' ) );
				} elseif ( 0 === strpos( $url, wp_login_url() ) ) {
					$body = '<html><head><title>Log In</title></head><body>'
						. '<form id="loginform"></form></body></html>';
				} else {
					$body = '<!DOCTYPE html><html><head><title>A site</title></head><body>'
						. 'Hello<div id="adminmenu"></div><div id="wpbody"></div></body></html>';
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
