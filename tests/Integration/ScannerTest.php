<?php
/**
 * The scanners, against a real WordPress install with seeded data.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use WPDebloat\Contracts\RunType;
use WPDebloat\Registry\SchemaValidator;
use WPDebloat\Scan\ScanRunner;
use WPDebloat\Scan\Scanners\DatabaseScanner;

/**
 * BUILD-SPEC §5 and §17 Phase 2.
 *
 * Counting is only worth testing against data that exists, so each test seeds
 * exactly what it measures and asserts the count with no tolerance. A scanner
 * that is approximately right is a scanner whose findings cannot be trusted.
 */
final class ScannerTest extends IntegrationTestCase {

	/**
	 * Every fact a real scan produces validates against the schema.
	 *
	 * BUILD-SPEC §5 makes unknown keys a failure, so this also proves no
	 * scanner has quietly invented a key without declaring it.
	 *
	 * @return void
	 */
	public function test_a_real_scan_validates_against_the_fact_schema(): void {
		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts;

		$validator = SchemaValidator::fromFile( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/fact.schema.json' );

		$violations = $validator->validate( $facts->toArray() );

		$this->assertSame(
			array(),
			$violations,
			"A real scan produced facts the schema rejects:\n" . implode( "\n", array_map( 'strval', $violations ) )
		);
	}

	/**
	 * Facts carry no opinions (BUILD-SPEC §2).
	 *
	 * The scanner layer must not be able to grade what it sees. This looks for
	 * the vocabulary of judgement in every string a scan produces.
	 *
	 * @return void
	 */
	public function test_facts_contain_no_opinions(): void {
		$opinionated = array(
			'should',
			'recommend',
			'unnecessary',
			'bloat',
			'excessive',
			'too many',
			'aggressive',
			'safe to',
			'you can',
			'consider',
			'improve',
			'optimi',
		);

		$facts = $this->plugin->scanRunner()->collect( $this->context() )->facts->toArray();

		// Plugin and theme names are other people's product names, echoed
		// verbatim. Searching them for opinion words would say nothing about
		// whether this plugin graded anything — and "WP Debloat" itself is in
		// the list. The Phase 11 facts are the same case twice over: they carry
		// plugin slugs, and the products they name include one called an
		// optimizer, which is what its author calls it rather than a verdict of
		// ours.
		foreach ( array( 'plugins.active', 'plugins.inactive', 'plugins.meta', 'plugins.detected', 'plugins.categories', 'plugins.host_optimizers', 'theme.active', 'theme.parent' ) as $observed_name ) {
			unset( $facts[ $observed_name ] );
		}

		$encoded = strtolower( (string) wp_json_encode( $facts ) );

		// The exemptions above are for names, not for a licence to editorialise
		// inside them. Nothing in a fact set may be a sentence: prose belongs to
		// the analyzer, and a fact that explains itself is a fact that has
		// started grading.
		foreach ( $this->plugin->scanRunner()->collect( $this->context() )->facts->toArray() as $key => $value ) {
			foreach ( is_array( $value ) ? $value : array() as $row ) {
				foreach ( is_array( $row ) ? $row : array() as $field => $text ) {
					if ( is_string( $text ) ) {
						$this->assertStringNotContainsString(
							'. ',
							$text,
							sprintf( '%s[%s] reads like prose. Facts are observations.', $key, (string) $field )
						);
					}
				}
			}
		}

		foreach ( $opinionated as $word ) {
			$this->assertStringNotContainsString(
				$word,
				$encoded,
				sprintf( 'A fact contained the word "%s". Scanners report; the analyzer judges.', $word )
			);
		}
	}

	/**
	 * A scan of a clean install completes well inside the phase's budget.
	 *
	 * @return void
	 */
	public function test_a_scan_completes_inside_the_budget(): void {
		$result = $this->plugin->scanRunner()->collect( $this->context() );

		$this->assertLessThan( 5000, $result->elapsed_ms, 'BUILD-SPEC §17 Phase 2 exit: a scan should take under 5 s' );
		$this->assertSame( array(), $result->over_budget );
		$this->assertSame( array(), $result->failed );
	}

	/**
	 * Every scanner reports a timing, so a slow site can be diagnosed.
	 *
	 * @return void
	 */
	public function test_every_scanner_reports_a_timing(): void {
		$result = $this->plugin->scanRunner()->collect( $this->context() );

		$this->assertCount( count( $this->plugin->scanRunner()->scanners() ), $result->timings );
	}

	/**
	 * Revisions are counted exactly.
	 *
	 * @return void
	 */
	public function test_revisions_are_counted(): void {
		$before = $this->facts()->value( 'db.revisions.count' );

		$post_id = self::factory()->post->create( array( 'post_content' => 'one' ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'two',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'three',
			)
		);

		$created = count( wp_get_post_revisions( $post_id ) );

		$this->assertGreaterThan( 0, $created, 'the fixture must actually create revisions' );
		$this->assertSame( $before + $created, $this->facts()->value( 'db.revisions.count' ) );
	}

	/**
	 * Trashed posts and auto-drafts are counted separately.
	 *
	 * @return void
	 */
	public function test_trash_and_autodrafts_are_counted(): void {
		$before_trash = $this->facts()->value( 'db.trash.count' );
		$before_draft = $this->facts()->value( 'db.autodrafts.count' );

		wp_trash_post( self::factory()->post->create() );
		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );

		$facts = $this->facts();

		$this->assertSame( $before_trash + 1, $facts->value( 'db.trash.count' ) );
		$this->assertSame( $before_draft + 1, $facts->value( 'db.autodrafts.count' ) );
	}

