<?php
/**
 * Shared base class for integration tests.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_UnitTestCase;
use Debloater\Apply\RuntimeLoader;
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
		$this->plugin->runtimeWriter()->remove();
	}

	/**
	 * Remove anything the test generated.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->plugin->runtimeWriter()->remove();
		$this->plugin->runtimeLoader()->uninstall();
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
	 * Select tweaks and regenerate the runtime, as an apply would.
	 *
	 * @param array<string,array<string,mixed>> $selection Tweak id to parameters.
	 * @return string The runtime hash.
	 */
	protected function selectAndGenerate( array $selection ): string {
		$this->plugin->state()->setSelection( $selection );

		return $this->plugin->regenerateRuntime();
	}

	/**
	 * Load the generated runtime the way the mu-plugin loader would.
	 *
	 * @return bool Whether a runtime was loaded.
	 */
	protected function loadRuntime(): bool {
		$runtime = $this->context()->runtimeFile();

		if ( ! is_readable( $runtime ) ) {
			return false;
		}

		require $runtime;

		return true;
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
		$compiler = $this->plugin->compiler();

		foreach ( $tweak_ids as $tweak_id ) {
			$class = $compiler->handlerClass( $tweak_id );

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
	 * Whether the mu-plugin loader is installed.
	 *
	 * @return bool
	 */
	protected function loaderInstalled(): bool {
		return $this->plugin->runtimeLoader()->isInstalled();
	}

	/**
	 * The loader mode the plugin reports.
	 *
	 * @return string
	 */
	protected function loaderMode(): string {
		return $this->plugin->runtimeLoader()->mode();
	}

	/**
	 * Assert the loader ended up in one of the two supported modes.
	 *
	 * BUILD-SPEC §10 allows either, and which one you get depends on whether
	 * mu-plugins is writable, so a test that demanded one would be asserting a
	 * property of the host rather than of the plugin.
	 *
	 * @param string $mode Reported mode.
	 * @return void
	 */
	protected function assertSupportedLoaderMode( string $mode ): void {
		$this->assertContains(
			$mode,
			array( RuntimeLoader::MODE_MU_PLUGIN, RuntimeLoader::MODE_FALLBACK, RuntimeLoader::MODE_NONE ),
			'The loader reported a mode that is not in the documented set.'
		);
	}
}
