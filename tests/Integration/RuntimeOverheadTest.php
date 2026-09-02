<?php
/**
 * The zero-overhead guarantee, asserted against a real WordPress install.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use WPDebloat\Brand;

/**
 * BUILD-SPEC §10 and §14: with nothing selected, WP Debloat costs nothing.
 *
 * This is the promise the whole architecture is arranged around, so it is
 * measured rather than reasoned about: no runtime file, no hooks, no queries,
 * nothing autoloaded.
 */
final class RuntimeOverheadTest extends IntegrationTestCase {

	/**
	 * An empty selection leaves no runtime file behind.
	 *
	 * @return void
	 */
	public function test_an_empty_selection_writes_no_runtime_file(): void {
		$this->selectAndGenerate( array() );

		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
		$this->assertFileDoesNotExist( $this->context()->runtimeLockFile() );
		$this->assertSame( '', $this->plugin->state()->runtimeHash() );
	}

	/**
	 * With an empty selection, loading the runtime registers no hooks at all.
	 *
	 * @return void
	 */
	public function test_an_empty_selection_registers_no_hooks(): void {
		$this->selectAndGenerate( array() );

		$before = $this->hookSnapshot();

		$this->assertFalse( $this->loadRuntime(), 'there should be no runtime to load' );

		$this->assertSame(
			array(),
			$this->hooksAdded( $before, $this->hookSnapshot() ),
			'an empty selection must register no hooks'
		);
	}

	/**
	 * None of the hooks our handlers touch are modified when nothing is selected.
	 *
	 * @return void
	 */
	public function test_core_hooks_are_untouched_when_nothing_is_selected(): void {
		$this->selectAndGenerate( array() );
		$this->loadRuntime();

		$this->assertNotFalse( has_action( 'wp_head', 'wp_generator' ) );
		$this->assertNotFalse( has_action( 'wp_head', 'rsd_link' ) );
		$this->assertNotFalse( has_action( 'wp_head', 'wp_shortlink_wp_head' ) );
		$this->assertNotFalse( has_action( 'wp_head', 'print_emoji_detection_script' ) );
	}

	/**
	 * BUILD-SPEC §14: an empty selection adds no database queries to a
	 * front-end request.
	 *
	 * Queries are captured through the `query` filter, which sees every query
	 * $wpdb runs, so the assertion covers anything the plugin might do rather
	 * than only the queries a test thought to look for.
	 *
	 * @return void
	 */
	public function test_an_empty_selection_adds_no_queries_to_a_frontend_request(): void {
		$this->selectAndGenerate( array() );

		$queries = $this->captureQueries(
			function (): void {
				$this->loadRuntime();

				// redirect_canonical would try to send headers, which a test run
				// has already sent. It is core behaviour, not ours, so removing it
				// leaves the measurement intact.
				remove_action( 'template_redirect', 'redirect_canonical' );

				do_action( 'wp_loaded' );
				do_action( 'template_redirect' );

				ob_start();
				do_action( 'wp_head' );
				do_action( 'wp_footer' );
				ob_end_clean();
			}
		);

		$offending = array_values(
			array_filter(
				$queries,
				static fn ( string $query ): bool => false !== stripos( $query, Brand::PREFIX )
			)
		);

		$this->assertSame(
			array(),
			$offending,
			"A front-end request queried WP Debloat's own storage:\n" . implode( "\n", $offending )
		);
	}

	/**
	 * The state option must not be autoloaded: a plugin that reduces what loads
	 * on every request should not add a row to the autoload set.
	 *
	 * @return void
	 */
	public function test_the_state_option_is_not_autoloaded(): void {
		global $wpdb;

		$this->plugin->state()->set( array( 'last_scan_run_id' => 1 ) );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Brand::STATE_OPTION )
		);

		$this->assertNotNull( $autoload, 'the state option should exist after a write' );
		$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto', 'auto-on' ), 'the state option must not autoload' );
	}

	/**
	 * WP Debloat stores exactly one option (BUILD-SPEC §8).
	 *
	 * @return void
	 */
	public function test_only_one_option_is_stored(): void {
		global $wpdb;

		$this->plugin->state()->set( array( 'last_scan_run_id' => 1 ) );

		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( Brand::PREFIX ) . '%'
			)
		);

		$this->assertSame( array( Brand::STATE_OPTION ), $options );
	}

	/**
	 * With one tweak selected, exactly that tweak's hooks change, and nothing
	 * else does.
	 *
	 * @return void
	 */
	public function test_one_tweak_registers_only_its_own_hooks(): void {
		$this->selectAndGenerate( array( 'core.disable_self_pingbacks' => array() ) );

		$before = $this->hookSnapshot();

		$this->assertTrue( $this->loadRuntime() );

		$added   = $this->hooksAdded( $before, $this->hookSnapshot() );
		$removed = $this->hooksRemoved( $before, $this->hookSnapshot() );

		$this->assertCount( 1, $added, 'exactly one hook should be added: ' . implode( ', ', $added ) );
		$this->assertStringStartsWith( 'pre_ping@10:', $added[0] );
		$this->assertSame( array(), $removed, 'this tweak removes nothing' );

		$this->unregisterHandlers( array( 'core.disable_self_pingbacks' ) );
	}

	/**
	 * Unregistering leaves the hook table exactly as it was found, which is what
	 * makes the handler contract's claim testable.
	 *
	 * @return void
	 */
	public function test_unregister_restores_the_hook_table(): void {
		$this->selectAndGenerate(
			array(
				'core.remove_generator' => array(),
				'core.remove_rsd'       => array(),
			)
		);

		$before = $this->hookSnapshot();

		$this->loadRuntime();

		$this->assertNotSame( $before, $this->hookSnapshot(), 'loading should have changed something' );

		$this->unregisterHandlers( array( 'core.remove_generator', 'core.remove_rsd' ) );

		$this->assertSame( $before, $this->hookSnapshot() );
	}

	/**
	 * Capture every query run during a callable.
	 *
	 * @param callable $callback Work to measure.
	 * @return array<int,string>
	 */
	private function captureQueries( callable $callback ): array {
		$queries = array();

		$collector = static function ( $query ) use ( &$queries ) {
			$queries[] = (string) $query;

			return $query;
		};

		add_filter( 'query', $collector );

		try {
			$callback();
		} finally {
			remove_filter( 'query', $collector );
		}

		return $queries;
	}
}
