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
 * wordpress.org review round 1: `ABSPATH . $root_relative_url` is wrong.
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
