<?php
/**
 * Shared base class for integration tests.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_UnitTestCase;
use Debloater\Contracts\Context;
use Debloater\Plugin;

/**
 * Test case with the plugin's services to hand and a clean slate each time.
 *
 * Integration tests write real files into wp-content and register real hooks, so
 * each test tears both down. A leaked runtime.php would silently change the
 * result of the next test, which is exactly the class of bug these tests exist
 * to catch.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {

	/**
	 * The booted plugin.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * Set up a clean plugin state.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$plugin = Plugin::instance();

		if ( null === $plugin ) {
			$this->fail( 'The plugin did not boot; check tests/bootstrap-integration.php.' );
		}

		$this->plugin = $plugin;
		$this->plugin->resetServices();

		$this->plugin->state()->delete();
		$this->plugin->runtime()->clear();
	}

	/**
	 * Remove anything the test generated.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->plugin->runtime()->clear();
		$this->plugin->state()->delete();
		$this->plugin->resetServices();

		parent::tear_down();
	}

	/**
	 * The site context.
	 *
	 * @return Context
	 */
	protected function context(): Context {
		return $this->plugin->context();
	}

	/**
	 * Select tweaks and store the resolved selection, as an apply would.
	 *
	 * @param array<string,array<string,mixed>> $selection Tweak id to parameters.
	 * @return int How many handlers the selection resolves to.
	 */
	protected function selectAndGenerate( array $selection ): int {
		$this->plugin->state()->setSelection( $selection );

		return $this->plugin->regenerateRuntime();
	}

	/**
	 * Register the selection the way `plugins_loaded` would.
	 *
	 * @return bool Whether anything registered.
	 */
	protected function loadRuntime(): bool {
		return $this->plugin->runtime()->load() > 0;
	}

	/**
	 * Unregister every handler class the given tweak ids compile to.
	 *
	 * Handlers register global hooks, which outlive a test. Calling unregister()
	 * is also how the handler contract's "removes exactly what register() added"
	 * claim gets exercised on every test that loads a runtime.
	 *
	 * @param array<int,string> $tweak_ids Tweak ids.
	 * @return void
	 */
	protected function unregisterHandlers( array $tweak_ids ): void {
		$runtime = $this->plugin->runtime();

		foreach ( $tweak_ids as $tweak_id ) {
			$class = $runtime->handlerClass( $tweak_id );

			if ( class_exists( $class, false ) && method_exists( $class, 'unregister' ) ) {
				$class::unregister();
			}
		}
	}

	/**
	 * A snapshot of every registered hook, for before-and-after comparison.
	 *
	 * Keyed by "hook@priority:callback" so a difference names the exact callback
	 * that appeared or vanished rather than just a count.
	 *
	 * @return array<int,string>
	 */
	protected function hookSnapshot(): array {
		global $wp_filter;

		$entries = array();

		foreach ( $wp_filter as $hook => $registry ) {
			if ( ! $registry instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $registry->callbacks as $priority => $callbacks ) {
				foreach ( array_keys( $callbacks ) as $identifier ) {
					$entries[] = $hook . '@' . $priority . ':' . $identifier;
				}
			}
		}

		sort( $entries, SORT_STRING );

		return $entries;
	}

	/**
	 * Hooks present in the second snapshot but not the first.
	 *
	 * @param array<int,string> $before Snapshot taken before.
	 * @param array<int,string> $after  Snapshot taken after.
	 * @return array<int,string>
	 */
	protected function hooksAdded( array $before, array $after ): array {
		return array_values( array_diff( $after, $before ) );
	}

	/**
	 * Hooks present in the first snapshot but not the second.
	 *
	 * @param array<int,string> $before Snapshot taken before.
	 * @param array<int,string> $after  Snapshot taken after.
	 * @return array<int,string>
	 */
	protected function hooksRemoved( array $before, array $after ): array {
		return array_values( array_diff( $before, $after ) );
	}

	/**
	 * Count the queries a callable makes.
	 *
	 * @param callable $callback Work to measure.
	 * @return int
	 */
	protected function countQueries( callable $callback ): int {
		global $wpdb;

		$before = $wpdb->num_queries;

		$callback();

		return $wpdb->num_queries - $before;
	}

	/**
	 * The handler list the loader would read.
	 *
	 * Replaces the "does runtime.php exist / what are its bytes" idiom this
	 * suite used everywhere. The question was never really about a file: it was
	 * whether the selection resolves to anything, and to the same thing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function storedHandlers(): array {
		$stored = get_option( \Debloater\Apply\Runtime::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['handlers'] ) || ! is_array( $stored['handlers'] ) ) {
			return array();
		}

		return $stored['handlers'];
	}

	/**
	 * The stored handler list as a comparable string.
	 *
	 * @return string
	 */
	protected function storedHandlersDigest(): string {
		return (string) wp_json_encode( $this->storedHandlers() );
	}
}
