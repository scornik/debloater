<?php
/**
 * Reading a page's assets back out of its HTML.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Scan;

use PHPUnit\Framework\TestCase;
use WPDebloat\Scan\AssetParser;

/**
 * BUILD-SPEC §17 Phase 13.
 *
 * The parser is the part of the asset scan that can be tested exactly, so it is.
 * Everything downstream — attribution, counting, the CF7 finding — is only as
 * good as this, and a parser that quietly missed a third of the page would make
 * every number after it look reassuring.
 */
final class AssetParserTest extends TestCase {

	/**
	 * Every enqueued script and stylesheet on the fixture page is found.
	 *
	 * @return void
	 */
	public function test_every_enqueued_asset_is_found(): void {
		$assets = AssetParser::parse( $this->fixture() );

		$handles = array();

		foreach ( $assets as $asset ) {
			if ( '' !== $asset['handle'] ) {
				$handles[] = $asset['handle'];
			}
		}

		sort( $handles, SORT_STRING );

		$this->assertSame(
			array(
				'analytics',
				'classic-theme-styles',
				'contact-form-7',
				'contact-form-7',
				'dashicons',
				'elementor-frontend',
				'elementor-frontend',
				'elementor-post-6',
				'google-fonts-1',
				'jquery-blockui',
				'jquery-core',
				'jquery-migrate',
				'storefront-icons',
				'storefront-navigation',
				'storefront-style',
				'swv',
				'wc-cart-fragments',
				'woocommerce',
				'woocommerce-general',
				'woocommerce-layout',
				'woocommerce-smallscreen',
				'wp-block-library',
			),
			$handles
		);
	}

	/**
	 * A stylesheet and a script may share a handle, and are told apart by kind.
	 *
	 * `contact-form-7-css` and `contact-form-7-js` are one plugin's two assets,
	 * not one asset counted twice.
	 *
	 * @return void
	 */
	public function test_a_style_and_a_script_may_share_a_handle(): void {
		$assets = AssetParser::parse( $this->fixture() );

		$kinds = array();

		foreach ( $assets as $asset ) {
			if ( 'contact-form-7' === $asset['handle'] ) {
				$kinds[] = $asset['kind'];
			}
		}

		sort( $kinds, SORT_STRING );

		$this->assertSame( array( AssetParser::SCRIPT, AssetParser::STYLE ), $kinds );
	}

	/**
	 * A script printed by hand is recorded with no handle rather than dropped.
	 *
	 * This is the case worth catching. An asset nobody enqueued cannot be
	 * dequeued either, so it is exactly the one a person needs to be told about
	 * — and it is the one a handle-based parser would silently lose.
	 *
	 * @return void
	 */
	public function test_a_hand_written_script_is_kept_without_a_handle(): void {
		$assets = AssetParser::parse( $this->fixture() );

		$anonymous = array();

		foreach ( $assets as $asset ) {
			if ( '' === $asset['handle'] ) {
				$anonymous[] = $asset['url'];
			}
		}

		$this->assertSame(
			array( 'http://example.test/wp-content/themes/storefront/assets/js/hand-rolled.js' ),
			$anonymous
		);
	}

	/**
	 * Inline scripts are not assets.
	 *
	 * @return void
	 */
	public function test_inline_scripts_are_not_counted(): void {
		$assets = AssetParser::parse( $this->fixture() );

		foreach ( $assets as $asset ) {
			$this->assertNotSame( '', $asset['url'], 'an asset with no URL is not an asset' );
		}
	}

	/**
	 * A `<link>` that is not a stylesheet is not a stylesheet.
	 *
	 * The fixture has a preconnect to fonts.gstatic.com, which is a hint to the
	 * browser and not a file.
	 *
	 * @return void
	 */
	public function test_a_preconnect_is_not_a_stylesheet(): void {
		$assets = AssetParser::parse( $this->fixture() );

		foreach ( $assets as $asset ) {
			$this->assertStringNotContainsString( 'fonts.gstatic.com', $asset['url'] );
		}
	}

	/**
	 * Entities in a URL are decoded, so two spellings of one URL are one URL.
	 *
	 * @return void
	 */
	public function test_urls_are_decoded(): void {
		$assets = AssetParser::parse( $this->fixture() );

		foreach ( $assets as $asset ) {
			if ( 'google-fonts-1' === $asset['handle'] ) {
				$this->assertStringNotContainsString( '&#038;', $asset['url'] );
				$this->assertStringContainsString( '&display=swap', $asset['url'] );
			}
		}
	}

	/**
	 * Nothing in an empty page.
	 *
	 * @return void
	 */
	public function test_a_page_with_nothing_on_it_yields_nothing(): void {
		$this->assertSame( array(), AssetParser::parse( '<html><body>Hello</body></html>' ) );
	}

	/**
	 * The fixture page.
	 *
	 * @return string
	 */
	private function fixture(): string {
		$html = file_get_contents( WPDEBLOAT_TESTS_ROOT . '/tests/Fixtures/html/stacked-page.html' );

		$this->assertIsString( $html );

		return $html;
	}
}
