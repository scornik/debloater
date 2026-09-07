<?php
/**
 * Repository-level invariants that no single class owns.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit;

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
			array( 'Debloater\\' => 'src/' ),
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
			$this->assertStringContainsString( '@package Debloater', $contents, $path . ' needs a file docblock' );
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
	 * The schema for the configuration document `wp debloater export` writes is
	 * deliberately not here: that is not registry content, and it lives in
	 * `schemas/`, checked below.
	 *
	 * @return void
	 */
	public function test_registry_schemas_are_valid_json(): void {
		$schemas = glob( DEBLOATER_TESTS_ROOT . '/registry/schemas/*.schema.json' );

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
	 * configuration file `wp debloater export` writes is not registry content, so
	 * it lives in `schemas/` — where the same rules about being valid draft-07
	 * with a title and a description still apply.
	 *
	 * @return void
	 */
	public function test_document_schemas_are_valid_json(): void {
		$schemas = glob( DEBLOATER_TESTS_ROOT . '/schemas/*.schema.json' );

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
			new \RecursiveDirectoryIterator( DEBLOATER_TESTS_ROOT . '/tests/Fixtures', \FilesystemIterator::SKIP_DOTS )
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
		$decisions = file_get_contents( DEBLOATER_TESTS_ROOT . '/docs/DECISIONS.md' );

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
		$raw = file_get_contents( DEBLOATER_TESTS_ROOT . '/composer.json' );

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
					DEBLOATER_TESTS_ROOT . '/' . $directory,
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

	/**
	 * The decisions that belong to Pro are not also recorded here.
	 *
	 * Both repositories carried a full copy of this file from the split until
	 * 0.2.0 -- fifty-six of the entries identical in both. Nothing noticed,
	 * because nothing looked, and amending either copy would have left the
	 * other stating the opposite with equal authority.
	 *
	 * This is the half of the check that can run here. Pro's repository is
	 * private and this one is public, so nothing here may check it out; the
	 * comparison of the two files lives in Pro, whose CI has both. What this
	 * can know alone is that the numbers reserved for Pro are not in this file,
	 * and that is enough to stop a duplicate arriving from this side.
	 *
	 * @return void
	 */
	public function test_pro_only_decisions_are_not_recorded_here(): void {
		$reserved = array( 'D-0035', 'D-0050', 'D-0060', 'D-0061', 'D-0062', 'D-0064', 'D-0065', 'D-0068' );

		$decisions = $this->decisionNumbers();

		foreach ( $reserved as $number ) {
			$this->assertNotContains(
				$number,
				$decisions,
				sprintf(
					'%s belongs to scornik/debloater-pro. A decision recorded in both places '
						. 'is two decisions that can disagree.',
					$number
				)
			);
		}

		// And the registry's, for the same reason.
		$this->assertNotContains( 'D-0067', $decisions );
	}

	/**
	 * Every decision number is used once, and the file says where the rest are.
	 *
	 * @return void
	 */
	public function test_the_decision_record_is_internally_consistent(): void {
		$decisions = $this->decisionNumbers();

		$this->assertSame(
			array_unique( $decisions ),
			$decisions,
			'A decision number is used twice in this file.'
		);

		$this->assertGreaterThan( 50, count( $decisions ) );

		// The pointer to the decisions that are elsewhere. A reader who cannot
		// find D-0060 here has to be told it exists rather than left to
		// conclude it was never taken.
		$markdown = $this->decisionRecord();

		$this->assertStringContainsString( 'scornik/debloater-pro', $markdown );
		$this->assertStringContainsString( 'D-0060', $markdown );
		$this->assertStringContainsString( 'scornik/debloater-registry', $markdown );
	}

	/**
	 * The Principles section exists and is short enough to be read.
	 *
	 * It stops being read at about a dozen entries, which is written into the
	 * section itself. A test is the only thing that will notice the day it
	 * quietly becomes fifteen.
	 *
	 * @return void
	 */
	public function test_the_principles_stay_short(): void {
		preg_match_all( '/^\*\*(P\d+)\./m', $this->decisionRecord(), $found );

		$this->assertNotEmpty( $found[1], 'docs/DECISIONS.md should have a Principles section.' );

		$this->assertLessThanOrEqual(
			12,
			count( $found[1] ),
			'The principles have outgrown being read. Consolidate rather than append -- '
				. 'the section says so itself.'
		);

		$this->assertSame( array_unique( $found[1] ), $found[1], 'A principle number is reused.' );
	}

	/**
	 * The decision record, as text.
	 *
	 * @return string
	 */
	private function decisionRecord(): string {
		$path = dirname( __DIR__, 2 ) . '/docs/DECISIONS.md';

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Every `## D-NNNN` heading in the decision record.
	 *
	 * @return string[]
	 */
	private function decisionNumbers(): array {
		preg_match_all( '/^## (D-\d+)/m', $this->decisionRecord(), $found );

		return $found[1];
	}
}
