<?php
/**
 * Resolving asset URLs to files, including where WordPress is not at the root.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Scan\Sources;

/**
 * wordpress.org review rounds 1 and 2: asset attribution without the disk.
 *
 * Round 2 went further than round 1 below: asset URLs are no longer mapped to
 * filesystem paths at all, but compared with the URLs WordPress reports for
 * its own directories (D-0076). The round-1 cases still stand, and the tests
 * after them pin what the URL-space version has to get right on its own.
 *
 * Round 1: `ABSPATH . $root_relative_url` is wrong.
 *
 * A subdirectory install has WordPress in, say, `/blog`. Its ABSPATH ends in
 * `/blog/` and its root-relative URLs *begin* with `/blog`, so gluing the two
 * together names `/var/www/html/blog/blog/wp-includes/...` — a path that does
 * not exist, on every site of that shape.
 *
 * The second bug found while fixing it was worse, because it misreports rather
 * than failing to report: `AdminScanner` classified anything starting `/wp-` as
 * core, and `/wp-content/plugins/…` starts with `/wp-`. Every plugin asset
 * enqueued with a root-relative source was attributed to WordPress itself.
 *
 * These run against the real site, whose URLs the test filters rather than
 * assumes — asserting against `home_url()` and friends rather than a hardcoded
 * host, because the suite has been bitten by that before.
 */
