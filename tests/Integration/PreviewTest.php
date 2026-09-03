<?php
/**
 * Previewing a plan against a real site.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Brand;
use Debloater\Recommend\IntentProfile;

/**
 * BUILD-SPEC §17 Phase 4.
 *
 * The unit tests cover the invariants over generated registries. These cover
 * the parts that need a real site: that a preview is built from a *recorded*
 * scan, that it changes nothing, and that the endpoint is closed.
 */
final class PreviewTest extends IntegrationTestCase {

	/**
	 * Set up the REST server and the tables.
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
	 * Previewing before a scan is refused, rather than inventing a plan with no
	 * evidence behind it.
	 *
	 * @return void
	 */
	public function test_previewing_before_a_scan_is_refused(): void {
		$this->assertNull( $this->plugin->preview() );
	}

	/**
	 * A safe plan on a default install proposes only safe, non-destructive
	 * changes.
	 *
	 * @return void
	 */
	public function test_the_safe_plan_on_a_default_install(): void {
		$this->plugin->scan();

		$result = $this->plugin->preview();

		$this->assertNotNull( $result );
		$this->assertGreaterThan( 0, $result->count() );
		$this->assertFalse( $result->plan->destructive );

		foreach ( $result->plan->tweaks as $tweak ) {
			$this->assertTrue( $tweak->risk->isSafePlanEligible(), $tweak->id );
			$this->assertFalse( $tweak->destructive, $tweak->id );
		}
	}

	/**
	 * A preview changes nothing: no runtime file, no runs, no state.
	 *
	 * @return void
	 */
	public function test_previewing_changes_nothing(): void {
		$this->plugin->scan();

		$runs_before  = $this->plugin->runs()->count();
		$state_before = $this->plugin->state()->all();

		$this->plugin->preview();
		$this->plugin->preview( 'maximum' );

		$this->plugin->state()->flush();

		$this->assertSame( $runs_before, $this->plugin->runs()->count(), 'a preview must not create a run' );
		$this->assertSame( $state_before, $this->plugin->state()->all(), 'a preview must not change state' );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile(), 'a preview must not write a runtime' );
	}

	/**
	 * A wider profile offers at least as much as the safe one.
	 *
	 * @return void
	 */
	public function test_a_wider_profile_offers_more(): void {
		$this->plugin->scan();

		$safe    = $this->plugin->preview( 'safe' );
		$maximum = $this->plugin->preview( 'maximum' );

		$this->assertNotNull( $safe );
		$this->assertNotNull( $maximum );
		$this->assertGreaterThanOrEqual( $safe->count(), $maximum->count() );

		// The medium-risk asset tweaks are the difference.
		$this->assertFalse( $safe->includes( 'core.remove_jquery_migrate' ) );
		$this->assertTrue( $maximum->includes( 'core.remove_jquery_migrate' ) );
	}

	/**
	 * Every candidate is either in the plan or explained.
	 *
	 * @return void
	 */
	public function test_every_exclusion_is_explained(): void {
		$this->plugin->scan();

		$result = $this->plugin->preview( 'safe' );

		$this->assertNotNull( $result );

		foreach ( $result->excluded as $id => $reason ) {
			$this->assertNotSame( '', trim( $reason ), $id );
		}
	}

	/**
	 * Planning twice from the same scan gives the same plan.
	 *
	 * @return void
	 */
	public function test_planning_is_reproducible(): void {
		$run = $this->plugin->scan();

		$first  = $this->plugin->preview( 'maximum', (int) $run->id );
		$second = $this->plugin->preview( 'maximum', (int) $run->id );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertSame( $first->toArray(), $second->toArray() );
	}

	/**
	 * The stated intent is stored and read back.
	 *
	 * @return void
	 */
	public function test_intent_is_persisted(): void {
		$this->plugin->setIntentProfile( new IntentProfile( 'store', 'conservative' ) );

		$this->plugin->state()->flush();

		$intent = $this->plugin->intentProfile();

		$this->assertSame( 'store', $intent->site_type );
		$this->assertSame( 'conservative', $intent->priority );
	}

	/**
	 * The preview endpoint is closed to anyone without the capability.
	 *
	 * @return void
	 */
	public function test_preview_requires_the_capability(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/preview' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->get( '/preview' )->get_status() );
	}

	/**
	 * Previewing before a scan returns a clear error, not an empty plan.
	 *
	 * @return void
	 */
	public function test_preview_endpoint_before_a_scan(): void {
		$this->asAdministrator();

		$this->assertSame( 409, $this->get( '/preview' )->get_status() );
	}

	/**
	 * An administrator gets the plan, its exclusions, and the profile it used.
	 *
	 * @return void
	 */
	public function test_preview_endpoint_returns_the_plan(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$response = $this->get( '/preview' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 'safe', $data['profile']['id'] );
		$this->assertGreaterThan( 0, $data['count'] );
		$this->assertFalse( $data['destructive'] );
		$this->assertNotEmpty( $data['plan']['will_change'] );
		$this->assertContains( 'Nothing will be deleted.', $data['plan']['will_not'] );
	}

	/**
	 * An unknown profile is rejected before the route runs.
	 *
	 * @return void
	 */
	public function test_an_unknown_profile_is_rejected(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$this->assertSame( 400, $this->get( '/preview', array( 'profile' => 'reckless' ) )->get_status() );
	}

	/**
	 * The preview names each change in the user's terms, and never claims speed.
	 *
	 * @return void
	 */
	public function test_preview_text_is_plain_and_makes_no_speed_claim(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$plan = $this->get( '/preview', array( 'profile' => 'maximum' ) )->get_data()['plan'];

		$text = strtolower( implode( ' ', array_merge( $plan['will_change'], $plan['will_not'] ) ) );

		foreach ( array( 'faster', 'speed up', 'quicker', 'load time' ) as $claim ) {
			$this->assertStringNotContainsString( $claim, $text );
		}

		foreach ( $plan['will_change'] as $line ) {
			$this->assertGreaterThan( 20, strlen( $line ), 'each line should actually explain the change' );
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
	 * Dispatch a GET request to one of our routes.
	 *
	 * @param string              $path  Route path.
	 * @param array<string,mixed> $query Query parameters.
	 * @return \WP_REST_Response
	 */
	private function get( string $path, array $query = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . $path );

		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}
}
