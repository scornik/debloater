<?php
/**
 * Tests for the runtime compiler.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Apply;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Debloater\Apply\Compiler;
use Debloater\Contracts\Category;
use Debloater\Contracts\Context;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Tweak;
use Debloater\Contracts\TweakKind;
use Debloater\Contracts\TweakParams;
use Debloater\Registry\Loader;
use Debloater\Registry\Registry;

/**
 * The compiler writes the file that runs on every request to the site, so its
 * output is pinned by snapshot and its refusals are tested as carefully as its
 * successes.
 *
 * Snapshots live in tests/Fixtures/runtime with the plugin directory replaced by
 * a placeholder, since the real path differs on every machine. Regenerate them
 * with DEBLOATER_UPDATE_SNAPSHOTS=1 after a deliberate change to the output.
 */
final class CompilerTest extends TestCase {

	/**
	 * Placeholder standing in for the absolute plugin directory in snapshots.
	 */
	private const PLUGIN_DIR_TOKEN = '{PLUGIN_DIR}';

	/**
	 * An empty selection compiles to nothing at all.
	 *
	 * BUILD-SPEC §10: no runtime file is written when nothing is selected. A file
	 * that "does nothing" would still be stat'ed and parsed on every request.
	 *
	 * @return void
	 */
	public function test_an_empty_selection_compiles_to_nothing(): void {
		$this->assertSame( '', $this->compiler()->compile( array() ) );
	}

	/**
	 * A selection of only data tweaks also compiles to nothing: data operations
	 * run once and never live in the runtime.
	 *
	 * @return void
	 */
	public function test_data_tweaks_do_not_reach_the_runtime(): void {
		$data = new Tweak(
			'db.clean_expired_transients',
			'Delete expired transients',
			Category::DATABASE,
			TweakKind::DATA,
			Risk::LOW,
			false,
			true,
			new TweakParams(),
			'Debloater\\Apply\\DataOperations\\ExpiredTransientsCleanup'
		);

		$this->assertSame( '', $this->compiler()->compile( array( $data ) ) );
	}

	/**
	 * One tweak, pinned by snapshot.
	 *
	 * @return void
	 */
	public function test_single_tweak_snapshot(): void {
		$source = $this->compiler()->compile( $this->tweaks( array( 'core.disable_emojis' ) ), self::REGISTRY_HASH );

		$this->assertMatchesSnapshot( 'one-tweak.php.txt', $source );
	}

	/**
	 * Three tweaks, pinned by snapshot.
	 *
	 * @return void
	 */
	public function test_three_tweak_snapshot(): void {
		$source = $this->compiler()->compile(
			$this->tweaks( array( 'core.remove_shortlink', 'core.disable_emojis', 'core.remove_generator' ) ),
			self::REGISTRY_HASH
		);

		$this->assertMatchesSnapshot( 'three-tweaks.php.txt', $source );
	}

	/**
	 * A tweak with parameters, pinned by snapshot, so a change to how values are
	 * emitted into generated code cannot pass unnoticed.
	 *
	 * @return void
	 */
	public function test_parameterised_tweak_snapshot(): void {
		$source = $this->compiler()->compile( array( $this->parameterisedTweak() ), self::REGISTRY_HASH );

		$this->assertMatchesSnapshot( 'parameters.php.txt', $source );
	}

	/**
	 * BUILD-SPEC §17 Phase 1: regenerating the same selection produces a
	 * byte-identical file.
	 *
	 * @return void
	 */
	public function test_compilation_is_byte_identical_on_regeneration(): void {
		$compiler = $this->compiler();
		$tweaks   = $this->tweaks( array( 'core.disable_emojis', 'core.remove_rsd' ) );

		$this->assertSame(
			$compiler->compile( $tweaks, self::REGISTRY_HASH ),
			$compiler->compile( $tweaks, self::REGISTRY_HASH )
		);
	}

