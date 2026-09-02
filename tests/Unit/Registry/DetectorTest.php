<?php
/**
 * Tests for detectors and the shipped detector set.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use WPDebloat\Contracts\ContractViolation;
use WPDebloat\Registry\Detector;
use WPDebloat\Registry\Loader;

/**
 * Detectors are pure data: they say what to look for, and PluginScanner does
 * the looking. These tests pin that boundary as well as the shape.
 */
final class DetectorTest extends TestCase {

	/**
	 * The ten detectors BUILD-SPEC §17 Phase 2 names are all present.
	 *
	 * @return void
	 */
	public function test_the_specified_detectors_are_shipped(): void {
		$this->assertSame(
			array(
				'contact-form-7',
				'elementor',
				'elementor-pro',
				'litespeed-cache',
				'rank-math',
				'woocommerce',
				'wordfence',
				'wp-rocket',
				'wp-super-cache',
				'yoast',
			),
			array_keys( $this->shippedDetectors() )
		);
	}

	/**
	 * Every shipped detector writes exactly one boolean under its own slug, so
	 * `plugins.detected` stays a flat map of slug to yes/no.
	 *
	 * @return void
	 */
	public function test_every_detector_writes_its_own_slug(): void {
		foreach ( $this->shippedDetectors() as $id => $detector ) {
			$this->assertSame(
				array( Detector::FACT_PREFIX . $id => true ),
				$detector->sets,
				$id . ' must write exactly plugins.detected.' . $id
			);
		}
	}

	/**
	 * Every shipped detector declares at least two signals, so a rename or a
	 * fork does not make it blind.
	 *
	 * @return void
	 */
	public function test_every_detector_has_more_than_one_signal(): void {
		foreach ( $this->shippedDetectors() as $id => $detector ) {
			$this->assertGreaterThanOrEqual(
				2,
				count( $detector->signals() ),
				$id . ' should recognise more than one signal'
			);
		}
	}

	/**
	 * A detector reports the negative outcome too: "not installed" is a fact a
	 * rule may need, and an absent key would be indistinguishable from "we did
	 * not look".
	 *
	 * @return void
	 */
	public function test_a_detector_reports_the_negative_case(): void {
		$detector = $this->shippedDetectors()['woocommerce'];

		$this->assertSame( array( 'plugins.detected.woocommerce' => false ), $detector->negativeFacts() );
	}

	/**
	 * A list of plugin files flattens into one signal per file.
	 *
	 * @return void
	 */
	public function test_plugin_file_lists_flatten_into_signals(): void {
		$detector = new Detector(
			'example',
			'Example',
			array( 'plugin_files' => array( 'a/a.php', 'b/b.php' ) ),
			array( 'plugins.detected.example' => true )
		);

		$this->assertSame(
			array(
				array(
					'type'  => 'plugin_file',
					'value' => 'a/a.php',
				),
				array(
					'type'  => 'plugin_file',
					'value' => 'b/b.php',
				),
			),
			$detector->signals()
		);
	}

	/**
	 * A detector with no signals would match every site.
	 *
	 * @return void
	 */
	public function test_a_detector_without_signals_is_refused(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/would match every site/' );

		new Detector( 'example', 'Example', array(), array( 'plugins.detected.example' => true ) );
	}

	/**
	 * An unknown signal name is an authoring error, not something to ignore: a
	 * typo would otherwise produce a detector that silently never fires.
	 *
	 * @return void
	 */
	public function test_an_unknown_signal_is_refused(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/unknown signal "vibes"/' );

		new Detector( 'example', 'Example', array( 'vibes' => 'good' ), array( 'plugins.detected.example' => true ) );
	}

	/**
	 * A detector may only write under `plugins.detected.`, so a detector file
	 * cannot reach into another scanner's namespace.
	 *
	 * @return void
	 */
	public function test_a_detector_cannot_write_outside_its_namespace(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/must start with "plugins\.detected\."/' );

		new Detector( 'example', 'Example', array( 'constant' => 'X' ), array( 'env.host_vendor' => 'kinsta' ) );
	}

	/**
	 * A detector writing nothing has observed nothing.
	 *
	 * @return void
	 */
	public function test_a_detector_must_write_a_fact(): void {
		$this->expectException( ContractViolation::class );

		new Detector( 'example', 'Example', array( 'constant' => 'X' ), array() );
	}

	/**
	 * Detectors round-trip through their array form.
	 *
	 * @return void
	 */
	public function test_round_trip(): void {
		foreach ( $this->shippedDetectors() as $detector ) {
			$this->assertEquals( $detector, Detector::fromArray( $detector->toArray() ) );
		}
	}

	/**
	 * The registry hash covers detectors, not only tweaks: a changed detector
	 * changes what a scan sees, so a plan produced before and after is not the
	 * same plan.
	 *
	 * @return void
	 */
	public function test_detectors_are_part_of_the_registry_hash(): void {
		$loader = new Loader( WPDEBLOAT_TESTS_ROOT . '/registry' );

		$with    = new \WPDebloat\Registry\Registry( $loader->loadTweaks(), $loader->loadDetectors() );
		$without = new \WPDebloat\Registry\Registry( $loader->loadTweaks(), array() );

		$this->assertNotSame( $with->hash(), $without->hash() );
	}

	/**
	 * The detectors shipped in this repository, in the registry's own order.
	 *
	 * Read through Registry rather than from the loader directly, because the
	 * registry is what everything downstream sees: it sorts by id, whereas the
	 * loader returns file order, in which "elementor-pro.json" precedes
	 * "elementor.json".
	 *
	 * @return array<string,Detector>
	 */
	private function shippedDetectors(): array {
		return ( new Loader( WPDEBLOAT_TESTS_ROOT . '/registry' ) )->load()->detectors();
	}
}
