<?php
/**
 * Counting what is there, before and after.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_Error;
use Debloater\Meter\Comparison;
use Debloater\Meter\Measurement;
use Debloater\Meter\MeasurementSet;
use Debloater\Meter\Meter;
use Debloater\Meter\PageMetrics;

/**
 * BUILD-SPEC §12.
 *
 * The rules under test are all about restraint: measure counts, never time;
 * report "not measured" rather than zero; never claim a percentage change from
 * nothing. Each of those is a way a reporting layer flatters itself, and each
 * has a test here that fails if it starts to.
 */
final class MeterTest extends IntegrationTestCase {

	/**
	 * A page with two scripts, one stylesheet, one image and one external host.
	 */
	private const PAGE = '<!DOCTYPE html><html><head><title>A site</title>'
		. '<link rel="stylesheet" href="https://example.test/style.css">'
		. '<script src="https://example.test/a.js"></script>'
		. '<script src="https://cdn.elsewhere.test/b.js"></script>'
		. '</head><body><img src="https://example.test/x.png"><div class="notice notice-info">Hi</div></body></html>';

	/**
	 * Stop intercepting HTTP.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * Every metric §12 lists is present in a reading.
	 *
	 * @return void
	 */
	public function test_every_v1_metric_is_measured(): void {
		$this->servePage();

		$set = $this->plugin->meter()->measure();

		foreach ( Meter::METRICS as $metric ) {
			$this->assertNotNull(
				$set->get( $metric ),
				sprintf( '§12 lists %s among the v1 metrics.', $metric )
			);
		}
	}

	/**
	 * Every metric carries a unit, and none of them is a unit of time.
	 *
	 * @return void
	 */
	public function test_no_metric_is_measured_in_time(): void {
		$this->servePage();

		$forbidden = array( 'ms', 'milliseconds', 'seconds', 'second', 'minutes', 'time' );

		foreach ( $this->plugin->meter()->measure()->measurements as $measurement ) {
			$this->assertNotSame( '', $measurement->unit, $measurement->metric . ' has no unit.' );

			$this->assertNotContains(
				strtolower( $measurement->unit ),
				$forbidden,
				sprintf(
					'%s is measured in %s. §12: never reported as time saved.',
					$measurement->metric,
					$measurement->unit
				)
			);
		}
	}

	/**
	 * The page counts come from the page, and count what a browser would fetch.
	 *
	 * @return void
	 */
	public function test_page_metrics_read_the_markup(): void {
		$page = new PageMetrics( self::PAGE, 'https://example.test/' );

		$this->assertSame( 2, $page->scripts() );
		$this->assertSame( 1, $page->styles() );
		$this->assertSame( 5, $page->requests(), 'The document itself counts: 1 + 2 scripts + 1 style + 1 image.' );
		$this->assertSame( 1, $page->externalHosts(), 'Only the CDN is external to example.test.' );
		$this->assertSame( 1, $page->adminNotices() );
		$this->assertGreaterThan( 0, $page->headBytes() );
	}

	/**
	 * A page that could not be fetched produces no numbers, not zeroes.
	 *
	 * @return void
	 */
	public function test_unreachable_pages_are_not_measured_as_zero(): void {
		add_filter(
			'pre_http_request',
			static fn (): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 7' ),
			10,
			3
		);

		$set = $this->plugin->meter()->measure();

		foreach ( array( 'frontend.requests', 'frontend.scripts.count', 'frontend.head_bytes' ) as $metric ) {
			$measurement = $set->get( $metric );

			$this->assertNotNull( $measurement );
			$this->assertFalse( $measurement->isAvailable(), $metric . ' must not report a number it did not take.' );
			$this->assertNotSame( '', $measurement->unavailable_because );
		}

		// The database metrics do not depend on HTTP, and are still measured.
		$this->assertTrue( $set->get( 'db.autoload_bytes' )->isAvailable() );
		$this->assertTrue( $set->get( 'cron.events' )->isAvailable() );
	}

