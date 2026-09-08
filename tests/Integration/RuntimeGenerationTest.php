<?php
/**
 * Registering a selection directly, with no generated file in between.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Apply\Runtime;

/**
 * BUILD-SPEC §10, as it stands after `D-0070`.
 *
 * The file this class used to be about is gone. What replaced it is an
 * autoloaded option holding handler file names, and a `plugins_loaded` hook that
 * requires them and calls `register()`.
 *
 * The tests that went with the file went with it — byte-identical regeneration,
 * the lock's provenance, an edited runtime being detected, the writer refusing
 * to escape its directory, unparseable source being refused, the loader's
 * install modes. None of those things exist to be wrong any more.
 *
 * What survives is the part that was never really about the file: the same
 * selection produces the same registrations, an unknown tweak is skipped, the
 * handlers actually change the page, and nothing outside the plugin's own
 * handler directory can be loaded.
 */
final class RuntimeGenerationTest extends IntegrationTestCase {

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->unregisterHandlers(
			array( 'core.remove_generator', 'core.disable_emojis', 'core.heartbeat_interval' )
		);

		parent::tear_down();
	}

	/**
	 * A selection is stored as handler file names and validated parameters.
	 *
	 * @return void
	 */
	public function test_a_selection_is_stored_for_the_loader(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$stored = get_option( Runtime::OPTION );

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'handlers', $stored );
		$this->assertCount( 1, $stored['handlers'] );

		$this->assertSame( 'core-remove-generator.php', $stored['handlers'][0]['file'] );
		$this->assertSame(
			'Debloater_Handler_Core_Remove_Generator',
			$stored['handlers'][0]['class']
		);

		// A file name, never a path. The directory is supplied by the loader, so
		// a traversal cannot be expressed here even if something wrote one.
		$this->assertStringNotContainsString( '/', $stored['handlers'][0]['file'] );
	}

	/**
	 * The option is autoloaded, because the loader reads it on every request.
	 *
	 * @return void
	 */
	public function test_the_runtime_option_is_autoloaded(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Runtime::OPTION
			)
		);

		$this->assertContains(
			$autoload,
			array( 'yes', 'on', 'auto', 'auto-on' ),
			'the loader reads this on every request; a query per request is the thing being avoided'
		);
	}

	/**
	 * The same selection produces the same stored result.
	 *
	 * Determinism mattered when it decided a file's bytes and a hash. It still
	 * matters: two sites with the same selection should register the same
	 * handlers in the same order, because a handler that behaves differently
	 * depending on what registered before it is a bug to reproduce rather than
	 * to shuffle.
	 *
	 * @return void
	 */
	public function test_storing_twice_produces_the_same_thing(): void {
		$selection = array(
			'core.heartbeat_interval' => array( 'interval' => 60 ),
			'core.remove_generator'   => array(),
		);

		$this->selectAndGenerate( $selection );
		$first = get_option( Runtime::OPTION );

		$this->selectAndGenerate( $selection );
		$second = get_option( Runtime::OPTION );

		$this->assertSame( $first, $second );

		// Sorted by tweak id, not by the order they were selected in.
		$this->assertSame(
			array( 'core-heartbeat-interval.php', 'core-remove-generator.php' ),
			array_column( $first['handlers'], 'file' )
		);
	}

	/**
	 * Emptying the selection leaves an empty list, not an absent option.
	 *
	 * An absent option is a `get_option()` miss, and a miss is a query on every
	 * request until something writes it again — which is exactly the cost this
	 * plugin exists to remove from other people's sites.
	 *
	 * @return void
	 */
	public function test_emptying_the_selection_keeps_the_option(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );
		$this->selectAndGenerate( array() );

		$stored = get_option( Runtime::OPTION );

		$this->assertIsArray( $stored );
		$this->assertSame( array(), $stored['handlers'] );
		$this->assertNotFalse( get_option( Runtime::OPTION, false ) );
	}

	/**
	 * A tweak the registry does not know is skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_an_unknown_tweak_in_the_selection_is_skipped(): void {
		$this->selectAndGenerate(
			array(
				'core.remove_generator' => array(),
				'not.a_real_tweak'      => array(),
			)
		);

		$stored = get_option( Runtime::OPTION );

		$this->assertCount( 1, $stored['handlers'] );
		$this->assertSame( 'core-remove-generator.php', $stored['handlers'][0]['file'] );
	}

	/**
	 * Nothing outside the plugin's handler directory can be loaded.
	 *
	 * The compiler got this from a `realpath()` check when it generated the
	 * file. There is no generation step, so the check moved to where the value
	 * is used — and it is stricter than it was, because the stored value is
	 * matched against a file-name pattern rather than resolved as a path.
	 *
	 * The option is written to directly here, which is the point: this is what
	 * happens if something that is not this plugin gets to write that row.
	 *
	 * @return void
	 */
	public function test_a_planted_path_is_refused(): void {
		foreach ( array( '../../../wp-config.php', '/etc/passwd', 'core-remove-generator.php/../x.php', '' ) as $planted ) {
			update_option(
				Runtime::OPTION,
				array(
					'handlers' => array(
						array(
							'file'   => $planted,
							'class'  => 'Debloater_Handler_Core_Remove_Generator',
							'params' => array(),
						),
					),
				),
				true
			);

			$this->assertSame(
				0,
				$this->plugin->runtime()->load(),
				sprintf( '"%s" was not refused', $planted )
			);
		}
	}

	/**
	 * A class name that is not a handler is refused too.
	 *
	 * @return void
	 */
	public function test_a_planted_class_is_refused(): void {
		update_option(
			Runtime::OPTION,
			array(
				'handlers' => array(
					array(
						'file'   => 'core-remove-generator.php',
						'class'  => 'wp_die',
						'params' => array(),
					),
				),
			),
			true
		);

		$this->assertSame( 0, $this->plugin->runtime()->load() );
	}

	/**
	 * The selection actually changes the page.
	 *
	 * The end of the whole chain, and the only test here that would notice if
	 * everything above were right and the handlers still did nothing.
	 *
	 * @return void
	 */
	public function test_a_registered_handler_removes_the_generator_tag(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->assertStringContainsString( '<meta name="generator"', $this->headOutput() );

		$this->assertSame( 1, $this->plugin->runtime()->load() );

		$this->assertStringNotContainsString( '<meta name="generator"', $this->headOutput() );
	}

	/**
	 * Deactivation stops registration but keeps the selection.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_the_runtime_but_keeps_the_selection(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->plugin->deactivate();

		$stored = get_option( Runtime::OPTION );

		$this->assertSame( array(), $stored['handlers'] );
		$this->assertSame(
			array( 'core.remove_generator' ),
			array_keys( $this->plugin->state()->selection() ),
			'deactivating is not uninstalling'
		);
	}

	/**
	 * `wp_head()` output, for the handlers that filter it.
	 *
	 * @return string
	 */
	private function headOutput(): string {
		ob_start();
		wp_head();

		return (string) ob_get_clean();
	}
}
