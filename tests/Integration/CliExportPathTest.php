<?php
/**
 * Where the CLI writes an export, and where it refuses to.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Cli\Command;
use Debloater\Cli\ExportDestination;
use Debloater\Config\ProfileStore;
use Debloater\Tests\Integration\Support\RecordingIo;

/**
 * wordpress.org review, rounds 1 and 2: where a plugin is allowed to write.
 *
 * Round 1 gave exports a home in `uploads/debloater/`, created on demand and
 * closed to the web, and kept `--file=<path>` for WP-CLI. Round 2 refused the
 * path as well, so `uploads/debloater/` is now the only destination there is
 * and `--file=-` — standard output, which is not a write — is the only value
 * the flag takes (`D-0074`).
 */
final class CliExportPathTest extends IntegrationTestCase {

	/**
	 * Files this test created, removed afterwards.
	 *
	 * @var array<int,string>
	 */
	private array $written = array();

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		$this->written = array();

		delete_option( ProfileStore::OPTION );

		parent::tear_down();
	}

	/**
	 * With no --file, the export lands in uploads/debloater.
	 *
	 * @return void
	 */
	public function test_the_default_destination_is_inside_uploads(): void {
		$this->saveProfile( 'Client baseline' );

		// The delta, not the count. The directory is a real one on a real site
		// and survives between runs, so anything absolute here passes on a
		// clean machine and fails on the second run.
		$before = $this->exports();

		$io = $this->runProfile( array( 'export', 'Client baseline' ), array() );

		$this->assertSame( Command::EXIT_OK, $io->code, $io->output() );

		$new = array_values( array_diff( $this->exports(), $before ) );

		$this->assertCount( 1, $new, 'exactly one export should have been written' );

		$this->written[] = $new[0];

		$decoded = json_decode( (string) file_get_contents( $new[0] ), true );

		$this->assertIsArray( $decoded );
		$this->assertStringContainsString( 'client-baseline-', basename( $new[0] ) );
	}

	/**
	 * Every export file currently in the default directory.
	 *
	 * @return array<int,string>
	 */
	private function exports(): array {
		$uploads = wp_upload_dir();
		$files   = glob( $uploads['basedir'] . '/' . ExportDestination::FOLDER . '/*.json' );

		return is_array( $files ) ? $files : array();
	}

	/**
	 * The directory is closed to the web when it is created.
	 *
	 * Both guards, because a site is served by one of two web servers and the
	 * plugin cannot know which. Neither helps on nginx, which is why the file
	 * name carries random bytes as well — asserted here so that removing the
	 * randomness has to be a deliberate act.
	 *
	 * @return void
	 */
	public function test_the_export_directory_is_closed_to_the_web(): void {
		$directory = ( new ExportDestination() )->directory();

		$this->assertFileExists( $directory . '/index.php' );
		$this->assertFileExists( $directory . '/.htaccess' );

		$this->assertStringContainsString(
			'Require all denied',
			(string) file_get_contents( $directory . '/.htaccess' )
		);

		$first  = ( new ExportDestination() )->filename( 'baseline' );
		$second = ( new ExportDestination() )->filename( 'baseline' );

		$this->assertNotSame( $first, $second, 'an export file name must not be guessable' );
	}

	/**
	 * A name that tries to walk out of the directory cannot.
	 *
	 * The profile name reaches the file name, and a profile name is typed by a
	 * person. This is the one part of the path that is not the operator's
	 * explicit choice, so it is the part that has to be defended.
	 *
	 * @return void
	 */
	public function test_a_profile_name_cannot_escape_the_directory(): void {
		$destination = new ExportDestination();

		foreach ( array( '../../evil', '..\\..\\evil', '/etc/passwd', 'a/b/c' ) as $hostile ) {
			$name = $destination->filename( $hostile );

			$this->assertStringNotContainsString( '/', $name, $hostile );
			$this->assertStringNotContainsString( '\\', $name, $hostile );
			$this->assertStringNotContainsString( '..', $name, $hostile );
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+-\d{8}-\d{6}-[0-9a-f]{8}\.json$/', $name, $hostile );
		}
	}

	/**
	 * A path is refused, and nothing is written anywhere.
	 *
	 * 0.3.0 honoured `--file=<path>` for WP-CLI. wordpress.org round 2 refused
	 * it, so this is the inverse of the test that used to be here: the same
	 * command, asserting the opposite, because that is what changed (D-0074).
	 *
	 * The refusal is explicit rather than silent. Somebody who typed a path
	 * expects their file to be there, and writing it elsewhere without saying
	 * so is how an export goes missing.
	 *
	 * @return void
	 */
	public function test_a_path_is_refused(): void {
		$this->saveProfile( 'Client baseline' );

		$target = get_temp_dir() . 'debloater-explicit-' . bin2hex( random_bytes( 4 ) ) . '.json';
		$before = $this->exports();

		$io = $this->runProfile(
			array( 'export', 'Client baseline' ),
			array( 'file' => $target )
		);

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertFileDoesNotExist( $target );
		$this->assertStringContainsString( 'uploads/debloater/', $io->output() );

		// And it did not quietly write to the default place instead.
		$this->assertSame( $before, $this->exports(), 'a refused export must write nothing' );
	}

	/**
	 * `--file=-` prints, and writes nothing.
	 *
	 * @return void
	 */
	public function test_a_dash_prints_instead_of_writing(): void {
		$this->saveProfile( 'Client baseline' );

		$before = $this->exports();

		$io = $this->runProfile( array( 'export', 'Client baseline' ), array( 'file' => '-' ) );

		$this->assertSame( Command::EXIT_OK, $io->code, $io->output() );
		$this->assertStringContainsString( 'Client baseline', $io->output() );

		$this->assertSame( $before, $this->exports(), 'printing must not also write a file' );
	}

	/**
	 * Save a profile to export.
	 *
	 * @param string $name Profile name.
	 * @return void
	 */
	private function saveProfile( string $name ): void {
		$this->plugin->state()->setSelection( array( 'core.remove_generator' => array() ) );

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( array( 'save', $name ), array() );

		$this->assertSame( Command::EXIT_OK, $io->code, $io->output() );
	}

	/**
	 * Run `wp debloater profile <args>`.
	 *
	 * Not called run(). PHPUnit\Framework\TestCase::run() is public, a private
	 * override of a public method is a compile-time fatal, and the whole suite
	 * exits 255 before printing a single line — which looks like the harness
	 * being broken rather than this file being wrong.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return object The recording IO.
	 */
	private function runProfile( array $args, array $assoc_args ) {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->profile( $args, $assoc_args );

		return $io;
	}
}
