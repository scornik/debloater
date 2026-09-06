<?php
/**
 * The profile routes.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Brand;
use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
use Debloater\Recommend\IntentProfile;
use WP_REST_Request;

/**
 * BUILD-SPEC §13 rules 1, 2, 3 and 8, and §17 Phase 19c.
 *
 * Three routes, and the same questions asked of each: does it check who is
 * asking, does it check the request came from this site, and — for the one that
 * reads a file somebody brought — does it change anything.
 */
final class ProfileRoutesTest extends IntegrationTestCase {

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ProfileStore::OPTION );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A site with nothing saved still answers with the built-ins.
	 *
	 * @return void
	 */
	public function test_the_list_is_never_empty(): void {
		$this->asAdministrator();

		$body = $this->get( '/profiles' )->get_data();

		$ids = array_column( $body['profiles'], 'id' );

		$this->assertContains( 'safe', $ids );
		$this->assertContains( 'performance', $ids );
		$this->assertContains( 'maximum', $ids );
		$this->assertSame( 0, $body['saved'] );
		$this->assertSame( ProfileStore::MAX, $body['max'] );
	}

	/**
	 * Each listed profile carries the exact bytes it exports as.
	 *
	 * The download is that string, saved verbatim. Re-encoding it in the
	 * browser would produce a file that differs from the one the command line
	 * writes, in whitespace and key order, and the two would drift the first
	 * time either side changed.
	 *
	 * @return void
	 */
	public function test_a_listed_profile_carries_its_own_exported_bytes(): void {
		$this->asAdministrator();

		$profile = new Profile(
			'Client baseline',
			array( 'core.remove_generator' => array() ),
			new IntentProfile( 'blog', 'balanced' ),
			$this->plugin->registry()->hash(),
			'2026-01-01T00:00:00Z'
		);

		( new ProfileStore( $this->plugin->registry() ) )->save( $profile, 'client-baseline' );

		$body = $this->get( '/profiles' )->get_data();

		$found = null;

		foreach ( $body['profiles'] as $row ) {
			if ( 'client-baseline' === $row['id'] ) {
				$found = $row;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( $profile->toJson(), $found['document'] );
	}

	/**
	 * Saving records what the site has committed, not what is ticked.
	 *
	 * @return void
	 */
	public function test_saving_stores_the_committed_selection(): void {
		$this->asAdministrator();
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$response = $this->post( '/profiles/save', array( 'name' => 'Client baseline' ) );

		$this->assertSame( 201, $response->get_status() );

		$saved = ( new ProfileStore( $this->plugin->registry() ) )->saved();

		$this->assertArrayHasKey( 'client-baseline', $saved );
		$this->assertSame(
			array( 'core.remove_generator' ),
			array_keys( $saved['client-baseline']->selection )
		);

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * A document that is not a profile is refused, with a reason.
	 *
	 * @return void
	 */
	public function test_a_document_that_is_not_a_profile_is_refused(): void {
		$this->asAdministrator();

		$notJson = $this->post( '/profiles/import', array( 'document' => 'this is not json' ) );

		$this->assertSame( 400, $notJson->get_status() );
		$this->assertSame( 'debloater_profile_unreadable', $notJson->get_data()['code'] );

		// Valid JSON, wrong shape: the schema is what says so.
		$wrongShape = $this->post(
			'/profiles/import',
			array( 'document' => (string) wp_json_encode( array( 'hello' => 'world' ) ) )
		);

		$this->assertSame( 400, $wrongShape->get_status() );
		$this->assertSame( 'debloater_profile_invalid', $wrongShape->get_data()['code'] );
	}

	/**
	 * Importing names what it skipped, warns about the registry, applies nothing.
	 *
	 * @return void
	 */
	public function test_importing_skips_unknown_changes_and_applies_nothing(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$before = count( $this->plugin->runs()->recent( 100 ) );

		$document = ( new Profile(
			'From elsewhere',
			array(
				'core.remove_generator' => array(),
				'not.a_real_change'     => array(),
			),
			new IntentProfile(),
			str_repeat( 'b', 64 )
		) )->toJson();

		$body = $this->post( '/profiles/import', array( 'document' => $document ) )->get_data();

		$this->assertSame( array( 'not.a_real_change' ), $body['skipped'] );
		$this->assertSame( array( 'core.remove_generator' ), $body['selection'] );
		$this->assertFalse( $body['registry_match'], 'a foreign registry hash must be reported' );
		$this->assertFalse( $body['applied'] );

		// §13 rule 8, at the HTTP boundary this time.
		$this->assertSame(
			$before,
			count( $this->plugin->runs()->recent( 100 ) ),
			'importing over HTTP must not run anything'
		);
		$this->assertSame( array(), $this->plugin->state()->selection() );
		$this->assertFalse( is_file( WP_CONTENT_DIR . '/debloater/runtime.php' ) );
	}

	/**
	 * Somebody without the capability gets nowhere.
	 *
	 * @return void
	 */
	public function test_a_subscriber_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( array( '/profiles', '/profiles/save', '/profiles/import' ) as $path ) {
			$response = '/profiles' === $path
				? $this->get( $path )
				: $this->post( $path, array( 'name' => 'x', 'document' => '{}' ) );

			$this->assertContains(
				$response->get_status(),
				array( 401, 403 ),
				sprintf( '%s must refuse a subscriber.', $path )
			);
		}
	}

	/**
	 * A write without a nonce is refused.
	 *
	 * @return void
	 */
	public function test_a_write_without_a_nonce_is_refused(): void {
		$this->asAdministrator();

		$request = new WP_REST_Request( 'POST', '/' . Brand::REST_NAMESPACE . '/profiles/save' );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'name' => 'No nonce' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( array(), ( new ProfileStore( $this->plugin->registry() ) )->saved() );
	}

	/**
	 * Act as somebody allowed to manage the site.
	 *
	 * @return void
	 */
	private function asAdministrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * GET a route with a valid nonce.
	 *
	 * @param string $path Route path.
	 * @return \WP_REST_Response
	 */
	private function get( string $path ) {
		$request = new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . $path );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * POST a route with a valid nonce.
	 *
	 * @param string              $path Route path.
	 * @param array<string,mixed> $body Body.
	 * @return \WP_REST_Response
	 */
	private function post( string $path, array $body ) {
		$request = new WP_REST_Request( 'POST', '/' . Brand::REST_NAMESPACE . $path );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}
}
