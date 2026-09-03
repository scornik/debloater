<?php
/**
 * Facts about an Elementor site.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;
use Debloater\Scan\Elementor\LiveWidgetCatalog;
use Debloater\Scan\Elementor\WidgetCatalog;
use Debloater\Scan\Sources;

/**
 * Collects the `elementor.*` facts (BUILD-SPEC §5, §17 Phase 14).
 *
 * Elementor sites accumulate addon packs. Each one registers dozens of widgets,
 * every registered widget is code that loads, and a site that installed a pack
 * for one slider is carrying the other fifty. That is a real and common
 * situation, and it is also one where confident-sounding numbers do the most
 * harm — so this scanner is careful about exactly what it can and cannot see.
 *
 * **What it sees.** Which widget types are registered, and which plugin
 * registered each one, by asking where the widget's class is defined. Which
 * widget types appear in `_elementor_data` across every post, page and template.
 * Which experiments are switched on. Which font families the stored designs
 * refer to.
 *
 * **What it cannot see, and the facts say so.** A widget used only through a
 * dynamic tag, a shortcode, a theme-builder template rendered conditionally, or
 * custom code, does not appear in `_elementor_data` as itself. Those four
 * signals are recorded as facts precisely so the rule can lower its confidence
 * rather than the scanner pretending they do not exist.
 *
 * Nothing here is ever "unused". The strongest thing the facts support is
 * *potentially* unused, and that word is asserted by a test.
 */
final class ElementorScanner extends AbstractScanner {

	/**
	 * Posts read in one batch when scanning stored designs.
	 */
	private const BATCH = 100;

	/**
	 * The widget catalogue.
	 *
	 * @var WidgetCatalog
	 */
	private WidgetCatalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param WidgetCatalog|null $catalog Catalogue to read; asks Elementor when omitted.
	 */
	public function __construct( ?WidgetCatalog $catalog = null ) {
		$this->catalog = $catalog ?? new LiveWidgetCatalog();
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'elementor';
	}

	/**
	 * Collect Elementor facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$present = defined( 'ELEMENTOR_VERSION' ) || is_plugin_active( 'elementor/elementor.php' );

		if ( ! $present ) {
			// One fact, and it is the only one that means anything here. Zeroes
			// for the rest would read as "an Elementor site with nothing on it".
			return array( 'elementor.present' => false );
		}

		$stored = $this->storedDesigns();

		return array_merge(
			array(
				'elementor.present'     => true,
				'elementor.pro'         => defined( 'ELEMENTOR_PRO_VERSION' ),
				'elementor.version'     => defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : null,
				'elementor.experiments' => $this->experiments(),
			),
			$this->catalogue(),
			$stored
		);
	}

	/**
	 * Registered widgets, and which plugin each belongs to.
	 *
	 * @return array<string,mixed>
	 */
	private function catalogue(): array {
		if ( ! $this->catalog->available() ) {
			// Absent, not empty. An empty catalogue would say Elementor has no
			// widgets, which would make everything on the site look unaccounted
			// for.
			return array();
		}

		$widgets = array();
		$packs   = array();

		foreach ( $this->catalog->widgets() as $name => $class ) {
			$source = Sources::of( $class );

			$widgets[] = array(
				'name'   => (string) $name,
				'source' => $source,
			);

			$packs[ $source ] = ( $packs[ $source ] ?? 0 ) + 1;
		}

		usort(
			$widgets,
			static fn ( array $left, array $right ): int => array( $left['source'], $left['name'] )
				<=> array( $right['source'], $right['name'] )
		);

		arsort( $packs, SORT_NUMERIC );

		$by_pack = array();

		foreach ( $packs as $source => $count ) {
			$by_pack[] = array(
				'source' => (string) $source,
				'count'  => (int) $count,
			);
		}

		return array(
			'elementor.widgets'           => $widgets,
			'elementor.widgets_available' => count( $widgets ),
			'elementor.packs'             => $by_pack,
		);
	}

