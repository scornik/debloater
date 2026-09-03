<?php
/**
 * The two registry tables Phase 11 adds.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Debloater\Analyze\Rules;
use Debloater\Registry\HostOptimizer;
use Debloater\Registry\Loader;
use Debloater\Registry\PluginCategories;
use Debloater\Registry\Registry;

/**
 * BUILD-SPEC §17 Phase 11.
 *
 * The rest of the registry is one document per object. These two are tables —
 * a single file holding a lookup — so they have their own loading path, and it
 * needs the same refusals the others have: an invalid document stops the load
 * rather than being partly understood.
 */
final class RegistryTablesTest extends TestCase {

	/**
	 * The shipped tables load and are not empty.
	 *
	 * @return void
	 */
	public function test_the_shipped_tables_load(): void {
		$registry = $this->registry();

		$this->assertGreaterThan( 30, $registry->pluginCategories()->count() );
		$this->assertNotSame( array(), $registry->hostOptimizers() );
	}

	/**
	 * Every finding id an optimizer claims to have a setting for is a finding
	 * the rule set can actually produce.
	 *
	 * This one earns its place. The first draft of `host-optimizers.json` named
	 * `wp.emojis.enabled`, which is the *fact* key; the finding is
	 * `wp.emojis.loaded`. Nothing failed — the lookup simply never matched, and
	 * the whole feature was a no-op that looked implemented.
	 *
	 * @return void
	 */
	public function test_every_covered_finding_id_is_real(): void {
		$known = array();

		foreach ( Rules::all() as $rule ) {
			$known[] = $rule->findingId();
		}

		foreach ( $this->registry()->hostOptimizers() as $optimizer ) {
			foreach ( $optimizer->covers as $finding_id ) {
				$this->assertContains(
					$finding_id,
					$known,
					sprintf(
						'Optimizer "%s" claims a setting for "%s", which no rule produces. A covers entry that matches nothing is a feature that silently does not exist.',
						$optimizer->id,
						$finding_id
					)
				);
			}
		}
	}

	/**
	 * Every plugin in the category table names a category that exists.
	 *
	 * @return void
	 */
	public function test_every_plugin_names_a_real_category(): void {
		$categories = $this->registry()->pluginCategories();

		foreach ( array( 'cache', 'seo', 'security', 'image', 'forms', 'backup', 'analytics' ) as $expected ) {
			$this->assertContains(
				$expected,
				$categories->categoryIds(),
				'BUILD-SPEC §17 Phase 11 names this category'
			);
		}
	}

	/**
	 * A plugin claiming a category nobody defined is refused at construction.
	 *
	 * @return void
	 */
	public function test_an_undefined_category_is_refused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/which is not defined/' );

