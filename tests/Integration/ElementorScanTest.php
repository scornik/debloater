<?php
/**
 * The Elementor scan, against a real WordPress install.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Contracts\FactSet;
use Debloater\Registry\SchemaValidator;
use Debloater\Scan\Elementor\WidgetCatalog;
use Debloater\Tests\Integration\Support\FakeWidgetCatalogue;
use Debloater\Tests\Integration\Support\UnreadableWidgetCatalogue;
use Debloater\Scan\Scanners\ElementorScanner;

/**
 * BUILD-SPEC §17 Phase 14.
 *
 * Elementor is not installed in the test environment — `.wp-env.json` puts it in
 * the development environment only — so the widget catalogue comes from a fake.
 * That is the right seam rather than a workaround: the catalogue is the one part
 * of this scan that has to talk to Elementor, which is exactly why it is an
 * interface, and the safety rules ask for third-party integrations to be built
 * behind a tested adapter with fixtures.
 *
 * Everything else here is real: real posts, real `_elementor_data`, real
 * options, read by the real scanner.
 */
final class ElementorScanTest extends IntegrationTestCase {

	/**
	 * Prepare the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		// Presence through the active-plugins option, not by defining
		// ELEMENTOR_VERSION. A constant cannot be undone, and defining one here
		// made the Elementor detector fire in an unrelated scanner test for the
		// rest of the process — a test that changes the world for its
		// neighbours is worse than no test.
		update_option( 'active_plugins', array( 'elementor/elementor.php' ) );
	}

	/**
	 * Leave the site as it was found.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		update_option( 'active_plugins', array() );

		parent::tear_down();
	}

	/**
	 * Widget types are read out of the saved designs.
	 *
	 * @return void
	 */
	public function test_widget_types_are_read_from_saved_designs(): void {
		$this->seedDesign( array( 'heading', 'image', 'heading' ) );
		$this->seedDesign( array( 'button' ) );

		$facts = $this->scan();

		$this->assertSame( 2, $facts->value( 'elementor.documents' ) );
		$this->assertSame(
			array( 'button', 'heading', 'image' ),
			$facts->value( 'elementor.widgets_in_use' ),
			'each type once, sorted, so two scans of an unchanged site match'
		);
	}

	/**
	 * Registered widgets are attributed to whichever plugin defines them.
	 *
	 * @return void
	 */
	public function test_widgets_are_attributed_to_the_plugin_that_defines_them(): void {
		$facts = $this->scan();

		$packs = $facts->value( 'elementor.packs', array() );

		$this->assertIsArray( $packs );
		$this->assertNotSame( array(), $packs );

		$this->assertSame( 3, $facts->value( 'elementor.widgets_available' ) );

		foreach ( $facts->value( 'elementor.widgets', array() ) as $widget ) {
			$this->assertNotSame( '', $widget['source'] );
		}
	}

	/**
	 * The four things that hide a widget from the count are each observed.
	 *
	 * @return void
	 */
	public function test_the_hiding_signals_are_observed(): void {
		$this->seedDesign( array( 'heading' ) );

		$plain = $this->scan();

		$this->assertFalse( $plain->value( 'elementor.dynamic_tags' ) );
		$this->assertFalse( $plain->value( 'elementor.shortcodes' ) );
		$this->assertFalse( $plain->value( 'elementor.custom_code' ) );

		$this->seedDesign( array( 'shortcode' ) );
		$this->seedDesign( array( 'html' ) );
		$this->seedRaw( '[{"elType":"widget","widgetType":"heading","settings":{"title":"__dynamic__"}}]' );

		$muddy = $this->scan();

		$this->assertTrue( $muddy->value( 'elementor.dynamic_tags' ) );
		$this->assertTrue( $muddy->value( 'elementor.shortcodes' ) );
		$this->assertTrue( $muddy->value( 'elementor.custom_code' ) );
	}