	/**
	 * The database metrics count what is actually in the database.
	 *
	 * @return void
	 */
	public function test_database_metrics_count_rows(): void {
		$this->servePage();

		$before = $this->plugin->meter()->measure();

		for ( $index = 0; $index < 5; $index++ ) {
			set_transient( 'wpd_meter_' . $index, 'x', 60 );
			update_option( '_transient_timeout_wpd_meter_' . $index, time() - 100 );
		}

		$after = $this->plugin->meter()->measure();

		$this->assertSame(
			$before->get( 'db.transients_expired' )->value + 5,
			$after->get( 'db.transients_expired' )->value
		);
	}

	/**
	 * A metric that did not change is reported as unchanged rather than left
	 * out. A report that lists only improvements is an advertisement.
	 *
	 * @return void
	 */
	public function test_unchanged_metrics_are_still_reported(): void {
		$before = new MeasurementSet( array( new Measurement( 'cron.events', 12.0, 'events' ) ) );
		$after  = new MeasurementSet( array( new Measurement( 'cron.events', 12.0, 'events' ) ) );

		$deltas = ( new Comparison( $before, $after ) )->deltas();

		$this->assertCount( 1, $deltas );
		$this->assertSame( 'unchanged', $deltas[0]['direction'] );
		$this->assertSame( 0.0, $deltas[0]['delta'] );
	}

	/**
	 * A metric measured before but not after has no delta, rather than a fall
	 * to zero.
	 *
	 * @return void
	 */
	public function test_a_missing_after_is_unknown_not_a_fall_to_zero(): void {
		$before = new MeasurementSet( array( new Measurement( 'frontend.requests', 40.0, 'requests' ) ) );
		$after  = new MeasurementSet(
			array( Measurement::unavailable( 'frontend.requests', 'requests', 'The site could not be reached.' ) )
		);

		$deltas = ( new Comparison( $before, $after ) )->deltas();

		$this->assertSame( 'unknown', $deltas[0]['direction'] );
		$this->assertNull( $deltas[0]['delta'] );
		$this->assertNull( $deltas[0]['percent'] );
		$this->assertStringContainsString( 'could not be reached', (string) $deltas[0]['reason'] );
	}

	/**
	 * There is no percentage change from zero.
	 *
	 * @return void
	 */
	public function test_no_percentage_is_invented_from_zero(): void {
		$before = new MeasurementSet( array( new Measurement( 'db.revisions', 0.0, 'rows' ) ) );
		$after  = new MeasurementSet( array( new Measurement( 'db.revisions', 8.0, 'rows' ) ) );

		$deltas = ( new Comparison( $before, $after ) )->deltas();

		$this->assertSame( 8.0, $deltas[0]['delta'] );
		$this->assertNull( $deltas[0]['percent'] );
	}

	/**
	 * A fall is reported with its percentage.
	 *
	 * @return void
	 */
	public function test_a_fall_is_reported_with_its_percentage(): void {
		$before = new MeasurementSet( array( new Measurement( 'frontend.scripts.count', 20.0, 'scripts' ) ) );
		$after  = new MeasurementSet( array( new Measurement( 'frontend.scripts.count', 15.0, 'scripts' ) ) );

		$deltas = ( new Comparison( $before, $after ) )->deltas();

		$this->assertSame( -5.0, $deltas[0]['delta'] );
		$this->assertSame( -25.0, $deltas[0]['percent'] );
		$this->assertSame( 'down', $deltas[0]['direction'] );
	}

	/**
	 * Nothing the meter produces claims speed.
	 *
	 * @return void
	 */
	public function test_the_comparison_never_claims_speed(): void {
		$this->servePage();

		$comparison = new Comparison( $this->plugin->meter()->measure(), $this->plugin->meter()->measure() );
		$json       = strtolower( (string) wp_json_encode( $comparison->toArray() ) );

		foreach ( array( 'faster', 'speed', 'load time', 'ms ', 'seconds saved' ) as $claim ) {
			$this->assertStringNotContainsString( $claim, $json, sprintf( 'The report must never say "%s".', $claim ) );
		}
	}

	/**
	 * Serve a fixed page for every request.
	 *
	 * @return void
	 */
	private function servePage(): void {
		add_filter(
			'pre_http_request',
			static fn (): array => array(
				'headers'  => array(),
				'body'     => self::PAGE,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			),
			10,
			3
		);
	}
}
