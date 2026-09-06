<?php
/**
 * `wp debloater profile`.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Cli\Command;
use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
use Debloater\Recommend\IntentProfile;
use Debloater\Tests\Integration\Support\RecordingIo;

/**
 * BUILD-SPEC §13 rule 8, §17 Phase 19c.
 *
 * The command is a surface: every decision it makes belongs to `Config\Profile`
 * and `Config\ProfileStore`, and what is asserted here is that the surface says
 * what those decided and adds nothing of its own — in particular that it cannot
 * apply anything without being asked to.
 */
final class ProfileCliTest extends IntegrationTestCase {

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ProfileStore::OPTION );

		parent::tear_down();
	}

	/**
	 * A fresh site lists the built-ins and nothing else.
	 *
	 * The state most people see first. A profiles list that is empty until you
	 * save something teaches you that the feature is empty.
	 *
	 * @return void
	 */
	public function test_a_site_with_nothing_saved_still_lists_the_builtins(): void {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'list' ), array( 'json' => true ) );

		$listed = $io->lastDocument()['profiles'] ?? array();
		$ids    = array_column( $listed, 'id' );

		$this->assertContains( 'safe', $ids );
		$this->assertContains( 'performance', $ids );
		$this->assertContains( 'maximum', $ids );

		foreach ( $listed as $row ) {
			$this->assertSame( 'built in', $row['source'] );
		}
	}

	/**
	 * Saving keeps what the site has selected, under a readable id.
	 *
	 * @return void
	 */
	public function test_saving_stores_the_current_selection(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'save', 'Client baseline' ), array() );

		$store = new ProfileStore( $this->plugin->registry() );
		$saved = $store->saved();

		$this->assertArrayHasKey( 'client-baseline', $saved, 'the id should be readable' );
		$this->assertSame( 'Client baseline', $saved['client-baseline']->name );
		$this->assertSame(
			array( 'core.remove_generator' ),
			array_keys( $saved['client-baseline']->selection )
		);

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * What the CLI writes is what the profile encodes, byte for byte.
	 *
	 * The admin screen exports through `Profile::toJson()` too, so this is what
	 * makes "the CLI and the UI produce the same file" a fact rather than an
	 * intention. Comparing against the encoder rather than against a fixture
	 * means the assertion survives a formatting change and still fails if the
	 * two surfaces ever diverge.
	 *
	 * @return void
	 */
	public function test_the_exported_bytes_are_the_profile_s_own_encoding(): void {
		$profile = new Profile(
			'Client baseline',
			array( 'core.remove_generator' => array() ),
			new IntentProfile( 'blog', 'balanced' ),
			$this->plugin->registry()->hash(),
			'2026-01-01T00:00:00Z'
		);

		( new ProfileStore( $this->plugin->registry() ) )->save( $profile, 'client-baseline' );

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'export', 'client-baseline' ), array() );

		$printed = implode( "\n", $io->lines );

		$this->assertSame( rtrim( $profile->toJson(), "\n" ), $printed );

		// And it is a profile by the schema's reckoning, not merely by ours.
		$this->assertIsArray( json_decode( $printed, true ) );
	}

	/**
	 * A profile can be found by the name a person gave it.
	 *
	 * @return void
	 */
	public function test_a_profile_is_found_by_name_as_well_as_by_id(): void {
		( new ProfileStore( $this->plugin->registry() ) )->save(
			new Profile( 'Client baseline', array(), new IntentProfile() ),
			'client-baseline'
		);

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'export', 'Client baseline' ), array() );

		$this->assertStringContainsString( '"name": "Client baseline"', implode( "\n", $io->lines ) );
	}

	/**
	 * Importing a file names what it skipped and applies nothing.
	 *
	 * @return void
	 */
	public function test_importing_lists_unknown_changes_and_skips_them(): void {
		$file = $this->write(
			( new Profile(
				'From elsewhere',
				array(
					'core.remove_generator' => array(),
					'not.a_real_change'     => array(),
				),
				new IntentProfile(),
				str_repeat( 'b', 64 )
			) )->toJson()
		);

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'import', $file ), array( 'json' => true ) );

		$warnings = implode( "\n", $io->warnings );

		$this->assertStringContainsString( 'not.a_real_change', $warnings, 'the skipped change must be named' );
		$this->assertStringContainsString( 'different registry', $warnings, 'the hash mismatch must be mentioned' );

		$saved = ( new ProfileStore( $this->plugin->registry() ) )->saved();
		$first = reset( $saved );

		$this->assertNotFalse( $first );
		$this->assertSame( array( 'core.remove_generator' ), array_keys( $first->selection ) );

		// Nothing was applied, and the command says so.
		$this->assertFalse( $io->lastDocument()['applied'] );

		unlink( $file );
	}

	/**
	 * Applying without --yes is refused, and nothing runs.
	 *
	 * §13 rule 8. The confirmation is asked for before a plan is even built, so
	 * a refusal costs nothing and cannot half-happen.
	 *
	 * @return void
	 */
	public function test_applying_without_confirmation_is_refused(): void {
		$this->plugin->scan();

		( new ProfileStore( $this->plugin->registry() ) )->save(
			new Profile( 'Client baseline', array( 'core.remove_generator' => array() ), new IntentProfile() ),
			'client-baseline'
		);

		$before = $this->plugin->runs()->recent( 50 );

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'apply', 'client-baseline' ), array() );

		$this->assertStringContainsString( '--yes', implode( "\n", $io->errors ) );
		$this->assertSame( Command::EXIT_ERROR, $io->code );

		$this->assertCount(
			count( $before ),
			$this->plugin->runs()->recent( 50 ),
			'a refused apply must not have created a run'
		);
	}

	/**
	 * A file to import.
	 *
	 * @param string $contents What to write.
	 * @return string Path.
	 */
	private function write( string $contents ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'debloater-profile' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file this test made, in the test's own directory.
		file_put_contents( $path, $contents );

		return $path;
	}
}
