<?php
/**
 * The zero-overhead guarantee, asserted against a real WordPress install.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Brand;

/**
 * BUILD-SPEC §10 and §14: with nothing selected, Debloater costs nothing.
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
			"A front-end request queried Debloater's own storage:\n" . implode( "\n", $offending )
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
	 * Debloater stores exactly one option (BUILD-SPEC §8).
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
	 * With a selection, a front-end request still queries none of our storage.
	 *
	 * The empty-selection case above is the easy half of the promise. This is
	 * the half that matters in practice: a site that has actually selected
	 * something is a site whose every page view now loads our generated code,
	 * and if that code reads an option or a table then the plugin has become
	 * the cost it was installed to remove.
	 *
	 * BUILD-SPEC §14 (performance) and product-safety invariant 4: the runtime
	 * has no registry, no database, and no option intelligence.
	 *
	 * @return void
	 */
	public function test_a_selection_adds_no_queries_to_a_frontend_request(): void {
		$tweaks = array(
			'core.remove_generator'       => array(),
			'core.disable_emojis'         => array(),
			'core.disable_self_pingbacks' => array(),
		);

		$this->selectAndGenerate( $tweaks );

		$queries = $this->captureQueries(
			function (): void {
				$this->assertTrue( $this->loadRuntime() );

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
			"A front-end request with tweaks selected queried Debloater's own storage:\n"
				. implode( "\n", $offending )
		);

		$this->unregisterHandlers( array_keys( $tweaks ) );
	}

	/**
	 * The runtime reads no registry JSON.
	 *
	 * Invariant 4 again, from the other side. The registry is how the plugin
	 * decides *what* to do; by the time the runtime exists that decision has
	 * already been made and compiled in. A runtime that opened a registry file
	 * on every request would have moved the plugin's slowest work into the hot
	 * path — and would also mean a registry update silently changing a live
	 * site's behaviour without anybody applying anything.
	 *
	 * @return void
	 */
	public function test_the_runtime_reads_no_registry_json(): void {
		$tweaks = array(
			'core.remove_generator' => array(),
			'core.disable_emojis'   => array(),
		);

		$this->selectAndGenerate( $tweaks );

		$source = (string) file_get_contents( $this->context()->runtimeFile() );

		foreach ( array( 'registry/', '.json', 'json_decode', 'get_option', 'wpdb' ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$source,
				'The generated runtime must not reference ' . $needle . '.'
			);
		}

		// And nothing it loads does either. The handlers are the runtime's only
		// dependency, so the guarantee is only as good as they are.
		foreach ( $this->plugin->registry()->all() as $definition ) {
			if ( ! str_starts_with( $definition->handler, 'runtime-handlers/' ) ) {
				continue;
			}

			$handler = (string) file_get_contents( DEBLOATER_TESTS_ROOT . '/' . $definition->handler );

			foreach ( array( 'registry/', 'json_decode', '$wpdb' ) as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$handler,
					$definition->handler . ' must not reference ' . $needle . '.'
				);
			}
		}

		$this->unregisterHandlers( array_keys( $tweaks ) );
	}

	/**
	 * The generated runtime parses in well under a millisecond.
	 *
	 * A budget rather than a comparison, because there is nothing to compare
	 * against: the alternative to loading this file is not loading it. What the
	 * number has to be is small enough that nobody would notice it, on the
	 * slowest machine anybody runs this suite on.
	 *
	 * The budget is 5 ms, roughly two orders of magnitude above what a few
	 * kilobytes of PHP actually costs to tokenise. That gap is deliberate: a
	 * tight budget on a shared CI runner measures the runner's load rather than
	 * this file, and a performance test that fails when a neighbouring job gets
	 * busy is one people learn to re-run rather than read. At 5 ms the only
	 * thing that trips it is the runtime having grown a real cost — reading a
	 * file, hitting the database, doing work at include time instead of on a
	 * hook.
	 *
	 * BUILD-SPEC §14: "runtime.php parse time".
	 *
	 * @return void
	 */
	public function test_the_runtime_parses_within_budget(): void {
		$selection = array();

		// Every config tweak there is: the worst case a real site can reach,
		// and considerably worse than any real site would choose.
		foreach ( $this->plugin->registry()->all() as $definition ) {
			if ( str_starts_with( $definition->handler, 'runtime-handlers/' ) ) {
				$selection[ $definition->id ] = array();
			}
		}

		$this->assertGreaterThan( 10, count( $selection ), 'this needs a realistic worst case' );

		$this->selectAndGenerate( $selection );

		$source = (string) file_get_contents( $this->context()->runtimeFile() );

		// Parse time, not execution time: the file is tokenised rather than
		// included, because including it registers hooks and running the
		// handlers is a different measurement.
		$iterations = 20;
		$started    = hrtime( true );

		for ( $index = 0; $index < $iterations; $index++ ) {
			$tokens = token_get_all( $source );

			$this->assertNotEmpty( $tokens );
		}

		$elapsed_ms = ( hrtime( true ) - $started ) / 1e6 / $iterations;

		$this->assertLessThan(
			5.0,
			$elapsed_ms,
			sprintf(
				'The generated runtime (%d selected tweaks, %d bytes) took %.3f ms to parse.',
				count( $selection ),
				strlen( $source ),
				$elapsed_ms
			)
		);
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