	/**
	 * What the stored designs actually contain.
	 *
	 * Read from `_elementor_data` rather than from rendered pages, because that
	 * is where every design lives whether or not anything links to it — a
	 * template used once a year is still a template.
	 *
	 * @return array<string,mixed>
	 */
	private function storedDesigns(): array {
		global $wpdb;

		$used        = array();
		$documents   = 0;
		$dynamic     = false;
		$shortcodes  = false;
		$custom_code = false;
		$fonts       = array();
		$offset      = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading one meta key across every post; get_post_meta() would be one query per post and there is no cache to warm.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d OFFSET %d",
					'_elementor_data',
					self::BATCH,
					$offset
				)
			);

			if ( ! is_array( $rows ) || array() === $rows ) {
				break;
			}

			foreach ( $rows as $raw ) {
				$data = (string) $raw;

				if ( '' === $data ) {
					continue;
				}

				++$documents;

				foreach ( $this->widgetTypesIn( $data ) as $type ) {
					$used[ $type ] = true;
				}

				foreach ( $this->fontsIn( $data ) as $family ) {
					$fonts[ $family ] = true;
				}

				$dynamic     = $dynamic || false !== strpos( $data, '__dynamic__' );
				$shortcodes  = $shortcodes || false !== strpos( $data, '"widgetType":"shortcode"' );
				$custom_code = $custom_code || false !== strpos( $data, '"widgetType":"html"' );
			}

			$read    = count( $rows );
			$offset += self::BATCH;
		} while ( self::BATCH === $read );

		$in_use = array_keys( $used );
		$family = array_keys( $fonts );

		sort( $in_use, SORT_STRING );
		sort( $family, SORT_STRING );

		return array(
			'elementor.documents'      => $documents,
			'elementor.widgets_in_use' => $in_use,
			'elementor.templates'      => $this->templateCount(),
			'elementor.dynamic_tags'   => $dynamic,
			'elementor.shortcodes'     => $shortcodes,
			'elementor.custom_code'    => $custom_code,
			'elementor.fonts'          => $family,
		);
	}

	/**
	 * Widget type names inside one stored design.
	 *
	 * A regular expression rather than a JSON decode: the stored document is
	 * deeply nested, can be large, and the only thing wanted from it is a flat
	 * list of type names. Decoding megabytes of design to read one field of each
	 * element would cost far more than it returns.
	 *
	 * @param string $data Raw `_elementor_data` value.
	 * @return array<int,string>
	 */
	private function widgetTypesIn( string $data ): array {
		if ( ! preg_match_all( '/"widgetType"\s*:\s*"([^"]+)"/', $data, $matches ) ) {
			return array();
		}

		return array_map( 'strval', $matches[1] );
	}

	/**
	 * Font families referenced by one stored design.
	 *
	 * @param string $data Raw `_elementor_data` value.
	 * @return array<int,string>
	 */
	private function fontsIn( string $data ): array {
		if ( ! preg_match_all( '/"[a-z_]*typography_font_family"\s*:\s*"([^"]+)"/', $data, $matches ) ) {
			return array();
		}

		$families = array();

		foreach ( $matches[1] as $family ) {
			$family = trim( (string) $family );

			if ( '' !== $family ) {
				$families[] = $family;
			}
		}

		return $families;
	}

	/**
	 * How many Elementor library templates exist.
	 *
	 * Counted separately because a template is a document nothing links to
	 * directly, which is exactly the case that makes "unused" an unsafe word.
	 *
	 * @return int
	 */
	private function templateCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A count of one post type; there is no cached answer to this question.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
				'elementor_library',
				'auto-draft'
			)
		);
	}

	/**
	 * Elementor experiments that are switched on or off explicitly.
	 *
	 * An experiment left at its default has no option row, and is absent here
	 * rather than reported as its default — what Elementor's defaults are is
	 * Elementor's business and changes between versions.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function experiments(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading a family of option names; there is no API that lists them.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'elementor_experiment-' ) . '%'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$experiments = array();

		foreach ( $rows as $row ) {
			$experiments[] = array(
				'feature' => (string) substr( (string) $row['option_name'], strlen( 'elementor_experiment-' ) ),
				'state'   => (string) $row['option_value'],
			);
		}

		usort(
			$experiments,
			static fn ( array $left, array $right ): int => strcmp( $left['feature'], $right['feature'] )
		);

		return $experiments;
	}
}
