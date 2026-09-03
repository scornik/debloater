<?php
/**
 * The other half of the MVP acceptance test.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\FailProbe;

use WP_REST_Request;
use Debloater\Apply\Lock;
use Debloater\Brand;
use Debloater\Contracts\RunState;

/**
 * BUILD-SPEC §14: "a forced probe failure triggers automatic rollback and
 * restores the prior selection and runtime hash exactly".
 *
 * Driven through the REST API rather than the plugin's PHP, because that is the
 * path the dashboard takes and the one a user's click actually follows. The
 * assertions compare the runtime bytes and the stored selection, not a status.
 */
final class AcceptanceRollbackTest extends FailProbeTestCase {

	/**
	 * Prepare the REST server and act as an administrator.
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
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		( new Lock() )->forceRelease();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Fix Safe Issues on a site that fails its checks: applied, verified,
	 * failed, rolled back, and everything exactly as it was.
	 *
	 * @return void
	 */
	public function test_fix_safe_issues_rolls_back_and_restores_exactly(): void {
		// A site that already has something applied, so the rollback has
		// something to restore rather than merely something to delete.
		$hash_before      = $this->selectAndGenerate( array( 'core.remove_jquery_migrate' => array() ) );
		$selection_before = $this->plugin->state()->selection();
		$runtime_before   = (string) file_get_contents( $this->context()->runtimeFile() );

		$this->plugin->scan();

		$preview = $this->rest( 'GET', '/preview', array( 'profile' => 'safe' ) );

		$this->assertSame( 200, $preview->get_status() );
		$this->assertGreaterThan( 0, $preview->get_data()['count'] );

		$applied = $this->rest(
			'POST',
			'/apply',
			array(
				'profile' => 'safe',
				'confirm' => $preview->get_data()['confirm'],
			)
		);

		$this->assertSame( 200, $applied->get_status() );
		$this->assertFalse( $applied->get_data()['ok'] );
		$this->assertSame( RunState::ROLLED_BACK->value, $applied->get_data()['state'] );

		$run_id = $applied->get_data()['run_id'];
		$detail = $this->rest( 'GET', '/runs/' . $run_id );
		$data   = $detail->get_data();

		$this->assertSame( RunState::ROLLED_BACK->value, $data['status'] );
		$this->assertTrue( $data['finished'] );
		$this->assertSame( 'Rollback complete', $data['label'] );
		$this->assertContains( RunState::VERIFICATION_FAILED->value, $data['history'] );
		$this->assertContains( RunState::ROLLING_BACK->value, $data['history'] );
		$this->assertNotContains( RunState::COMMITTED->value, $data['history'] );

		$this->assertSame( 'FAIL', $data['result']['verification']['status'] );

		$failed = array();

		foreach ( $data['result']['verification']['probes'] as $probe ) {
			if ( 'FAIL' === $probe['status'] ) {
				$failed[] = $probe['probe'];
			}
		}

		$this->assertSame( array( 'rest' ), $failed, 'The report must name the check that failed.' );

		// Exactly as it was.
		$this->assertSame(
			$runtime_before,
			(string) file_get_contents( $this->context()->runtimeFile() ),
			'The runtime must be byte-identical to what was there before.'
		);

		$this->assertSame( $hash_before, $this->plugin->state()->runtimeHash() );
		$this->assertSame( $selection_before, $this->plugin->state()->selection() );

		$this->assertNull( ( new Lock() )->heldBy(), 'A rolled-back run must release the lock.' );
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
}
