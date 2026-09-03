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
	 * The registry holds exactly the MVP tweak set from BUILD-SPEC §15.
	 *
	 * Pinned as a list rather than a count, so an accidental addition is a
	 * failure with a name rather than an off-by-one.
	 *
	 * @return void
	 */
	public function test_the_shipped_registry_holds_the_mvp_tweak_set(): void {
		$this->assertSame(
			array(
				'admin.hide_update_nags_non_admins',
				'admin.remove_dashboard_widgets',
				'admin.remove_welcome_panel',
				'admin.remove_wp_news_widget',
				'admin.suppress_promo_notices',
				'core.disable_dashicons_guests',
				'core.disable_embeds',
				'core.disable_emojis',
				'core.disable_self_pingbacks',
				'core.heartbeat_interval',
				'core.limit_revisions',
				'core.remove_generator',
				'core.remove_jquery_migrate',
				'core.remove_rsd',
				'core.remove_shortlink',
				'db.autoload_off',
				'db.clean_auto_drafts',
				'db.clean_expired_transients',
				'db.clean_orphan_meta',
				'db.clean_revisions',
				'db.delete_spam_comments',
				'db.empty_trash',
			),
			$this->shippedRegistry()->ids()
		);
	}

	/**
	 * Every tweak carries the risk BUILD-SPEC §15 assigns it.
	 *
	 * Asserted per tweak rather than as a blanket rule, because the risk level
	 * is what decides whether a tweak can reach "Fix Safe Issues" (§7.4). A
	 * tweak quietly promoted from medium to safe would change what a single
	 * click does.
	 *
	 * @return void
	 */
	public function test_every_tweak_carries_its_specified_risk(): void {
		$expected = array(
			'core.remove_generator'             => Risk::SAFE,
			'core.remove_rsd'                   => Risk::SAFE,
			'core.remove_shortlink'             => Risk::SAFE,
			'core.disable_emojis'               => Risk::SAFE,
			'core.disable_self_pingbacks'       => Risk::SAFE,
			'core.disable_embeds'               => Risk::SAFE,
			'core.heartbeat_interval'           => Risk::LOW,
			'core.limit_revisions'              => Risk::LOW,
			'db.clean_expired_transients'       => Risk::LOW,
			'core.disable_dashicons_guests'     => Risk::MEDIUM,
			'core.remove_jquery_migrate'        => Risk::MEDIUM,

			// Phase 10. The three that delete content a person might miss are
			// medium; the two that delete things already judged disposable —
			// an abandoned auto-draft, a comment marked as spam — are low.
			'db.clean_revisions'                => Risk::MEDIUM,
			'db.empty_trash'                    => Risk::MEDIUM,
			'db.clean_orphan_meta'              => Risk::MEDIUM,
			'db.clean_auto_drafts'              => Risk::LOW,
			'db.delete_spam_comments'           => Risk::LOW,
			'db.autoload_off'                   => Risk::LOW,

			// Phase 12. Removing something from your own dashboard is about as
			// safe as a change gets; hiding another plugin's notices is not,
			// because the same hook carries its warnings.
			'admin.remove_dashboard_widgets'    => Risk::SAFE,
			'admin.remove_welcome_panel'        => Risk::SAFE,
			'admin.remove_wp_news_widget'       => Risk::SAFE,
			'admin.hide_update_nags_non_admins' => Risk::SAFE,
			'admin.suppress_promo_notices'      => Risk::MEDIUM,
		);

		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			$this->assertArrayHasKey( $id, $expected, $id . ' is not in the MVP set' );
			$this->assertSame( $expected[ $id ], $definition->risk, $id );
		}
	}

	/**
	 * Only the operations that delete rows are destructive, and every one of
	 * them is reversible.
	 *
	 * BUILD-SPEC §15 said "nothing destructive" for the MVP set; Phase 10 adds
	 * five that are, with the Level B machinery that makes them recoverable. The
	 * test that once stopped one arriving early now states exactly which ones
	 * are allowed to be destructive — a list, not a blanket, so a new tweak
	 * cannot join it silently.
	 *
	 * @return void
	 */
	public function test_only_the_deleting_operations_are_destructive(): void {
		$allowed = array(
			'db.clean_revisions',
			'db.clean_auto_drafts',
			'db.empty_trash',
			'db.delete_spam_comments',
			'db.clean_orphan_meta',
		);

		$destructive = array();

		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			$this->assertTrue( $definition->reversible, $id . ' must be reversible' );

			if ( $definition->destructive ) {
				$destructive[] = $id;
			}
		}

		sort( $destructive, SORT_STRING );
		sort( $allowed, SORT_STRING );

		$this->assertSame( $allowed, $destructive, 'the set of destructive tweaks has changed' );
	}

	/**
	 * A config tweak is never destructive: it registers hooks and deletes
	 * nothing.
	 *
	 * @return void
	 */
	public function test_no_config_tweak_is_destructive(): void {
		foreach ( $this->configTweaks() as $id => $definition ) {
			$this->assertFalse( $definition->destructive, $id . ' is a config tweak and cannot delete anything' );
		}
	}

	/**
	 * Every data tweak is one of the seven that exist, and names an operation
	 * class rather than a handler file.
	 *
	 * The MVP had one, chosen to prove the Level B recovery path where a mistake
	 * cost nothing. Phase 10 adds six more, and this is the list.
	 *
	 * @return void
	 */
	public function test_the_data_tweaks_are_the_expected_seven(): void {
		$data = array();

		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			if ( TweakKind::DATA !== $definition->kind ) {
				continue;
			}

			$data[] = $id;

			$this->assertStringStartsWith(
				'WPDebloat\\Apply\\DataOperations\\',
				$definition->handler,
				$id . ' must name a data operation class'
			);
		}

		sort( $data, SORT_STRING );

		$this->assertSame(
			array(
				'db.autoload_off',
				'db.clean_auto_drafts',
				'db.clean_expired_transients',
				'db.clean_orphan_meta',
				'db.clean_revisions',
				'db.delete_spam_comments',
				'db.empty_trash',
			),
			$data
		);
	}

	/**
	 * Every config tweak names a handler file that exists.
	 *
	 * @return void
	 */
	public function test_every_config_handler_file_exists(): void {
		foreach ( $this->configTweaks() as $id => $definition ) {
			$this->assertFileExists( WPDEBLOAT_TESTS_ROOT . '/' . $definition->handler, $id );
		}
	}

	/**
	 * Every config handler declares the two methods the contract requires
	 * (BUILD-SPEC §10, Contracts\HandlerInterface).
	 *
	 * @return void
	 */
	public function test_every_config_handler_declares_register_and_unregister(): void {
		foreach ( $this->configTweaks() as $id => $definition ) {
			$source = file_get_contents( WPDEBLOAT_TESTS_ROOT . '/' . $definition->handler );

			$this->assertIsString( $source );
			$this->assertStringContainsString( 'public static function register(', $source, $id );
			$this->assertStringContainsString( 'public static function unregister(', $source, $id );
		}
	}

	/**
	 * Every data tweak names a class in the DataOperations namespace.
	 *
	 * The classes themselves arrive in Phase 5 along with ApplyManager; until
	 * then this asserts the shape, so a typo in the registry is caught now
	 * rather than at apply time.
	 *
	 * @return void
	 */
	public function test_every_data_handler_names_a_data_operation_class(): void {
		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			if ( TweakKind::DATA !== $definition->kind ) {
				continue;
			}

			$this->assertStringStartsWith( 'WPDebloat\\Apply\\DataOperations\\', $definition->handler, $id );
			$this->assertMatchesRegularExpression(
				'/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/',
				$definition->handler,
				$id . ' must name a class, not a path'
			);
		}
	}

	/**
	 * The config tweaks in the shipped registry.
	 *
	 * @return array<string,TweakDefinition>
	 */
	private function configTweaks(): array {
		$config = array();

		foreach ( $this->shippedRegistry()->all() as $id => $definition ) {
			if ( TweakKind::CONFIG === $definition->kind ) {
				$config[ $id ] = $definition;
			}
		}

		return $config;
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