		new PluginCategories(
			array(
				'cache' => array(
					'label' => 'Page caching',
					'note'  => '',
				),
			),
			array( 'wordpress-seo' => 'seo' )
		);
	}

	/**
	 * Grouping produces one row per classified plugin, sorted, and drops the
	 * rest.
	 *
	 * @return void
	 */
	public function test_grouping_is_by_row_and_deterministic(): void {
		$rows = $this->registry()->pluginCategories()->rows(
			array( 'wordpress-seo', 'litespeed-cache', 'something-nobody-classified', 'seo-by-rank-math' )
		);

		$this->assertCount( 3, $rows, 'the unclassified plugin is dropped, not guessed at' );

		$this->assertSame(
			array( 'litespeed-cache', 'seo-by-rank-math', 'wordpress-seo' ),
			array_column( $rows, 'plugin' ),
			'rows sort by category then plugin, so a scan is reproducible'
		);

		$this->assertSame( array( 'cache', 'seo', 'seo' ), array_column( $rows, 'category' ) );
		$this->assertSame( 'Page caching', $rows[0]['label'] );

		$this->assertSame(
			array( 'plugin', 'category', 'label' ),
			array_keys( $rows[0] ),
			'a row carries identifiers and a name, never reasoning'
		);
	}

	/**
	 * An optimizer that covers nothing is refused.
	 *
	 * Its presence would change nothing, and a registry entry that does nothing
	 * is worse than no entry: it looks like the case is handled.
	 *
	 * @return void
	 */
	public function test_an_optimizer_that_covers_nothing_is_refused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/covers nothing/' );

		new HostOptimizer( 'empty', 'Empty', HostOptimizer::SIGNAL_DETECTOR, 'empty', array(), 'nowhere' );
	}

	/**
	 * A signal type nothing knows how to evaluate is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_signal_type_is_refused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/unknown signal type/' );

		new HostOptimizer( 'guess', 'Guess', 'vibes', 'anything', array( 'wp.emojis.loaded' ), 'nowhere' );
	}

	/**
	 * Two optimizers with one id is refused.
	 *
	 * @return void
	 */
	public function test_a_duplicate_optimizer_id_is_refused(): void {
		$optimizer = new HostOptimizer(
			'twice',
			'Twice',
			HostOptimizer::SIGNAL_DETECTOR,
			'twice',
			array( 'wp.emojis.loaded' ),
			'nowhere'
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Duplicate optimizer id/' );

		new Registry( array(), array(), array(), array(), null, array( $optimizer, $optimizer ) );
	}

	/**
	 * The tables are part of the registry hash.
	 *
	 * A plan's determinism claim is "same facts, same profile, same registry".
	 * A category map that could change without changing the hash would break
	 * that claim quietly.
	 *
	 * @return void
	 */
	public function test_the_tables_are_part_of_the_registry_hash(): void {
		$without = new Registry();

		$with_categories = new Registry(
			array(),
			array(),
			array(),
			array(),
			new PluginCategories(
				array(
					'cache' => array(
						'label' => 'Page caching',
						'note'  => 'Two fight.',
					),
				),
				array( 'wp-rocket' => 'cache' )
			)
		);

		$with_optimizer = new Registry(
			array(),
			array(),
			array(),
			array(),
			null,
			array(
				new HostOptimizer(
					'one',
					'One',
					HostOptimizer::SIGNAL_HOST_VENDOR,
					'siteground',
					array( 'wp.emojis.loaded' ),
					'somewhere'
				),
			)
		);

		$this->assertNotSame( $without->hash(), $with_categories->hash() );
		$this->assertNotSame( $without->hash(), $with_optimizer->hash() );
		$this->assertNotSame( $with_categories->hash(), $with_optimizer->hash() );
	}

	/**
	 * A registry with no tables at all is valid and yields empty ones.
	 *
	 * @return void
	 */
	public function test_a_registry_without_the_tables_is_valid(): void {
		$directory = $this->emptyRegistry();

		try {
			$loader = new Loader( $directory );

			$this->assertSame( 0, $loader->loadPluginCategories()->count() );
			$this->assertSame( array(), $loader->loadHostOptimizers() );
		} finally {
			$this->remove( $directory );
		}
	}

	/**
	 * A table that does not match its schema stops the load.
	 *
	 * @return void
	 */
	public function test_an_invalid_table_is_refused(): void {
		$directory = $this->emptyRegistry();

		try {
			file_put_contents(
				$directory . '/plugin-categories.json',
				(string) json_encode( array( 'plugins' => array( 'wordpress-seo' => 'seo' ) ) )
			);

			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessageMatches( '/plugin-categories\.json is invalid/' );

			( new Loader( $directory ) )->loadPluginCategories();
		} finally {
			$this->remove( $directory );
		}
	}

	/**
	 * A registry directory with the schemas and nothing else.
	 *
	 * @return string
	 */
	private function emptyRegistry(): string {
		$directory = sys_get_temp_dir() . '/debloater-tables-' . bin2hex( random_bytes( 6 ) );

		mkdir( $directory . '/schemas', 0777, true );

		$schemas = glob( DEBLOATER_TESTS_ROOT . '/registry/schemas/*.json' );

		foreach ( is_array( $schemas ) ? $schemas : array() as $schema ) {
			copy( $schema, $directory . '/schemas/' . basename( $schema ) );
		}

		return $directory;
	}

	/**
	 * Remove a temporary directory.
	 *
	 * @param string $directory Directory to remove.
	 * @return void
	 */
	private function remove( string $directory ): void {
		$entries = glob( $directory . '/{,*/}*', GLOB_BRACE );

		foreach ( array_reverse( is_array( $entries ) ? $entries : array() ) as $entry ) {
			if ( is_dir( $entry ) ) {
				rmdir( $entry );
			} else {
				unlink( $entry );
			}
		}

		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}

	/**
	 * The shipped registry.
	 *
	 * @return Registry
	 */
	private function registry(): Registry {
		return ( new Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();
	}
}
