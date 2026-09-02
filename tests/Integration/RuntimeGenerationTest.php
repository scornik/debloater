<?php
/**
 * Generation, rewriting and integrity of the runtime file.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use RuntimeException;
use WPDebloat\Apply\RuntimeLoader;

/**
 * BUILD-SPEC §10 and §17 Phase 1.
 *
 * These tests treat the generated file as a product artefact: it must be
 * reproducible, tamper-evident, and removable without trace.
 */
final class RuntimeGenerationTest extends IntegrationTestCase {

	/**
	 * A selection produces a runtime file, a lock, and a matching hash.
	 *
	 * @return void
	 */
	public function test_a_selection_writes_a_runtime_and_a_lock(): void {
		$hash = $this->selectAndGenerate( array( 'core.disable_emojis' => array() ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
		$this->assertFileExists( $this->context()->runtimeFile() );
		$this->assertFileExists( $this->context()->runtimeLockFile() );
		$this->assertSame( $hash, $this->plugin->state()->runtimeHash() );
		$this->assertTrue( $this->plugin->runtimeWriter()->isIntact() );
	}

	/**
	 * BUILD-SPEC §17 Phase 1: regenerating the same selection produces a
	 * byte-identical file.
	 *
	 * @return void
	 */
	public function test_regeneration_is_byte_identical(): void {
		$selection = array(
			'core.disable_emojis'   => array(),
			'core.remove_generator' => array(),
		);

		$this->selectAndGenerate( $selection );

		$first = file_get_contents( $this->context()->runtimeFile() );

		$this->selectAndGenerate( $selection );

		$second = file_get_contents( $this->context()->runtimeFile() );

		$this->assertSame( $first, $second );
	}

	/**
	 * The lock records the generation time, which is why the file itself does
	 * not have to (docs/DECISIONS.md D-0005).
	 *
	 * @return void
	 */
	public function test_the_lock_records_provenance(): void {
		$hash = $this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );
		$lock = $this->plugin->runtimeWriter()->readLock();

		$this->assertSame( $hash, $lock['runtime_hash'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $lock['selection_hash'] );
		$this->assertSame( $this->plugin->registry()->hash(), $lock['registry_hash'] );
		$this->assertSame( $this->plugin->version(), $lock['plugin_version'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			(string) $lock['generated_at']
		);
	}

	/**
	 * Changing the selection rewrites the file and changes the hash.
	 *
	 * @return void
	 */
	public function test_changing_the_selection_changes_the_runtime(): void {
		$one = $this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );
		$two = $this->selectAndGenerate(
			array(
				'core.remove_rsd'       => array(),
				'core.remove_generator' => array(),
			)
		);

		$this->assertNotSame( $one, $two );
		$this->assertTrue( $this->plugin->runtimeWriter()->isIntact() );
	}

	/**
	 * Emptying the selection removes the runtime again, leaving nothing behind.
	 *
	 * @return void
	 */
	public function test_emptying_the_selection_removes_the_runtime(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$this->assertFileExists( $this->context()->runtimeFile() );

		$this->assertSame( '', $this->selectAndGenerate( array() ) );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
		$this->assertFileDoesNotExist( $this->context()->runtimeLockFile() );
	}

	/**
	 * A runtime edited by hand no longer matches its lock, and that is detected
	 * rather than repaired: overwriting somebody's edit would destroy the
	 * evidence of what they were trying to do.
	 *
	 * @return void
	 */
	public function test_an_edited_runtime_is_detected(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$runtime = $this->context()->runtimeFile();

		file_put_contents( $runtime, file_get_contents( $runtime ) . "\n// edited\n" );

		$this->assertFalse( $this->plugin->runtimeWriter()->isIntact() );
		$this->assertNotSame(
			$this->plugin->runtimeWriter()->recordedHash(),
			$this->plugin->runtimeWriter()->actualHash()
		);
	}

	/**
	 * A selection naming a tweak the registry does not have is skipped rather
	 * than fatal: a registry can legitimately shrink between versions.
	 *
	 * @return void
	 */
	public function test_an_unknown_tweak_in_the_selection_is_skipped(): void {
		$hash = $this->selectAndGenerate(
			array(
				'core.remove_rsd'      => array(),
				'core.from_the_future' => array(),
			)
		);

		$this->assertNotSame( '', $hash );

		$source = file_get_contents( $this->context()->runtimeFile() );

		$this->assertStringContainsString( 'core.remove_rsd', $source );
		$this->assertStringNotContainsString( 'core.from_the_future', $source );
	}

	/**
	 * The generated file is world-readable but not writable.
	 *
	 * @return void
	 */
	public function test_generated_files_are_not_world_writable(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		foreach ( array( $this->context()->runtimeFile(), $this->context()->runtimeLockFile() ) as $path ) {
			$mode = fileperms( $path ) & 0777;

			$this->assertSame( 0, $mode & 0022, sprintf( '%s must not be group- or world-writable', basename( $path ) ) );
		}
	}

	/**
	 * Everything the plugin generates lives under wp-content/wpdebloat
	 * (BUILD-SPEC §13 rule 6).
	 *
	 * @return void
	 */
	public function test_generated_files_stay_in_one_directory(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$directory = $this->context()->runtimeDir();
		$entries   = scandir( $directory );

		$this->assertIsArray( $entries );

		$files       = array();
		$directories = array();

		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			if ( is_dir( $directory . '/' . $entry ) ) {
				$directories[] = $entry;
			} else {
				$files[] = $entry;
			}
		}

		sort( $files, SORT_STRING );
		sort( $directories, SORT_STRING );

		$this->assertSame( array( 'index.php', 'runtime.lock', 'runtime.php' ), $files );

		// BUILD-SPEC §4 puts oversized Level B recovery points in a backups
		// subdirectory. It is the only thing allowed to appear beside the
		// runtime, and it only exists once one has been written.
		$this->assertSame(
			array(),
			array_values( array_diff( $directories, array( 'backups' ) ) ),
			'Nothing but the backups directory belongs under wp-content/wpdebloat.'
		);
	}

	/**
	 * The writer refuses to write outside its own directory.
	 *
	 * @return void
	 */
	public function test_the_writer_refuses_to_escape_its_directory(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$writer = $this->plugin->runtimeWriter();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Refusing to write outside|Could not resolve/' );

		$method = new \ReflectionMethod( $writer, 'atomicWrite' );
		$method->setAccessible( true );
		$method->invoke( $writer, $this->context()->content_dir . '/escaped.php', '<?php' );
	}

	/**
	 * Source that does not parse is never put in place.
	 *
	 * @return void
	 */
	public function test_unparseable_source_is_refused(): void {
		$this->expectException( RuntimeException::class );

		$this->plugin->runtimeWriter()->assertSyntax( '<?php function ( {' );
	}

	/**
	 * The generated runtime actually loads and does what it says.
	 *
	 * @return void
	 */
	public function test_the_generated_runtime_removes_the_generator_tag(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->assertNotFalse( has_action( 'wp_head', 'wp_generator' ) );

		$this->loadRuntime();

		$this->assertFalse( has_action( 'wp_head', 'wp_generator' ) );
		$this->assertSame( '', apply_filters( 'the_generator', '<meta name="generator" />', 'xhtml' ) );

		$this->unregisterHandlers( array( 'core.remove_generator' ) );

		$this->assertNotFalse( has_action( 'wp_head', 'wp_generator' ) );
	}

	/**
	 * The emoji handler removes the pieces WordPress registers, across the
	 * versions in the supported range.
	 *
	 * @return void
	 */
	public function test_the_generated_runtime_removes_the_emoji_script(): void {
		$this->selectAndGenerate( array( 'core.disable_emojis' => array() ) );

		$this->assertNotFalse( has_action( 'wp_head', 'print_emoji_detection_script' ) );

		$this->loadRuntime();

		$this->assertFalse( has_action( 'wp_head', 'print_emoji_detection_script' ) );
		$this->assertFalse( has_filter( 'wp_mail', 'wp_staticize_emoji_for_email' ) );
		$this->assertNotContains( 'wpemoji', apply_filters( 'tiny_mce_plugins', array( 'wpemoji', 'charmap' ) ) );

		$this->unregisterHandlers( array( 'core.disable_emojis' ) );
	}

	/**
	 * The loader ends up in a documented mode, and the runtime it points at is
	 * the one we generated.
	 *
	 * @return void
	 */
	public function test_the_loader_is_installed_in_a_supported_mode(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$mode = $this->plugin->state()->loaderMode();

		$this->assertSupportedLoaderMode( $mode );

		if ( RuntimeLoader::MODE_MU_PLUGIN === $mode ) {
			$this->assertTrue( $this->loaderInstalled() );
			$this->assertTrue( $this->plugin->runtimeLoader()->isUpToDate() );
		}
	}

	/**
	 * Uninstalling the loader removes it completely.
	 *
	 * @return void
	 */
	public function test_the_loader_can_be_removed(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$this->assertTrue( $this->plugin->runtimeLoader()->uninstall() );
		$this->assertFalse( $this->loaderInstalled() );
	}

	/**
	 * Deactivation removes the runtime and the loader but keeps the selection,
	 * so reactivating restores exactly what was there.
	 *
	 * @return void
	 */
	public function test_deactivation_removes_the_runtime_but_keeps_the_selection(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );

		$this->plugin->deactivate();

		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
		$this->assertFalse( $this->loaderInstalled() );
		$this->assertSame( array( 'core.remove_rsd' => array() ), $this->plugin->state()->selection() );

		$this->plugin->regenerateRuntime();

		$this->assertFileExists( $this->context()->runtimeFile() );
	}
}