	/**
	 * Spam comments are counted.
	 *
	 * @return void
	 */
	public function test_spam_comments_are_counted(): void {
		$before = $this->facts()->value( 'db.spam_comments.count' );

		self::factory()->comment->create_many(
			3,
			array(
				'comment_post_ID'  => self::factory()->post->create(),
				'comment_approved' => 'spam',
			)
		);

		$this->assertSame( $before + 3, $this->facts()->value( 'db.spam_comments.count' ) );
	}

	/**
	 * Expired transients are distinguished from live ones.
	 *
	 * This is the fact `db.clean_expired_transients` acts on, so getting it
	 * wrong would mean proposing to delete something still in use.
	 *
	 * @return void
	 */
	public function test_expired_transients_are_distinguished_from_live_ones(): void {
		global $wpdb;

		$before_total   = $this->facts()->value( 'db.transients.count' );
		$before_expired = $this->facts()->value( 'db.transients.expired' );

		set_transient( 'wpdebloat_live', 'value', HOUR_IN_SECONDS );

		// An expired transient has to be written directly: set_transient() will
		// not accept a timeout in the past, and get_transient() deletes what it
		// finds expired.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => '_transient_wpdebloat_stale',
				'option_value' => 'value',
				'autoload'     => 'off',
			)
		);
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => '_transient_timeout_wpdebloat_stale',
				'option_value' => (string) ( time() - HOUR_IN_SECONDS ),
				'autoload'     => 'off',
			)
		);

		$facts = $this->facts();

		$this->assertSame( $before_total + 2, $facts->value( 'db.transients.count' ), 'both transients are counted' );
		$this->assertSame( $before_expired + 1, $facts->value( 'db.transients.expired' ), 'only the stale one is expired' );
	}

	/**
	 * Orphan post meta is counted with the documented definition: a meta row
	 * whose owning post no longer exists.
	 *
	 * @return void
	 */
	public function test_orphan_postmeta_is_counted(): void {
		global $wpdb;

		$before = $this->facts()->value( 'db.orphan_postmeta.count' );

		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => 9999999,
				'meta_key'   => 'wpdebloat_orphan',
				'meta_value' => 'x',
			)
		);

		$this->assertSame( $before + 1, $this->facts()->value( 'db.orphan_postmeta.count' ) );
	}

	/**
	 * BUILD-SPEC §17 Phase 2: the database scanner's query count is bounded, and
	 * the bound is asserted rather than hoped for.
	 *
	 * @return void
	 */
	public function test_the_database_scanner_stays_inside_its_query_budget(): void {
		$scanner = new DatabaseScanner();

		$queries = $this->countQueries(
			function () use ( $scanner ): void {
				$scanner->scan( $this->context(), new \WPDebloat\Contracts\FactSet() );
			}
		);

		$this->assertLessThanOrEqual(
			DatabaseScanner::QUERY_BUDGET,
			$queries,
			'The database scanner ran more queries than its declared budget. Raising the budget is a decision, not an accident.'
		);
	}

	/**
	 * The autoload figure grows by exactly what was added.
	 *
	 * @return void
	 */
	public function test_autoloaded_options_are_measured(): void {
		$before = $this->facts()->value( 'db.autoload.bytes' );
		$value  = str_repeat( 'x', 50000 );

		add_option( 'wpdebloat_big_option', $value, '', true );

		$facts = $this->facts();

		$this->assertSame( $before + strlen( $value ), $facts->value( 'db.autoload.bytes' ) );

		$names = array_column( (array) $facts->value( 'db.autoload.top' ), 'name' );

		$this->assertContains( 'wpdebloat_big_option', $names, 'the largest options list should include it' );

		delete_option( 'wpdebloat_big_option' );
	}

	/**
	 * An option that is not autoloaded is not counted.
	 *
	 * @return void
	 */
	public function test_options_that_do_not_autoload_are_not_counted(): void {
		$before = $this->facts()->value( 'db.autoload.bytes' );

		add_option( 'wpdebloat_lazy_option', str_repeat( 'x', 50000 ), '', false );

		$this->assertSame( $before, $this->facts()->value( 'db.autoload.bytes' ) );

		delete_option( 'wpdebloat_lazy_option' );
	}

	/**
	 * Cron events are counted, and events recurring more often than once a
	 * minute are listed.
	 *
	 * @return void
	 */
	public function test_cron_events_are_counted_and_subminute_events_listed(): void {
		$before = $this->facts()->value( 'cron.events.count' );

		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The whole point of the fixture is a sub-minute schedule.
			static function ( $schedules ) {
				$schedules['wpdebloat_every_30s'] = array(
					'interval' => 30,
					'display'  => 'Every 30 seconds',
				);

				return $schedules;
			}
		);

		wp_schedule_event( time() + 60, 'wpdebloat_every_30s', 'wpdebloat_fixture_sync' );

		$facts = $this->facts();

		$this->assertSame( $before + 1, $facts->value( 'cron.events.count' ) );

		$hooks = array_column( (array) $facts->value( 'cron.events.subminute' ), 'hook' );

		$this->assertContains( 'wpdebloat_fixture_sync', $hooks );

		wp_clear_scheduled_hook( 'wpdebloat_fixture_sync' );
	}

	/**
	 * An event whose hook nothing listens to is counted as an orphan.
	 *
	 * @return void
	 */
	public function test_orphan_cron_events_are_counted(): void {
		$before = $this->facts()->value( 'cron.orphans.count' );

		wp_schedule_event( time() + 60, 'hourly', 'wpdebloat_nobody_listens' );

		$this->assertSame( $before + 1, $this->facts()->value( 'cron.orphans.count' ) );

		wp_clear_scheduled_hook( 'wpdebloat_nobody_listens' );
	}

	/**
	 * Core features are reported as on for a default install, and as off once
	 * the matching runtime handler has removed them.
	 *
	 * This is what makes a second scan after an apply report the truth rather
	 * than recommending the same change again.
	 *
	 * @return void
	 */
	public function test_core_features_track_what_is_actually_registered(): void {
		$facts = $this->facts();

		$this->assertTrue( $facts->value( 'wp.generator_tag' ) );
		$this->assertTrue( $facts->value( 'wp.rsd_link' ) );
		$this->assertTrue( $facts->value( 'wp.shortlink' ) );
		$this->assertTrue( $facts->value( 'wp.emojis_enabled' ) );
		$this->assertTrue( $facts->value( 'wp.self_pingbacks' ) );

		$this->selectAndGenerate(
			array(
				'core.remove_generator'       => array(),
				'core.remove_rsd'             => array(),
				'core.disable_self_pingbacks' => array(),
			)
		);
		$this->loadRuntime();

		$after = $this->facts();

		$this->assertFalse( $after->value( 'wp.generator_tag' ) );
		$this->assertFalse( $after->value( 'wp.rsd_link' ) );
		$this->assertFalse( $after->value( 'wp.self_pingbacks' ) );
		$this->assertTrue( $after->value( 'wp.shortlink' ), 'a feature we did not touch must be unchanged' );

		$this->unregisterHandlers(
			array( 'core.remove_generator', 'core.remove_rsd', 'core.disable_self_pingbacks' )
		);
	}

	/**
	 * Configuration facts read the way WordPress reads them, filters included.
	 *
	 * @return void
	 */
	public function test_heartbeat_interval_follows_the_filter(): void {
		$this->assertSame( 15, $this->facts()->value( 'wp.heartbeat_interval' ) );

		$filter = static function ( $settings ) {
			$settings['interval'] = 60;

			return $settings;
		};

		add_filter( 'heartbeat_settings', $filter );

		$this->assertSame( 60, $this->facts()->value( 'wp.heartbeat_interval' ) );

		remove_filter( 'heartbeat_settings', $filter );
	}

	/**
	 * Administrators and recent editors are counted.
	 *
	 * @return void
	 */
	public function test_users_are_counted(): void {
		$before = $this->facts()->value( 'users.admin_count' );

		self::factory()->user->create_many( 3, array( 'role' => 'administrator' ) );
		self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( $before + 3, $this->facts()->value( 'users.admin_count' ) );
	}

	/**
	 * Detectors report both outcomes, so "not installed" is distinguishable
	 * from "not looked for".
	 *
	 * @return void
	 */
	public function test_detectors_report_both_outcomes(): void {
		$detected = $this->facts()->value( 'plugins.detected' );

		$this->assertIsArray( $detected );
		$this->assertCount( count( $this->plugin->registry()->detectors() ), $detected );

		foreach ( $detected as $slug => $value ) {
			$this->assertIsBool( $value, $slug . ' must be a definite yes or no' );
		}

		$this->assertFalse( $detected['woocommerce'], 'WooCommerce is not installed on the test site' );
	}

	/**
	 * A detector fires when its plugin file is active, and the fact set changes
	 * accordingly.
	 *
	 * A stub plugin file is enough: detection reads the active plugin list, so
	 * this exercises exactly what the detector does without installing sixty
	 * megabytes of WooCommerce into the test suite.
	 *
	 * @return void
	 */
	public function test_a_detector_fires_for_an_active_plugin(): void {
		$active = get_option( 'active_plugins', array() );

		update_option( 'active_plugins', array_merge( (array) $active, array( 'woocommerce/woocommerce.php' ) ) );

		$this->plugin->resetServices();

		$detected = $this->facts()->value( 'plugins.detected' );

		$this->assertTrue( $detected['woocommerce'] );
		$this->assertFalse( $detected['elementor'], 'an unrelated detector must not fire' );

		update_option( 'active_plugins', $active );
	}

	/**
	 * A scan is recorded as a run, with its facts in the payload.
	 *
	 * @return void
	 */
	public function test_a_scan_is_recorded_as_a_run(): void {
		$this->plugin->schema()->ensure();

		$run = $this->plugin->scan();

		$this->assertNotNull( $run->id );
		$this->assertSame( RunType::SCAN, $run->type );
		$this->assertSame( $this->plugin->registry()->hash(), $run->registry_hash );
		$this->assertTrue( $run->isFinished() );
		$this->assertGreaterThan( 0, $run->facts()->count() );
		$this->assertSame( $run->id, $this->plugin->state()->get( 'last_scan_run_id' ) );

		$reloaded = $this->plugin->runs()->find( (int) $run->id );

		$this->assertNotNull( $reloaded );
		$this->assertEquals( $run->facts()->toArray(), $reloaded->facts()->toArray() );
	}

	/**
	 * The recorded facts round-trip through storage without loss.
	 *
	 * @return void
	 */
	public function test_recorded_facts_survive_storage(): void {
		$this->plugin->schema()->ensure();

		$run      = $this->plugin->scan();
		$reloaded = $this->plugin->runs()->find( (int) $run->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame( $run->facts()->keys(), $reloaded->facts()->keys() );
	}

	/**
	 * The soft budget is what the specification says it is.
	 *
	 * @return void
	 */
	public function test_the_budget_is_two_seconds(): void {
		$this->assertSame( 2000, ScanRunner::BUDGET_MS );
	}

	/**
	 * Collect facts without recording a run.
	 *
	 * @return \WPDebloat\Contracts\FactSet
	 */
	private function facts(): \WPDebloat\Contracts\FactSet {
		return $this->plugin->scanRunner()->collect( $this->context() )->facts;
	}
}
