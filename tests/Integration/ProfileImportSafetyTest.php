<?php
/**
 * Importing a profile changes nothing.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Brand;
use Debloater\Cli\Command;
use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
use Debloater\Recommend\IntentProfile;
use Debloater\Rest\ConfirmationToken;
use Debloater\Tests\Integration\Support\RecordingIo;
use WP_REST_Request;

/**
 * BUILD-SPEC §13 rule 8, and docs/DECISIONS.md D-0063.
 *
 * A profile is a file that arrives from somewhere else: emailed, downloaded,
 * committed to a repository by somebody who left. It is exactly the input that
 * must not be able to change a site by being read.
 *
 * So the assertions here are about what did *not* happen. That is worth being
 * explicit about, because "nothing happened" is the easiest thing in the world
 * to assert badly — a test that checks one of the four things that should not
 * have changed will pass while the other three did.
 *
 * The four: no tweak state moved, no selection was written, no runtime file
 * appeared, no run was recorded. And separately, that the apply route refuses a
 * plan with no confirmation token, by name.
 */
final class ProfileImportSafetyTest extends IntegrationTestCase {

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
	 * Importing changes no state, writes no runtime and records no run.
	 *
	 * @return void
	 */
	public function test_importing_a_profile_changes_nothing_about_the_site(): void {
		$this->plugin->scan();

		$before = $this->siteState();

		$file = $this->write(
			( new Profile(
				'From elsewhere',
				array(
					'core.remove_generator' => array(),
					'core.remove_rsd'       => array(),
				),
				new IntentProfile()
			) )->toJson()
		);

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'import', $file ), array() );

		unlink( $file );

		// It did import: the profile is saved, so this is not passing because
		// nothing happened at all.
		$this->assertNotSame( array(), ( new ProfileStore( $this->plugin->registry() ) )->saved() );

		$after = $this->siteState();

		$this->assertSame(
			$before['tweak_states'],
			$after['tweak_states'],
			'importing must not move any tweak into SELECTED or anywhere else'
		);
		$this->assertSame(
			$before['selection'],
			$after['selection'],
			'importing must not change what this site has selected'
		);
		$this->assertSame(
			$before['runtime_hash'],
			$after['runtime_hash'],
			'importing must not generate a runtime'
		);
		$this->assertFalse( $after['runtime_exists'], 'importing must not write a runtime file' );
		$this->assertSame(
			$before['runs'],
			$after['runs'],
			'importing must not record a run: nothing ran'
		);
	}

	/**
	 * The apply route refuses a plan with no confirmation token, by name.
	 *
	 * @return void
	 */
	public function test_applying_without_a_confirmation_token_is_refused(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$before = $this->siteState();

		$response = $this->post(
			'/apply',
			array(
				'tweaks'  => array( 'core.remove_generator' ),
				'confirm' => str_repeat( '0', 64 ),
			)
		);

		$this->assertSame( 409, $response->get_status() );

		$body = $response->get_data();

		$this->assertSame(
			'debloater_stale_confirmation',
			$body['code'] ?? ( is_object( $body ) ? $body->get_error_code() : '' ),
			'the refusal must name the confirmation, not something else that also fails'
		);

		$after = $this->siteState();

		$this->assertSame( $before['tweak_states'], $after['tweak_states'] );
		$this->assertSame( $before['runs'], $after['runs'], 'a refused apply must not record a run' );
		$this->assertFalse( $after['runtime_exists'] );
	}

	/**
	 * And with the right token it does apply, so the refusal means something.
	 *
	 * Without this the test above would pass on a route that refused
	 * everything, which is a different bug wearing the same result.
	 *
	 * @return void
	 */
	public function test_the_same_request_with_the_right_token_is_accepted(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$response = $this->post(
			'/apply',
			array(
				'tweaks'  => array( 'core.remove_generator' ),
				'confirm' => ConfirmationToken::forPlan( $preview->plan ),
			)
		);

		$this->assertNotSame(
			409,
			$response->get_status(),
			'a correct token must be accepted, or the refusal above proves nothing'
		);

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * Everything about this site that an import must leave alone.
	 *
	 * @return array<string,mixed>
	 */
	private function siteState(): array {
		$state = $this->plugin->state();

		return array(
			'tweak_states'   => $state->tweakStates(),
			'selection'      => $state->selection(),
			'runtime_hash'   => $state->runtimeHash(),
			'runtime_exists' => is_file( WP_CONTENT_DIR . '/debloater/runtime.php' ),
			'runs'           => count( $this->plugin->runs()->recent( 100 ) ),
		);
	}

	/**
	 * Act as somebody allowed to change the site.
	 *
	 * @return void
	 */
	private function asAdministrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

	/**
	 * A file to import.
	 *
	 * @param string $contents What to write.
	 * @return string Path.
	 */
	private function write( string $contents ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'debloater-profile' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file this test made.
		file_put_contents( $path, $contents );

		return $path;
	}
}