final class SubdirectoryPathsTest extends IntegrationTestCase {

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'site_url' );
		remove_all_filters( 'home_url' );
		remove_all_filters( 'content_url' );
		remove_all_filters( 'includes_url' );
		remove_all_filters( 'admin_url' );
		remove_all_filters( 'plugins_url' );

		Sources::reset();

		parent::tear_down();
	}

	/**
	 * A root-relative core URL resolves under wp-includes, not beside it.
	 *
	 * @return void
	 */
	public function test_a_root_relative_core_url_is_core(): void {
		$this->assertSame(
			Sources::CORE,
			Sources::fromUrl( '/wp-includes/js/jquery/jquery.min.js' )
		);
	}

	/**
	 * A root-relative plugin URL is not core.
	 *
	 * The misattribution, pinned. `/wp-content/...` begins with `/wp-`, and the
	 * old classifier stopped there.
	 *
	 * @return void
	 */
	public function test_a_root_relative_plugin_url_is_not_core(): void {
		$source = Sources::fromUrl( '/wp-content/plugins/debloater/admin-ui/build/index.js' );

		$this->assertNotSame(
			Sources::CORE,
			$source,
			'a plugin asset must not be reported as WordPress itself'
		);
	}

	/**
	 * The same, through the scanner that had its own copy of the logic.
	 *
	 * @return void
	 */
	public function test_the_admin_scanner_agrees(): void {
		$scanner = new \Debloater\Scan\Scanners\AdminScanner( $this->plugin->registry() );

		$method = new \ReflectionMethod( $scanner, 'assetSource' );
		$method->setAccessible( true );

		$this->assertNotSame(
			Sources::CORE,
			$method->invoke( $scanner, '/wp-content/plugins/debloater/admin-ui/build/index.js' ),
			'the admin scanner must not attribute a plugin asset to WordPress'
		);

		$this->assertSame(
			Sources::CORE,
			$method->invoke( $scanner, '/wp-includes/js/jquery/jquery.min.js' )
		);
	}

	/**
	 * With WordPress in a subdirectory, root-relative URLs still resolve.
	 *
	 * The site's URLs are moved under `/blog` and ABSPATH is left alone, which
	 * is exactly the shape of a real subdirectory install: WordPress's files sit
	 * in a folder and its URLs carry that folder's name.
	 *
	 * @return void
	 */
	public function test_a_subdirectory_install_resolves_root_relative_urls(): void {
		$this->moveSiteInto( '/blog' );

		$this->assertSame(
			Sources::CORE,
			Sources::fromUrl( '/blog/wp-includes/js/jquery/jquery.min.js' ),
			'a core asset on a subdirectory install should still be core'
		);

		$this->assertNotSame(
			Sources::CORE,
			Sources::fromUrl( '/blog/wp-content/plugins/debloater/admin-ui/build/index.js' ),
			'a plugin asset on a subdirectory install must not be core'
		);
	}

	/**
	 * And a URL that is not under the subdirectory is not ours.
	 *
	 * The old code would have appended it to ABSPATH and produced a path that
	 * belongs to nobody. Saying "unknown" is the honest answer.
	 *
	 * @return void
	 */
	public function test_a_url_outside_the_subdirectory_is_unknown(): void {
		$this->moveSiteInto( '/blog' );

		$this->assertSame(
			Sources::UNKNOWN,
			Sources::fromUrl( '/somebody-elses-app/bundle.js' )
		);
	}

	/**
	 * A plugin asset is attributed to that plugin, not merely "not core".
	 *
	 * Absolute and root-relative, because the scanners see both.
	 *
	 * @return void
	 */
	public function test_a_plugin_asset_is_attributed_to_its_plugin(): void {
		$this->assertSame( 'woocommerce', Sources::fromUrl( plugins_url( 'woocommerce/assets/js/x.js' ) ) );

		$path = (string) wp_parse_url( plugins_url( 'woocommerce/assets/js/x.js' ), PHP_URL_PATH );

		$this->assertSame( 'woocommerce', Sources::fromUrl( $path ) );
		$this->assertSame( 'woocommerce', Sources::fromUrl( $path . '?ver=9.1' ) );
	}

	/**
	 * Themes, includes and the admin, each by the URL WordPress reports.
	 *
	 * @return void
	 */
	public function test_each_base_is_classified(): void {
		$this->assertSame( Sources::THEME, Sources::fromUrl( get_theme_root_uri() . '/storefront/style.css' ) );
		$this->assertSame( Sources::CORE, Sources::fromUrl( includes_url( 'css/dashicons.min.css' ) ) );
		$this->assertSame( Sources::CORE, Sources::fromUrl( admin_url( 'css/common.min.css' ) ) );
	}

	/**
	 * Under wp-content but outside plugins and themes is nobody's.
	 *
	 * Uploads and cache directories hold files something generated, and
	 * nothing records what. The old path mapping called these core, because
	 * WP_CONTENT_DIR sits inside ABSPATH.
	 *
	 * @return void
	 */
	public function test_uploads_are_not_attributed(): void {
		$this->assertSame( Sources::UNKNOWN, Sources::fromUrl( content_url( 'uploads/elementor/css/post-12.css' ) ) );
	}

	/**
	 * A prefix match stops at a path segment.
	 *
	 * `/wp-content/plugins-extra/` begins with `/wp-content/plugins`.
	 *
	 * @return void
	 */
	public function test_a_prefix_is_matched_on_a_segment_boundary(): void {
		$this->assertNotSame( 'x', Sources::fromUrl( plugins_url() . '-extra/x/y.js' ) );
		$this->assertSame( Sources::UNKNOWN, Sources::fromUrl( plugins_url() . '-extra/x/y.js' ) );
	}

	/**
	 * A content URL on another host still attributes.
	 *
	 * `WP_CONTENT_URL` pointed at a CDN is a normal configuration. By path
	 * mapping those assets were "external" and unknown; by URL they are the
	 * plugins they came from.
	 *
	 * @return void
	 */
	public function test_a_cdn_content_url_still_attributes(): void {
		add_filter( 'plugins_url', static fn (): string => 'https://cdn.example.net/wp-content/plugins' );

		Sources::reset();

		$this->assertSame(
			'contact-form-7',
			Sources::fromUrl( 'https://cdn.example.net/wp-content/plugins/contact-form-7/includes/js/index.js' )
		);

		// The scheme is not part of the identity.
		$this->assertSame(
			'contact-form-7',
			Sources::fromUrl( 'http://CDN.example.net/wp-content/plugins/contact-form-7/includes/js/index.js' )
		);
	}

	/**
	 * The asset half of Sources never touches the filesystem.
	 *
	 * The reviewer reads the source, so this does too: every method the URL
	 * attribution goes through, by line range, must not name a directory
	 * constant or a filesystem function. `of()` and `roots()` are deliberately
	 * not in the list; see the docblock on `of()` for why.
	 *
	 * @return void
	 */
	public function test_url_attribution_names_no_filesystem_path(): void {
		$lines = file( ( new \ReflectionClass( Sources::class ) )->getFileName() );

		$this->assertIsArray( $lines );

		$methods = array( 'fromUrl', 'externalHost', 'classify', 'under', 'withoutScheme', 'bases' );
		$checked = 0;

		foreach ( $methods as $name ) {
			$method = new \ReflectionMethod( Sources::class, $name );
			$body   = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

			foreach ( array( 'ABSPATH', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR', 'get_theme_root(', 'is_file', 'filesize', 'file_exists', 'realpath' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $body, sprintf( 'Sources::%s() names %s', $name, $needle ) );
			}

			$checked += strlen( $body );
		}

		$this->assertGreaterThan( 1000, $checked, 'the method bodies were not read' );

		$this->assertFalse( method_exists( Sources::class, 'bytesOfUrl' ), 'the per-asset size read off the disk is gone' );
		$this->assertFalse( method_exists( Sources::class, 'pathOfUrl' ), 'URLs are not mapped to paths' );
	}

	/**
	 * Pretend WordPress lives in a subdirectory.
	 *
	 * @param string $prefix Leading-slash path, such as `/blog`.
	 * @return void
	 */
	private function moveSiteInto( string $prefix ): void {
		$base = untrailingslashit( home_url() );

		foreach ( array( 'site_url', 'home_url' ) as $hook ) {
			add_filter(
				$hook,
				static function ( string $url ) use ( $base, $prefix ): string {
					return $base . $prefix . substr( $url, strlen( $base ) );
				}
			);
		}

		foreach ( array( 'content_url', 'includes_url', 'admin_url' ) as $hook ) {
			add_filter(
				$hook,
				static function ( string $url ) use ( $base, $prefix ): string {
					return 0 === strpos( $url, $base . $prefix )
						? $url
						: $base . $prefix . substr( $url, strlen( $base ) );
				}
			);
		}

		Sources::reset();
	}
}
