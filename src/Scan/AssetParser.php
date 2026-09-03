<?php
/**
 * What a page actually loaded.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan;

/**
 * Pulls the enqueued scripts and styles back out of rendered HTML
 * (BUILD-SPEC §17 Phase 13).
 *
 * The obvious alternative — reading `wp_scripts()->queue` in-process — answers a
 * different question. It says what *would* be enqueued on a request shaped like
 * the one we are already in, which for a scan means an admin request. What a
 * visitor's page actually contains depends on conditional enqueues, on the
 * theme, on caching, and on plugins that dequeue each other. So the page is
 * fetched and read.
 *
 * WordPress gives every enqueued asset an id of `handle-css` or `handle-js`,
 * which is what makes the handle recoverable at all. An asset printed by hand —
 * a theme hard-coding a `<script src>` in header.php — has no id, and is
 * recorded with an empty handle rather than dropped: "something is loading this
 * and nobody enqueued it" is worth knowing, and it is a common cause of a
 * script that no unloading plugin can reach.
 *
 * This parses; it decides nothing. Whether forty scripts is too many is not a
 * question a parser gets to have an opinion about.
 */
final class AssetParser {

	/**
	 * A stylesheet found on a page.
	 */
	public const STYLE = 'style';

	/**
	 * A script found on a page.
	 */
	public const SCRIPT = 'script';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Every script and stylesheet a page loaded.
	 *
	 * Returns one row per asset, in the order they appear, each with its kind,
	 * its handle where WordPress gave it one, and its URL.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int,array{kind:string,handle:string,url:string}>
	 */
	public static function parse( string $html ): array {
		return array_merge( self::styles( $html ), self::scripts( $html ) );
	}

	/**
	 * Stylesheets, from `<link rel="stylesheet">`.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int,array{kind:string,handle:string,url:string}>
	 */
	private static function styles( string $html ): array {
		$rows = array();

		if ( ! preg_match_all( '#<link\b[^>]*>#i', $html, $matches ) ) {
			return $rows;
		}

		foreach ( $matches[0] as $tag ) {
			$rel = self::attribute( $tag, 'rel' );

			if ( 'stylesheet' !== strtolower( $rel ) ) {
				continue;
			}

			$href = self::attribute( $tag, 'href' );

			if ( '' === $href ) {
				continue;
			}

			$rows[] = array(
				'kind'   => self::STYLE,
				'handle' => self::handle( self::attribute( $tag, 'id' ), '-css' ),
				'url'    => $href,
			);
		}

		return $rows;
	}

	/**
	 * Scripts, from `<script src>`.
	 *
	 * Inline scripts are skipped. They have no URL, cannot be attributed to a
	 * file, and are not what this is counting.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int,array{kind:string,handle:string,url:string}>
	 */
	private static function scripts( string $html ): array {
		$rows = array();

		if ( ! preg_match_all( '#<script\b[^>]*>#i', $html, $matches ) ) {
			return $rows;
		}

		foreach ( $matches[0] as $tag ) {
			$src = self::attribute( $tag, 'src' );

			if ( '' === $src ) {
				continue;
			}

			$rows[] = array(
				'kind'   => self::SCRIPT,
				'handle' => self::handle( self::attribute( $tag, 'id' ), '-js' ),
				'url'    => $src,
			);
		}

		return $rows;
	}

	/**
	 * The enqueue handle behind an element id, or an empty string.
	 *
	 * WordPress appends `-css` or `-js`; an id without the suffix was not
	 * printed by the enqueue system and its handle is not recoverable.
	 *
	 * @param string $id     Element id.
	 * @param string $suffix Expected suffix.
	 * @return string
	 */
	private static function handle( string $id, string $suffix ): string {
		if ( '' === $id || ! str_ends_with( $id, $suffix ) ) {
			return '';
		}

		return substr( $id, 0, -strlen( $suffix ) );
	}

	/**
	 * One attribute of one tag, with entities decoded.
	 *
	 * @param string $tag  The whole tag.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function attribute( string $tag, string $name ): string {
		$pattern = '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i';

		if ( ! preg_match( $pattern, $tag, $matches ) ) {
			return '';
		}

		foreach ( array( 1, 2, 3 ) as $group ) {
			if ( isset( $matches[ $group ] ) && '' !== $matches[ $group ] ) {
				return html_entity_decode( $matches[ $group ], ENT_QUOTES, 'UTF-8' );
			}
		}

		return '';
	}
}
