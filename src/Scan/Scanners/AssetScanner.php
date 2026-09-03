<?php
/**
 * Facts about what pages actually load.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;
use WPDebloat\Scan\AssetParser;
use WPDebloat\Scan\SampledPages;
use WPDebloat\Scan\Sources;

/**
 * Collects the `assets.*` facts (BUILD-SPEC §5, §17 Phase 13).
 *
 * The pages come from {@see SampledPages}, which fetches them once and lends
 * them to every scanner that reads pages — the WooCommerce scan needs the same
 * bodies, and fetching them twice would double a scan's loopback traffic to
 * learn nothing new. Every request is to this site, and there is a test that
 * fails if one is not (§13 rule 9).
 *
 * Three properties are load-bearing.
 *
 * **It gives up rather than hanging.** Loopback is checked first, and a site
 * that cannot reach itself produces `assets.available = false` and a reason
 * instead of ten timeouts. There is a wall-clock budget across the fetch, and
 * the number of pages actually read is recorded, so a partial sample is visible
 * as a partial sample.
 *
 * **It measures a sample, and says so.** `assets.pages_sampled` travels with
 * every other fact here precisely so that no rule can turn "on all four pages we
 * looked at" into "on every page".
 *
 * **It detects and proposes nothing.** Phase 13 adds no unloading tweaks at all,
 * and the Assets sub-score stays out of the score. Deciding that a script is
 * unnecessary needs to know what the page does, which is the next problem, not
 * this one.
 */
final class AssetScanner extends AbstractScanner {

	/**
	 * Hosts that serve Google Fonts.
	 */
	private const GOOGLE_FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	/**
	 * The page sample, shared with every other scanner that reads pages.
	 *
	 * @var SampledPages
	 */
	private SampledPages $sample;

	/**
	 * Constructor.
	 *
	 * @param SampledPages|null $sample Page sample to read.
	 */
	public function __construct( ?SampledPages $sample = null ) {
		$this->sample = $sample ?? new SampledPages();
	}

	/**
	 * Forget the fetched pages.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->sample->forget();
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'assets';
	}

	/**
	 * Collect asset facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		if ( ! $this->sample->available( $context ) ) {
			return array(
				'assets.available'          => false,
				'assets.unavailable_reason' => $this->sample->reason( $context ),
				'assets.pages_sampled'      => 0,
			);
		}

		$pages  = $this->sample->pages( $context );
		$assets = array();
		$types  = array();
		$cf7    = 0;
		$forms  = 0;

		foreach ( $pages as $page ) {
			$found = AssetParser::parse( $page['body'] );

			foreach ( $found as $asset ) {
				$assets[] = $asset;
			}

			$types[] = $page['post_type'];

			if ( $this->hasCf7Assets( $found ) ) {
				++$cf7;
			}

			if ( $this->hasCf7Form( $page['body'] ) ) {
				++$forms;
			}
		}

		return array_merge(
			array(
				'assets.available'       => true,
				'assets.pages_sampled'   => count( $pages ),
				'assets.pages_offered'   => $this->sample->offered( $context ),
				'assets.elapsed_ms'      => $this->sample->elapsedMs( $context ),
				'assets.post_types'      => $this->uniqueSorted( $types ),
				'assets.cf7_asset_pages' => $cf7,
				'assets.cf7_form_pages'  => $forms,
			),
			$this->summarise( $assets )
		);
	}

	/**
	 * Turn the raw asset rows into the facts.
	 *
	 * One row per distinct handle-or-URL, with how many of the sampled pages it
	 * appeared on. An asset on one page out of six is a different thing from one
	 * on all six, and the count is the whole difference.
	 *
	 * @param array<int,array{kind:string,handle:string,url:string}> $assets Raw rows.
	 * @return array<string,mixed>
	 */
	private function summarise( array $assets ): array {
		$by_key = array();
		$hosts  = array();
		$fonts  = false;

		foreach ( $assets as $asset ) {
			$url  = $asset['url'];
			$host = Sources::externalHost( $url );

			if ( null !== $host ) {
				$hosts[ $host ] = ( $hosts[ $host ] ?? 0 ) + 1;

				if ( in_array( $host, self::GOOGLE_FONT_HOSTS, true ) ) {
					$fonts = true;
				}
			}

			$key = $asset['kind'] . '|' . ( '' !== $asset['handle'] ? $asset['handle'] : $this->normalise( $url ) );

			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = array(
					'kind'   => $asset['kind'],
					'handle' => $asset['handle'],
					'source' => Sources::fromUrl( $url ),
					'bytes'  => Sources::bytesOfUrl( $url ),
					'pages'  => 0,
				);
			}

			++$by_key[ $key ]['pages'];
		}

		ksort( $by_key, SORT_STRING );

		$scripts = array();
		$styles  = array();

		foreach ( $by_key as $row ) {
			$entry = array(
				'handle' => $row['handle'],
				'source' => $row['source'],
				'pages'  => $row['pages'],
				'bytes'  => $row['bytes'],
			);

			if ( AssetParser::SCRIPT === $row['kind'] ) {
				$scripts[] = $entry;
			} else {
				$styles[] = $entry;
			}
		}

		arsort( $hosts, SORT_NUMERIC );

		$external = array();

		foreach ( $hosts as $host => $count ) {
			$external[] = array(
				'host'  => (string) $host,
				'count' => (int) $count,
			);
		}

		return array(
			'assets.scripts'        => $scripts,
			'assets.scripts.count'  => count( $scripts ),
			'assets.styles'         => $styles,
			'assets.styles.count'   => count( $styles ),
			'assets.external_hosts' => $external,
			'assets.google_fonts'   => $fonts,
		);
	}

	/**
	 * Whether a page loaded Contact Form 7's own assets.
	 *
	 * @param array<int,array{kind:string,handle:string,url:string}> $assets Assets on the page.
	 * @return bool
	 */
	private function hasCf7Assets( array $assets ): bool {
		foreach ( $assets as $asset ) {
			if ( 0 === strpos( $asset['handle'], 'contact-form-7' ) ) {
				return true;
			}

			if ( false !== strpos( $asset['url'], '/contact-form-7/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a page actually contains a Contact Form 7 form.
	 *
	 * Read from the rendered markup rather than from the post content, because
	 * a form can arrive through a shortcode, a block, a widget, a template part
	 * or a page builder, and the rendered page is the one place all of those end
	 * up looking the same.
	 *
	 * @param string $html Rendered HTML.
	 * @return bool
	 */
	private function hasCf7Form( string $html ): bool {
		return false !== strpos( $html, 'wpcf7' ) && false !== stripos( $html, '<form' );
	}

	/**
	 * A URL reduced to something stable enough to count with.
	 *
	 * @param string $url Asset URL.
	 * @return string
	 */
	private function normalise( string $url ): string {
		$without_query = strtok( $url, '?' );

		return false === $without_query ? $url : $without_query;
	}

	/**
	 * Unique values in a fixed order.
	 *
	 * @param array<int,string> $values Values.
	 * @return array<int,string>
	 */
	private function uniqueSorted( array $values ): array {
		$unique = array_values( array_unique( $values ) );

		sort( $unique, SORT_STRING );

		return $unique;
	}
}
