<?php
/**
 * Repository-level invariants that no single class owns.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Some rules in BUILD-SPEC are properties of the repository rather than of any
 * one class. They are asserted here so a later phase cannot quietly break them.
 */
final class RepositoryInvariantsTest extends TestCase {

	/**
	 * BUILD-SPEC §3: "Composer (dev deps only; no runtime deps)".
	 *
	 * @return void
	 */
	public function test_there_are_no_runtime_composer_dependencies(): void {
		$composer = $this->composerJson();

		$this->assertArrayHasKey( 'require', $composer );
		$this->assertSame(
			array( 'php' ),
			array_keys( $composer['require'] ),
			'The plugin must ship with zero runtime Composer dependencies; only a PHP version constraint is allowed.'
		);
	}

	/**
	 * The PSR-4 root is the one BUILD-SPEC §3 fixes.
	 *
	 * @return void
	 */
	public function test_psr4_autoloading_maps_the_specified_namespace(): void {
		$composer = $this->composerJson();

		$this->assertSame(
			array( 'WPDebloat\\' => 'src/' ),
			$composer['autoload']['psr-4']
		);
	}

	/**
	 * BUILD-SPEC §10: runtime handlers are deliberately not autoloaded, so they
	 * must never appear in an autoload section.
	 *
	 * @return void
	 */
	public function test_runtime_handlers_are_not_autoloaded(): void {
		$composer = $this->composerJson();
		$encoded  = json_encode( array( $composer['autoload'], $composer['autoload-dev'] ) );

		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'runtime-handlers', $encoded );
	}

	/**
	 * Every source file declares strict types (CONVENTIONS.md).
	 *
	 * @return void
	 */
	public function test_every_source_file_declares_strict_types(): void {
		foreach ( $this->sourceFiles() as $path ) {
			$contents = file_get_contents( $path );

			$this->assertIsString( $contents );
			$this->assertStringContainsString(
				'declare( strict_types = 1 );',
				$contents,
				$path . ' must declare strict types'
			);
		}
	}

	/**
	 * Every source file carries the package tag, so generated docs and the
	 * plugin header stay consistent.
	 *
	 * @return void
	 */
	public function test_every_source_file_has_a_file_docblock(): void {
		foreach ( $this->sourceFiles() as $path ) {
			$contents = file_get_contents( $path );

			$this->assertIsString( $contents );
			$this->assertStringContainsString( '@package WPDebloat', $contents, $path . ' needs a file docblock' );
		}
	}

	/**
	 * Phase 0 is contracts only: nothing in src/ may call WordPress yet, which is
	 * what lets the whole unit suite run without WordPress loaded.
	 *
	 * Later phases add WordPress-dependent layers; this assertion is scoped to
	 * the directories that must stay WordPress-free for good.
	 *
	 * @return void
	 */
	public function test_contracts_and_registry_do_not_call_wordpress(): void {
		$forbidden = array(
			'add_action(',
			'add_filter(',
			'get_option(',
			'update_option(',
			'wp_remote_get(',
			'$wpdb',
		);

		foreach ( $this->sourceFiles( array( 'src/Contracts', 'src/Registry' ) ) as $path ) {
			$contents = file_get_contents( $path );

			$this->assertIsString( $contents );

			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$contents,
					$path . ' must not depend on WordPress: found ' . $needle
				);
			}
		}
	}

	/**
	 * Every shipped registry schema is valid JSON and declares draft-07 — and
	 * the set of them is exactly what it should be.
	 *
	 * The set is asserted by name rather than by count, which is both stricter
	 * and more honest than the number it replaced. Six of them are the object
	 * types BUILD-SPEC §4 lists: one document per tweak, per detector, per
	 * compatibility rule, per profile, plus the fact and finding shapes. The
	 * others describe registry *tables* — single files holding a lookup, where a
	 * document per object would have been forty files each holding one word.
	 * Phase 11 added the plugin categories and the host optimizers; Phase 12
	 * added the admin-notice vendor allowlist.
	 *
	 * The schema for the configuration document `wp debloat export` writes is
	 * deliberately not here: that is not registry content, and it lives in
	 * `schemas/`, checked below.
	 *
	 * @return void
	 */
	public function test_registry_schemas_are_valid_json(): void {
		$schemas = glob( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/*.schema.json' );

		$this->assertIsArray( $schemas );

		$names = array_map( 'basename', $schemas );
		sort( $names, SORT_STRING );

		$this->assertSame(
			array(
				'admin-notices.schema.json',
				'compat.schema.json',
				'detector.schema.json',
				'fact.schema.json',
				'finding.schema.json',
				'host-optimizers.schema.json',
				'plugin-categories.schema.json',
				'profile.schema.json',
				'tweak.schema.json',
			),
			$names,
			'registry/schemas holds the six object types BUILD-SPEC §4 names plus the registry tables, and nothing else'
		);

		$this->assertSchemasAreWellFormed( $schemas );
	}

	/**
	 * Schemas that describe documents rather than registry content live apart.
	 *
	 * `registry/schemas` holds exactly the six documents §4 names. The
	 * configuration file `wp debloat export` writes is not registry content, so
	 * it lives in `schemas/` — where the same rules about being valid draft-07
	 * with a title and a description still apply.
	 *
	 * @return void
	 */
	public function test_document_schemas_are_valid_json(): void {
		$schemas = glob( WPDEBLOAT_TESTS_ROOT . '/schemas/*.schema.json' );

		$this->assertIsArray( $schemas );
		$this->assertNotEmpty( $schemas );
		$this->assertSchemasAreWellFormed( $schemas );
	}

	/**
	 * Every given schema file is valid draft-07 with a title and a description.
	 *
	 * @param array<int,string> $schemas Absolute paths.
	 * @return void
	 */
	private function assertSchemasAreWellFormed( array $schemas ): void {
		foreach ( $schemas as $path ) {
			$raw = file_get_contents( $path );

			$this->assertIsString( $raw );

			$decoded = json_decode( $raw, true );

			$this->assertIsArray( $decoded, $path . ' is not valid JSON: ' . json_last_error_msg() );
			$this->assertSame( 'http://json-schema.org/draft-07/schema#', $decoded['$schema'] ?? null, $path );
			$this->assertArrayHasKey( 'title', $decoded, $path );
			$this->assertArrayHasKey( 'description', $decoded, $path );
		}
	}

	/**
	 * Every fixture under tests/Fixtures is valid JSON, so a malformed fixture
	 * cannot masquerade as a schema failure.
	 *
	 * @return void
	 */
	public function test_fixtures_are_valid_json(): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( WPDEBLOAT_TESTS_ROOT . '/tests/Fixtures', \FilesystemIterator::SKIP_DOTS )
		);

		$count = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'json' !== $file->getExtension() ) {
				continue;
			}

			++$count;

			$raw = file_get_contents( $file->getPathname() );

			$this->assertIsString( $raw );
			$this->assertIsArray(
				json_decode( $raw, true ),
				$file->getPathname() . ' is not valid JSON: ' . json_last_error_msg()
			);
		}

		$this->assertGreaterThan( 0, $count );
	}

	/**
	 * The decision log records every decision this phase depends on.
	 *
	 * @return void
	 */
	public function test_phase_zero_decisions_are_recorded(): void {
		$decisions = file_get_contents( WPDEBLOAT_TESTS_ROOT . '/docs/DECISIONS.md' );

		$this->assertIsString( $decisions );

		foreach ( array( 'D-0001', 'D-0002', 'D-0003' ) as $id ) {
			$this->assertStringContainsString( $id, $decisions );
		}
	}

	/**
	 * The decoded composer.json.
	 *
	 * @return array<string,mixed>
	 */
	private function composerJson(): array {
		$raw = file_get_contents( WPDEBLOAT_TESTS_ROOT . '/composer.json' );

		$this->assertIsString( $raw );

		$decoded = json_decode( $raw, true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * PHP files under the given repository-relative directories.
	 *
	 * @param array<int,string> $directories Repository-relative directories.
	 * @return array<int,string>
	 */
	private function sourceFiles( array $directories = array( 'src' ) ): array {
		$files = array();

		foreach ( $directories as $directory ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					WPDEBLOAT_TESTS_ROOT . '/' . $directory,
					\FilesystemIterator::SKIP_DOTS
				)
			);

			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		sort( $files, SORT_STRING );

		return $files;
	}
}
