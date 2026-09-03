<?php
/**
 * What a page actually asks the browser to fetch.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Meter;

/**
 * Counts taken from a rendered page (BUILD-SPEC §12).
 *
 * These are read from the HTML the site served, not from WordPress's own
 * registries. `wp_scripts()->queue` says what WordPress intended to print; the
 * page says what it printed. Where they disagree — a plugin echoing a tag
 * directly, a cache serving something older — the page is the thing a visitor
 * receives, and the page is what gets counted.
 *
 * Parsed with a regular expression rather than DOMDocument on purpose: this
 * runs against markup from other people's themes, and a strict parser that
 * throws on invalid HTML would make a measurement fail on exactly the sites
 * that most need measuring.
 */
final class PageMetrics {

	/**
	 * The page body.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * The host the page was served from.
	 *
	 * @var string
	 */
	private string $host;

	/**
	 * Constructor.
	 *
	 * @param string $html The page body.
	 * @param string $url  The URL it came from.
	 */
	public function __construct( string $html, string $url = '' ) {
		$this->html = $html;
		$this->host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Script tags with a src.
	 *
	 * @return int
	 */
	public function scripts(): int {
		return count( $this->sources( '/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i' ) );
	}

	/**
	 * Stylesheet links.
	 *
	 * @return int
	 */
	public function styles(): int {
		// Both attribute orders, because a theme may write either. Merged by
		// value: two lists combined with `+` would keep only the first, which is
		// a silent undercount rather than a visible error.
		return count(
			array_unique(
				array_merge(
					$this->sources( '/<link\b[^>]*\brel\s*=\s*["\']stylesheet["\'][^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i' ),
					$this->sources( '/<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*\brel\s*=\s*["\']stylesheet["\']/i' )
				)
			)
		);
	}

	/**
	 * Everything the page asks the browser to fetch: scripts, stylesheets and
	 * images.
	 *
	 * The page itself is counted, because a visitor's browser makes that request
	 * too and a count that ignored it would be describing something other than
	 * what happens.
	 *
	 * @return int
	 */
	public function requests(): int {
		return 1 + $this->scripts() + $this->styles() + count( $this->sources( '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i' ) );
	}

	/**
	 * Bytes between <head> and </head>.
	 *
	 * @return int
	 */
	public function headBytes(): int {
		if ( 1 !== preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $this->html, $matches ) ) {
			return 0;
		}

		return strlen( $matches[1] );
	}

	/**
	 * Distinct hosts other than this site's own.
	 *
	 * @return int
	 */
	public function externalHosts(): int {
		$hosts = array();

		foreach ( $this->allUrls() as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( '' === $host || $host === $this->host ) {
				continue;
			}

			$hosts[ $host ] = true;
		}

		return count( $hosts );
	}

	/**
	 * Elements WordPress renders as admin notices.
	 *
	 * @return int
	 */
	public function adminNotices(): int {
		return preg_match_all( '/class\s*=\s*["\'][^"\']*\bnotice\b[^"\']*["\']/i', $this->html );
	}

	/**
	 * Every URL the page references.
	 *
	 * @return array<int,string>
	 */
	private function allUrls(): array {
		return array_merge(
			$this->sources( '/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i' ),
			$this->sources( '/<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i' ),
			$this->sources( '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i' ),
			$this->sources( '/<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i' )
		);
	}

	/**
	 * The first capture group of every match.
	 *
	 * @param string $pattern Regular expression with one capture group.
	 * @return array<int,string>
	 */
	private function sources( string $pattern ): array {
		if ( false === preg_match_all( $pattern, $this->html, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ?? array() ) );
	}
}