	/**
	 * The order the selection arrives in must not change the output.
	 *
	 * @return void
	 */
	public function test_output_does_not_depend_on_selection_order(): void {
		$compiler = $this->compiler();
		$ids      = array( 'core.disable_emojis', 'core.remove_rsd', 'core.remove_generator' );

		$forward  = $compiler->compile( $this->tweaks( $ids ), self::REGISTRY_HASH );
		$backward = $compiler->compile( $this->tweaks( array_reverse( $ids ) ), self::REGISTRY_HASH );

		$this->assertSame( $forward, $backward );
	}

	/**
	 * The selection hash follows the same rule.
	 *
	 * @return void
	 */
	public function test_selection_hash_does_not_depend_on_order(): void {
		$compiler = $this->compiler();
		$ids      = array( 'core.disable_emojis', 'core.remove_rsd' );

		$this->assertSame(
			$compiler->selectionHash( $this->tweaks( $ids ) ),
			$compiler->selectionHash( $this->tweaks( array_reverse( $ids ) ) )
		);
	}

	/**
	 * Changing a parameter changes the selection hash, because the runtime it
	 * describes is a different file.
	 *
	 * @return void
	 */
	public function test_selection_hash_changes_with_parameters(): void {
		$compiler = $this->compiler();

		$sixty  = $compiler->selectionHash( array( $this->parameterisedTweak( 60 ) ) );
		$ninety = $compiler->selectionHash( array( $this->parameterisedTweak( 90 ) ) );

		$this->assertNotSame( $sixty, $ninety );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $sixty );
	}

	/**
	 * An empty selection hashes to a stable, non-empty value rather than ''.
	 *
	 * @return void
	 */
	public function test_empty_selection_still_has_a_hash(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $this->compiler()->selectionHash( array() ) );
	}

	/**
	 * The same tweak twice is a caller error, not something to deduplicate
	 * silently: it means two code paths disagree about the selection.
	 *
	 * @return void
	 */
	public function test_a_duplicate_tweak_is_refused(): void {
		$tweaks = $this->tweaks( array( 'core.disable_emojis' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/appears twice/' );

		$this->compiler()->compile( array( $tweaks[0], $tweaks[0] ) );
	}

	/**
	 * Handler class names follow the documented convention.
	 *
	 * @return void
	 */
	public function test_handler_class_naming(): void {
		$compiler = $this->compiler();

		$this->assertSame( 'Debloater_Handler_Core_Disable_Emojis', $compiler->handlerClass( 'core.disable_emojis' ) );
		$this->assertSame( 'Debloater_Handler_Core_Remove_Rsd', $compiler->handlerClass( 'core.remove_rsd' ) );
		$this->assertSame( 'Debloater_Handler_Woo_Cart_Fragments_Conditional', $compiler->handlerClass( 'woo.cart_fragments_conditional' ) );
	}

	/**
	 * Every class named in the generated source actually exists in the file the
	 * generated source requires. A typo here is a fatal error on every page.
	 *
	 * @return void
	 */
	public function test_every_generated_class_exists_in_its_handler_file(): void {
		$compiler = $this->compiler();
		$registry = $this->registry();

		foreach ( $registry->all() as $id => $definition ) {
			if ( TweakKind::CONFIG !== $definition->kind ) {
				continue;
			}

			$path     = $compiler->handlerPath( $definition->resolve() );
			$source   = file_get_contents( $path );
			$expected = $compiler->handlerClass( $id );

			$this->assertIsString( $source );
			$this->assertStringContainsString(
				'class ' . $expected,
				$source,
				sprintf( '%s must define %s', $definition->handler, $expected )
			);
		}
	}

	/**
	 * BUILD-SPEC §13 rule 5: generated code may only require files from the
	 * plugin's own handler directory. A traversal in the registry is refused
	 * after resolution, so it cannot pass by looking innocent as a string.
	 *
	 * @return void
	 */
	public function test_a_handler_outside_the_plugin_is_refused(): void {
		$escaping = new Tweak(
			'core.evil',
			'Escapes the handler directory',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			false,
			true,
			new TweakParams(),
			'runtime-handlers/../../../../wp-config.php'
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside the plugin handler directory|not found/' );

		$this->compiler()->compile( array( $escaping ) );
	}

	/**
	 * A handler file that does not exist is refused rather than compiled into a
	 * require that will fatal on the next request.
	 *
	 * @return void
	 */
	public function test_a_missing_handler_is_refused(): void {
		$missing = new Tweak(
			'core.missing',
			'Missing handler',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			false,
			true,
			new TweakParams(),
			'runtime-handlers/core-does-not-exist.php'
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/not found/' );

		$this->compiler()->compile( array( $missing ) );
	}

	/**
	 * Generated source must parse. This is the same check RuntimeWriter makes
	 * before putting a file in place, run here against every shipped tweak.
	 *
	 * @return void
	 */
	public function test_generated_source_parses(): void {
		$compiler = $this->compiler();
		$tweaks   = array();

		foreach ( $this->registry()->all() as $definition ) {
			if ( TweakKind::CONFIG === $definition->kind ) {
				$tweaks[] = $definition->resolve();
			}
		}

		$source = $compiler->compile( $tweaks, self::REGISTRY_HASH );

		$this->assertNotSame( '', $source );

		$tokens = token_get_all( $source, TOKEN_PARSE );

		$this->assertNotEmpty( $tokens );
		$this->assertSame( T_OPEN_TAG, $tokens[0][0] );
	}

	/**
	 * The generated file refuses to run outside WordPress and can be switched
	 * off entirely.
	 *
	 * @return void
	 */
	public function test_generated_source_has_a_guard_and_a_kill_switch(): void {
		$source = $this->compiler()->compile( $this->tweaks( array( 'core.disable_emojis' ) ), self::REGISTRY_HASH );

		$this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $source );
		$this->assertStringContainsString( 'runtime-guard.php', $source );
		$this->assertStringContainsString( 'Debloater_Runtime_Guard::disabled()', $source );
		$this->assertStringContainsString( 'Debloater_Runtime_Guard::bypass_allowed()', $source );
		$this->assertStringContainsString( 'DO NOT EDIT', $source );
	}

	/**
	 * The header records what the file was built from, so a diff is explicable.
	 *
	 * @return void
	 */
	public function test_generated_source_records_its_inputs(): void {
		$compiler = $this->compiler();
		$tweaks   = $this->tweaks( array( 'core.disable_emojis' ) );
		$source   = $compiler->compile( $tweaks, self::REGISTRY_HASH );

		$this->assertStringContainsString( 'selection ' . $compiler->selectionHash( $tweaks ), $source );
		$this->assertStringContainsString( 'registry  ' . self::REGISTRY_HASH, $source );
	}

	/**
	 * BUILD-SPEC §17 Phase 1 requires byte-identical regeneration, which a
	 * timestamp in the file would break. The generation time belongs in
	 * runtime.lock (docs/DECISIONS.md D-0005).
	 *
	 * @return void
	 */
	public function test_generated_source_carries_no_timestamp(): void {
		$source = $this->compiler()->compile( $this->tweaks( array( 'core.disable_emojis' ) ), self::REGISTRY_HASH );

		$this->assertDoesNotMatchRegularExpression( '/\d{4}-\d{2}-\d{2}/', $source );
	}

	/**
	 * Parameter values are emitted as PHP literals, not interpolated text.
	 *
	 * @return void
	 */
	public function test_parameters_are_exported_as_literals(): void {
		$source = $this->compiler()->compile( array( $this->parameterisedTweak( 60 ) ), self::REGISTRY_HASH );

		$this->assertStringContainsString( "array( 'interval' => 60 )", $source );
	}

	/**
	 * A string parameter is quoted and escaped by var_export, so a value
	 * containing a quote cannot end the literal.
	 *
	 * @return void
	 */
	public function test_string_parameters_are_escaped(): void {
		$tweak = new Tweak(
			'core.disable_emojis',
			'Disable emojis',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			false,
			true,
			new TweakParams( array( 'label' => "it's ' ); evil(); //" ) ),
			'runtime-handlers/core-disable-emojis.php'
		);

		$source = $this->compiler()->compile( array( $tweak ), self::REGISTRY_HASH );

		$this->assertStringContainsString( "'it\\'s \\' ); evil(); //'", $source );
		$this->assertStringNotContainsString( "evil(); //'\n", $source );

		// The decisive check: whatever the value was, the result still parses,
		// and it parses as one statement rather than as injected code.
		$tokens = token_get_all( $source, TOKEN_PARSE );

		$this->assertNotEmpty( $tokens );
	}

	/**
	 * A list parameter is emitted element by element, each through var_export.
	 *
	 * @return void
	 */
	public function test_list_parameters_are_exported(): void {
		$tweak = new Tweak(
			'core.disable_emojis',
			'Disable emojis',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			false,
			true,
			new TweakParams( array( 'widgets' => array( 'dashboard_primary', 'dashboard_quick_press' ) ) ),
			'runtime-handlers/core-disable-emojis.php'
		);

		$source = $this->compiler()->compile( array( $tweak ), self::REGISTRY_HASH );

		$this->assertStringContainsString(
			"array( 'widgets' => array( 'dashboard_primary', 'dashboard_quick_press' ) )",
			$source
		);
	}

	/**
	 * A registry hash used in tests, so snapshots do not change when the real
	 * registry gains a tweak.
	 */
	private const REGISTRY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Compare against a stored snapshot, with machine-specific paths removed.
	 *
	 * @param string $name   Snapshot file name.
	 * @param string $actual Generated source.
	 * @return void
	 */
	private function assertMatchesSnapshot( string $name, string $actual ): void {
		$path       = DEBLOATER_TESTS_ROOT . '/tests/Fixtures/runtime/' . $name;
		$normalised = str_replace(
			str_replace( '\\', '/', DEBLOATER_TESTS_ROOT ),
			self::PLUGIN_DIR_TOKEN,
			$actual
		);

		if ( '1' === getenv( 'DEBLOATER_UPDATE_SNAPSHOTS' ) ) {
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}

			file_put_contents( $path, $normalised );
		}

		$this->assertFileExists(
			$path,
			'Snapshot missing; regenerate with DEBLOATER_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit'
		);

		$expected = file_get_contents( $path );

		$this->assertIsString( $expected );
		$this->assertSame(
			str_replace( "\r\n", "\n", $expected ),
			$normalised,
			$name . ' is stale. If the change is deliberate, regenerate with DEBLOATER_UPDATE_SNAPSHOTS=1.'
		);
	}

	/**
	 * A compiler pointed at this repository.
	 *
	 * @return Compiler
	 */
	private function compiler(): Compiler {
		return new Compiler( $this->context() );
	}

	/**
	 * A context whose plugin directory is this repository, so handler paths
	 * resolve to the real files.
	 *
	 * @return Context
	 */
	private function context(): Context {
		$root = str_replace( '\\', '/', DEBLOATER_TESTS_ROOT );

		return new Context(
			'https://example.test',
			$root . '/wp',
			$root . '/wp/wp-content',
			$root,
			'6.8.1',
			PHP_VERSION,
			'0.1.0',
			'cli'
		);
	}

	/**
	 * The real registry shipped in this repository.
	 *
	 * @return Registry
	 */
	private function registry(): Registry {
		return ( new Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();
	}

	/**
	 * Resolve tweaks by id from the shipped registry.
	 *
	 * @param array<int,string> $ids Tweak ids.
	 * @return array<int,Tweak>
	 */
	private function tweaks( array $ids ): array {
		$registry = $this->registry();
		$tweaks   = array();

		foreach ( $ids as $id ) {
			$tweaks[] = $registry->tweak( $id )->resolve();
		}

		return $tweaks;
	}

	/**
	 * A config tweak carrying an integer parameter.
	 *
	 * @param int $interval Interval value.
	 * @return Tweak
	 */
	private function parameterisedTweak( int $interval = 60 ): Tweak {
		return new Tweak(
			'core.disable_emojis',
			'Stands in for a parameterised tweak',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::LOW,
			false,
			true,
			new TweakParams( array( 'interval' => $interval ) ),
			'runtime-handlers/core-disable-emojis.php'
		);
	}
}
