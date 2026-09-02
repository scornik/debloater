<?php
/**
 * Tests for the registry loader and the shipped registry itself.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Registry\Loader;
use WPDebloat\Registry\Registry;
use WPDebloat\Registry\TweakDefinition;

/**
 * The registry is the input to every plan, so a broken one has to fail loudly
 * at load time rather than quietly produce a smaller plan.
 */
final class LoaderTest extends TestCase {

	/**
	 * Temporary directories created by a test, removed afterwards.
	 *
	 * @var array<int,string>
	 */
	private array $temporary = array();

	/**
	 * Clean up temporary registries.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->temporary as $directory ) {
			$this->removeDirectory( $directory );
		}

		$this->temporary = array();

		parent::tearDown();
	}

	/**
	 * The shipped registry loads, and holds the five tweaks Phase 1 specifies.
	 *
	 * @return void
	 */
	public function test_the_shipped_registry_loads(): void {
		$registry = $this->shippedRegistry();

		$this->assertSame(
			array(
				'core.disable_emojis',
				'core.disable_self_pingbacks',
				'core.remove_generator',
				'core.remove_rsd',
				'core.remove_shortlink',
			),
			$registry->ids()
		);
	}

	/**
	 * Every shipped tweak is a safe, reversible config tweak in this phase.
	 *
	 * BUILD-SPEC §15 lists them under "safe"; nothing destructive exists until
	 * Phase 10, and this test is what stops one arriving early by accident.
	 *
	 * @return void
	 */
	public function test_every_shipped_tweak_is_safe_and_reversible(): void {
		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			$this->assertSame( TweakKind::CONFIG, $definition->kind, $id );
			$this->assertSame( Risk::SAFE, $definition->risk, $id );
			$this->assertTrue( $definition->reversible, $id );
			$this->assertFalse( $definition->destructive, $id );
		}
	}

	/**
	 * Every shipped tweak names a handler file that exists.
	 *
	 * @return void
	 */
	public function test_every_handler_file_exists(): void {
		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			$this->assertFileExists( WPDEBLOAT_TESTS_ROOT . '/' . $definition->handler, $id );
		}
	}

	/**
	 * Every handler declares the two methods the contract requires
	 * (BUILD-SPEC §10, Contracts\HandlerInterface).
	 *
	 * @return void
	 */
	public function test_every_handler_declares_register_and_unregister(): void {
		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			$source = file_get_contents( WPDEBLOAT_TESTS_ROOT . '/' . $definition->handler );

			$this->assertIsString( $source );
			$this->assertStringContainsString( 'public static function register(', $source, $id );
			$this->assertStringContainsString( 'public static function unregister(', $source, $id );
		}
	}

	/**
	 * Handlers must stay free of anything that would drag WordPress internals or
	 * the plugin's own state into a front-end request (BUILD-SPEC §10).
	 *
	 * @return void
	 */
	public function test_handlers_read_no_options_and_no_database(): void {
		$forbidden = array( 'get_option(', 'update_option(', 'get_transient(', '$wpdb', 'namespace ', 'vendor/autoload' );

		$handlers = glob( WPDEBLOAT_TESTS_ROOT . '/runtime-handlers/*.php' );

		foreach ( false === $handlers ? array() : $handlers as $path ) {
			$source = file_get_contents( $path );

			$this->assertIsString( $source );

			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$source,
					basename( $path ) . ' must not contain ' . $needle
				);
			}
		}
	}

	/**
	 * The registry hash is stable across loads and changes when content does.
	 *
	 * @return void
	 */
	public function test_registry_hash_is_stable_and_content_derived(): void {
		$first  = $this->shippedRegistry()->hash();
		$second = $this->shippedRegistry()->hash();

		$this->assertSame( $first, $second );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );

		$directory = $this->temporaryRegistry(
			array(
				'core.example' => $this->definitionDocument( 'core.example' ),
			)
		);

		$one = ( new Loader( $directory ) )->load()->hash();

		$this->writeDefinition(
			$directory,
			'core.example',
			$this->definitionDocument( 'core.example', array( 'title' => 'A different title' ) )
		);

		$two = ( new Loader( $directory ) )->load()->hash();

		$this->assertNotSame( $one, $two, 'editing a definition must change the registry hash' );
	}

	/**
	 * Reordering keys inside a file must not change the hash: the hash describes
	 * the definitions, not their formatting.
	 *
	 * @return void
	 */
	public function test_registry_hash_ignores_key_order(): void {
		$document = $this->definitionDocument( 'core.example' );

		$directory = $this->temporaryRegistry( array( 'core.example' => $document ) );
		$ordered   = ( new Loader( $directory ) )->load()->hash();

		$this->writeDefinition( $directory, 'core.example', array_reverse( $document, true ) );

		$this->assertSame( $ordered, ( new Loader( $directory ) )->load()->hash() );
	}

	/**
	 * An empty registry is valid and hashes deterministically.
	 *
	 * @return void
	 */
	public function test_an_empty_registry_is_valid(): void {
		$registry = new Registry();

		$this->assertSame( 0, $registry->count() );
		$this->assertSame( array(), $registry->ids() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $registry->hash() );
	}

	/**
	 * A document that fails schema validation stops the load.
	 *
	 * @return void
	 */
	public function test_an_invalid_document_stops_the_load(): void {
		$directory = $this->temporaryRegistry( array() );

		file_put_contents(
			$directory . '/tweaks/core.broken.json',
			json_encode( array( 'id' => 'core.broken' ) )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/failed schema validation/' );

		( new Loader( $directory ) )->load();
	}

	/**
	 * Malformed JSON stops the load rather than being skipped.
	 *
	 * @return void
	 */
	public function test_malformed_json_stops_the_load(): void {
		$directory = $this->temporaryRegistry( array() );

		file_put_contents( $directory . '/tweaks/core.broken.json', '{ not json' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/not valid JSON/' );

		( new Loader( $directory ) )->load();
	}

	/**
	 * A definition in the wrong file is refused: the file name is how a reviewer
	 * finds a tweak, so it is part of the contract.
	 *
	 * @return void
	 */
	public function test_a_misnamed_file_is_refused(): void {
		$directory = $this->temporaryRegistry( array() );

		file_put_contents(
			$directory . '/tweaks/wrong-name.json',
			json_encode( $this->definitionDocument( 'core.example' ) )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be defined in core\.example\.json/' );

		( new Loader( $directory ) )->load();
	}

	/**
	 * A conflict naming a tweak that does not exist is an authoring error. Left
	 * unchecked it would turn a safety rule into a silent no-op.
	 *
	 * @return void
	 */
	public function test_a_dangling_conflict_is_refused(): void {
		$directory = $this->temporaryRegistry(
			array(
				'core.example' => $this->definitionDocument(
					'core.example',
					array( 'conflicts' => array( 'core.nonexistent' ) )
				),
			)
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/conflict with unknown tweak "core\.nonexistent"/' );

		( new Loader( $directory ) )->load();
	}

	/**
	 * The same applies to a requirement.
	 *
	 * @return void
	 */
	public function test_a_dangling_requirement_is_refused(): void {
		$directory = $this->temporaryRegistry(
			array(
				'core.example' => $this->definitionDocument(
					'core.example',
					array( 'requires' => array( 'core.nonexistent' ) )
				),
			)
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/requires unknown tweak/' );

		( new Loader( $directory ) )->load();
	}

	/**
	 * A fact predicate in `requires` is not a dangling reference; it is resolved
	 * against a scan, not against the registry.
	 *
	 * @return void
	 */
	public function test_a_fact_predicate_requirement_is_allowed(): void {
		$directory = $this->temporaryRegistry(
			array(
				'core.example' => $this->definitionDocument(
					'core.example',
					array( 'requires' => array( 'fact:plugins.detected.woocommerce=true' ) )
				),
			)
		);

		$registry = ( new Loader( $directory ) )->load();

		$this->assertSame( array(), $registry->tweak( 'core.example' )->requiredTweakIds() );
		$this->assertSame(
			array( 'fact:plugins.detected.woocommerce=true' ),
			$registry->tweak( 'core.example' )->requiredFactPredicates()
		);
	}

	/**
	 * Conflicts resolve in both directions, so one side declaring it is enough.
	 *
	 * @return void
	 */
	public function test_conflicts_are_symmetric(): void {
		$directory = $this->temporaryRegistry(
			array(
				'core.first'  => $this->definitionDocument( 'core.first', array( 'conflicts' => array( 'core.second' ) ) ),
				'core.second' => $this->definitionDocument( 'core.second' ),
			)
		);

		$registry = ( new Loader( $directory ) )->load();

		$this->assertSame( array( 'core.second' ), $registry->conflictsFor( 'core.first' ) );
		$this->assertSame( array( 'core.first' ), $registry->conflictsFor( 'core.second' ) );
	}

	/**
	 * A missing registry directory is an error, not an empty registry.
	 *
	 * @return void
	 */
	public function test_a_missing_registry_directory_is_refused(): void {
		$this->expectException( RuntimeException::class );

		new Loader( WPDEBLOAT_TESTS_ROOT . '/registry-does-not-exist' );
	}

	/**
	 * Asking for a tweak that does not exist is an error, not null.
	 *
	 * @return void
	 */
	public function test_an_unknown_tweak_id_is_refused(): void {
		$this->expectException( RuntimeException::class );

		$this->shippedRegistry()->tweak( 'core.nope' );
	}

	/**
	 * A config tweak whose handler is not in runtime-handlers/ is refused at the
	 * contract boundary, before code generation ever sees it.
	 *
	 * @return void
	 */
	public function test_a_config_handler_outside_runtime_handlers_is_refused(): void {
		$this->expectException( \WPDebloat\Contracts\ContractViolation::class );
		$this->expectExceptionMessageMatches( '/must live under runtime-handlers/' );

		TweakDefinition::fromArray( $this->definitionDocument( 'core.example', array( 'handler' => 'src/Evil.php' ) ) );
	}

	/**
	 * The registry shipped in this repository.
	 *
	 * @return Registry
	 */
	private function shippedRegistry(): Registry {
		return ( new Loader( WPDEBLOAT_TESTS_ROOT . '/registry' ) )->load();
	}

	/**
	 * A minimal valid tweak document.
	 *
	 * @param string              $id        Tweak id.
	 * @param array<string,mixed> $overrides Fields to override.
	 * @return array<string,mixed>
	 */
	private function definitionDocument( string $id, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => $id,
				'schema_version'  => 1,
				'title'           => 'An example tweak',
				'category'        => 'wordpress',
				'kind'            => 'config',
				'risk'            => 'safe',
				'base_confidence' => 0.9,
				'reversible'      => true,
				'destructive'     => false,
				'handler'         => 'runtime-handlers/core-disable-emojis.php',
				'params'          => array(),
				'description'     => 'Stands in for a real tweak in tests.',
			),
			$overrides
		);
	}

	/**
	 * Create a temporary registry directory with the shipped schemas.
	 *
	 * @param array<string,array<string,mixed>> $definitions Definitions keyed by id.
	 * @return string The registry directory path.
	 */
	private function temporaryRegistry( array $definitions ): string {
		$directory = sys_get_temp_dir() . '/wpdebloat-registry-' . bin2hex( random_bytes( 6 ) );

		mkdir( $directory . '/tweaks', 0777, true );
		mkdir( $directory . '/schemas', 0777, true );

		$schemas = glob( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/*.json' );

		foreach ( false === $schemas ? array() : $schemas as $schema ) {
			copy( $schema, $directory . '/schemas/' . basename( $schema ) );
		}

		foreach ( $definitions as $id => $document ) {
			$this->writeDefinition( $directory, $id, $document );
		}

		$this->temporary[] = $directory;

		return $directory;
	}

	/**
	 * Write one definition into a temporary registry.
	 *
	 * @param string              $directory Registry directory.
	 * @param string              $id        Tweak id.
	 * @param array<string,mixed> $document  Definition document.
	 * @return void
	 */
	private function writeDefinition( string $directory, string $id, array $document ): void {
		file_put_contents(
			$directory . '/tweaks/' . $id . '.json',
			(string) json_encode( $document, JSON_PRETTY_PRINT )
		);
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $directory Directory to remove.
	 * @return void
	 */
	private function removeDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$entries = scandir( $directory );

		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $directory );
	}
}