	/**
	 * Font families and experiments are read.
	 *
	 * @return void
	 */
	public function test_fonts_and_experiments_are_read(): void {
		$this->seedRaw(
			'[{"elType":"widget","widgetType":"heading","settings":{"typography_font_family":"Roboto","title_typography_font_family":"Inter"}}]'
		);

		update_option( 'elementor_experiment-container', 'active' );
		update_option( 'elementor_experiment-e_lazyload', 'inactive' );

		try {
			$facts = $this->scan();

			$this->assertSame( array( 'Inter', 'Roboto' ), $facts->value( 'elementor.fonts' ) );

			$this->assertSame(
				array(
					array(
						'feature' => 'container',
						'state'   => 'active',
					),
					array(
						'feature' => 'e_lazyload',
						'state'   => 'inactive',
					),
				),
				$facts->value( 'elementor.experiments' )
			);
		} finally {
			delete_option( 'elementor_experiment-container' );
			delete_option( 'elementor_experiment-e_lazyload' );
		}
	}

	/**
	 * A catalogue that cannot be read leaves the counts absent, not zero.
	 *
	 * @return void
	 */
	public function test_an_unreadable_catalogue_leaves_the_counts_absent(): void {
		$this->seedDesign( array( 'heading' ) );

		$facts = $this->scan( new UnreadableWidgetCatalogue() );

		$this->assertTrue( $facts->value( 'elementor.present' ) );
		$this->assertSame( 1, $facts->value( 'elementor.documents' ), 'the designs are still readable' );

		$this->assertNull(
			$facts->value( 'elementor.widgets_available' ),
			'an unread catalogue is absent, and absent is not zero'
		);
	}

	/**
	 * The facts validate against the schema.
	 *
	 * @return void
	 */
	public function test_the_facts_validate(): void {
		$this->seedDesign( array( 'heading' ) );

		$violations = SchemaValidator::fromFile( DEBLOATER_TESTS_ROOT . '/registry/schemas/fact.schema.json' )
			->validate( $this->scan()->toArray() );

		$this->assertSame( array(), $violations, implode( '; ', array_map( 'strval', $violations ) ) );
	}

	/**
	 * The Google Fonts tweak registers and unregisters cleanly, and answers
	 * Elementor's own filter.
	 *
	 * @return void
	 */
	public function test_the_fonts_tweak_uses_elementors_own_filter_and_reverses(): void {
		$before = $this->hookSnapshot();

		$this->selectAndGenerate( array( 'elementor.disable_google_fonts' => array() ) );
		$this->loadRuntime();

		$this->assertFalse(
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's hook, spelled Elementor's way.
			(bool) apply_filters( 'elementor/frontend/print_google_fonts', true ),
			'Elementor asks, and the answer is no'
		);

		$this->unregisterHandlers( array( 'elementor.disable_google_fonts' ) );

		$this->assertTrue(
			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's hook, spelled Elementor's way.
			(bool) apply_filters( 'elementor/frontend/print_google_fonts', true ),
			'and unselecting it gives Elementor its own answer back'
		);

		$after = $this->hookSnapshot();

		$this->assertSame( array(), $this->hooksAdded( $before, $after ) );
		$this->assertSame( array(), $this->hooksRemoved( $before, $after ) );
	}

	/**
	 * Facts from an Elementor scan.
	 *
	 * @param WidgetCatalog|null $catalog Catalogue to read.
	 * @return FactSet
	 */
	private function scan( ?WidgetCatalog $catalog = null ): FactSet {
		return ( new ElementorScanner( $catalog ?? new FakeWidgetCatalogue() ) )
			->scan( $this->context(), new FactSet() );
	}

	/**
	 * Save a design made of the given widget types.
	 *
	 * @param array<int,string> $types Widget types.
	 * @return void
	 */
	private function seedDesign( array $types ): void {
		$elements = array();

		foreach ( $types as $type ) {
			$elements[] = array(
				'elType'     => 'widget',
				'widgetType' => $type,
				'settings'   => array(),
			);
		}

		$this->seedRaw( (string) wp_json_encode( $elements ) );
	}

	/**
	 * Save a design exactly as given.
	 *
	 * @param string $data Raw `_elementor_data` value.
	 * @return void
	 */
	private function seedRaw( string $data ): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		update_post_meta( $post_id, '_elementor_data', wp_slash( $data ) );
	}
}
