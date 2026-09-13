<?php
/**
 * Who does this belong to.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan;

use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Attributes a callback, a file or a URL to whatever it belongs to
 * (BUILD-SPEC §17 Phases 12 and 13).
 *
 * "Fourteen admin notices" is a number. "Six of them are from one plugin" is
 * something a person can act on, and it is the difference between a fact that
 * informs and a fact that only alarms. The same is true of the forty scripts on
 * a page.
 *
 * Two questions, answered in two different spaces, and kept apart on purpose.
 *
 * **An asset is attributed by its URL, against URLs.** `fromUrl()` compares
 * the asset's address with the addresses WordPress itself reports for plugins,
 * mu-plugins, themes, includes and the admin — `plugins_url()`,
 * `get_theme_root_uri()`, `includes_url()`, `admin_url()`, `content_url()`.
 * It never turns a URL into a file path. It used to, by gluing a URL onto
 * `ABSPATH` or `WP_CONTENT_DIR`, which wordpress.org's review rightly refused:
 * the mapping between the two is the web server's business, not the plugin's,
 * and it was wrong on every subdirectory install before it was fixed. The one
 * thing it was needed for, a per-asset size read off the disk, no rule read,
 * and it went with it (D-0076).
 *
 * **A hook callback is attributed by the file its code is in.** See
 * {@see self::of()} for why that one has no URL form.
 *
 * Both have honest failure modes, and all of them produce {@see self::UNKNOWN}
 * rather than a guess:
 *
 * - a closure defined in a file that was included from somewhere else;
 * - a callable this code cannot reflect at all;
 * - an asset served from a CDN or another host WordPress does not report as
 *   one of its own;
 * - an asset under `wp-content` but outside plugins and themes — uploads, a
 *   cache directory — which something wrote, but nothing records what.
 *
 * `unknown` is a real answer and appears in the facts as one. A source list that
 * quietly attributed everything to the nearest plausible plugin would be worse
 * than a list that admits what it could not see.
 */
final class Sources {

	/**
	 * The answer when the owner cannot be established.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * WordPress itself.
	 */
	public const CORE = 'wordpress';

	/**
	 * The active theme.
	 */
	public const THEME = 'theme';

	/**
	 * Cached directory prefixes, resolved once. Callables only.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $roots = null;

	/**
	 * Cached URL prefixes, resolved once. Assets only.
	 *
	 * @var array<int,array{kind:string,url:string,path:string}>|null
	 */
	private static ?array $bases = null;

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * The source of a callable, as a slug.
	 *
	 * ## Why this one reads the directory constants
	 *
	 * Attributing a hook callback is a filesystem question with no URL form.
	 * WordPress does not record who added a hook; the only evidence is where
	 * the callback's code lives, and PHP reports that as a file path through
	 * reflection. There is no URL to compare, because PHP files are not served.
	 *
	 * So {@see self::roots()} reads `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`,
	 * `get_theme_root()` and `ABSPATH` — reads them, to compare a path PHP
	 * already gave us against where WordPress says those directories are. It
	 * never concatenates a URL onto one, never builds a path to open, and
	 * nothing it computes is read, written or included. That is a different
	 * thing from what wordpress.org flagged in round 2, which was mapping asset
	 * URLs onto the disk; that is gone (see {@see self::fromUrl()}).
	 *
	 * @param mixed $callback Anything WordPress would accept as a callback.
	 * @return string
	 */
	public static function of( mixed $callback ): string {
		$file = self::fileOf( $callback );

		return null === $file ? self::UNKNOWN : self::fromPath( $file );
	}

	/**
	 * The source of a file path, as a slug.
	 *
	 * For paths PHP reports — reflection, `__FILE__` — never for URLs.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string
	 */
	public static function fromPath( string $path ): string {
		$normalised = str_replace( '\\', '/', $path );

		foreach ( self::roots() as $kind => $root ) {
			if ( '' === $root || 0 !== strpos( $normalised, $root ) ) {
				continue;
			}

			$remainder = substr( $normalised, strlen( $root ) );
			$segment   = strtok( ltrim( $remainder, '/' ), '/' );

			if ( 'plugins' === $kind || 'mu_plugins' === $kind ) {
				// A single-file plugin has no directory of its own, so its file
				// name is its slug — which is also how wordpress.org names it.
				return false === $segment ? self::UNKNOWN : self::slug( $segment );
			}

			return 'themes' === $kind ? self::THEME : self::CORE;
		}

		return self::UNKNOWN;
	}

	/**
	 * The source of an asset URL, as a slug.
	 *
	 * Matched against the URLs WordPress reports for its own directories,
	 * most specific first, and never mapped to a file. An absolute URL is
	 * compared whole first, so a `content_url()` on another host — a CDN
	 * configured through `WP_CONTENT_URL` — still attributes; then any URL on
	 * this site, absolute or root-relative, is compared by path, which is what
	 * makes a subdirectory install work without special cases.
	 *
	 * Anything else is not WordPress's to vouch for and is reported as unknown.
	 * `externalHost()` is the question to ask about those.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return string
	 */
	public static function fromUrl( string $url ): string {
		$url = (string) strtok( $url, '?#' );

		if ( '' === $url ) {
			return self::UNKNOWN;
		}

		$bases = self::bases();

		// Whole URL, scheme ignored: `http://` and `https://` name the same
		// asset, and a site behind a proxy reports one while printing the other.
		$bare = self::withoutScheme( $url );

		if ( null !== $bare ) {
			foreach ( $bases as $base ) {
				$prefix = self::withoutScheme( $base['url'] );

				if ( null !== $prefix && self::under( $bare, $prefix ) ) {
					return self::classify( $base['kind'], substr( $bare, strlen( $prefix ) ) );
				}
			}
		}

		if ( null !== self::externalHost( $url ) ) {
			return self::UNKNOWN;
		}

		$path = 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' )
			? $url
			: wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return self::UNKNOWN;
		}

		// By path length this time. Sorting by whole URL above put a CDN-hosted
		// content_url() wherever its host name happened to fall, which says
		// nothing about how specific its path is.
		usort(
			$bases,
			static fn ( array $left, array $right ): int => strlen( $right['path'] ) <=> strlen( $left['path'] )
		);

		foreach ( $bases as $base ) {
			if ( '' !== $base['path'] && self::under( $path, $base['path'] ) ) {
				return self::classify( $base['kind'], substr( $path, strlen( $base['path'] ) ) );
			}
		}

		return self::UNKNOWN;
	}

	/**
	 * The host an asset is fetched from when it is not this site, or null.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return string|null
	 */
	public static function externalHost( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			// Root-relative, so this site.
			return null;
		}

		$ours = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $ours ) && strtolower( $host ) === strtolower( $ours ) ? null : strtolower( $host );
	}

	/**
	 * What a match against one base means.
	 *
	 * @param string $kind      Which base matched.
	 * @param string $remainder The URL after the base, starting with `/`.
	 * @return string
	 */
	private static function classify( string $kind, string $remainder ): string {
		switch ( $kind ) {
			case 'plugins':
			case 'mu_plugins':
				// The first segment is the plugin's directory, or for a
				// single-file mu-plugin its file — which is also how
				// wordpress.org names it.
				$segment = strtok( ltrim( $remainder, '/' ), '/' );

				return false === $segment ? self::UNKNOWN : self::slug( $segment );

			case 'themes':
				return self::THEME;

			case 'includes':
			case 'admin':
				return self::CORE;

			default:
				// Under wp-content but in none of the directories above:
				// uploads, a cache plugin's output, a page builder's generated
				// CSS. Something put it there, and nothing records what.
				return self::UNKNOWN;
		}
	}

	/**
	 * Whether a URL or path sits under a prefix, on a segment boundary.
	 *
	 * `/wp-content/plugins-extra/x.js` is not under `/wp-content/plugins`, which
	 * a bare `strpos()` would say it is.
	 *
	 * @param string $value  URL or path.
	 * @param string $prefix Prefix with no trailing slash.
	 * @return bool
	 */
	private static function under( string $value, string $prefix ): bool {
		return '' !== $prefix && 0 === strpos( $value, $prefix . '/' );
	}

	/**
	 * `//host/path` from an absolute URL, host lowercased, or null when the URL
	 * is not absolute.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private static function withoutScheme( string $url ): ?string {
		if ( 1 !== preg_match( '~^(?:[a-z][a-z0-9+.-]*:)?//([^/?#]+)(.*)$~i', $url, $match ) ) {
			return null;
		}

		return '//' . strtolower( $match[1] ) . rtrim( $match[2], '/' );
	}

	/**
	 * The URLs WordPress reports for the places an asset can come from.
	 *
	 * Most specific first: mu-plugins and plugins sit inside `content_url()`,
	 * and a site root would otherwise swallow everything. The site root itself
	 * is deliberately absent — a file beside WordPress that is in none of these
	 * belongs to nobody WordPress knows about.
	 *
	 * @return array<int,array{kind:string,url:string,path:string}>
	 */
	private static function bases(): array {
		if ( null !== self::$bases ) {
			return self::$bases;
		}

		$urls = array(
			// A constant holding a URL, not a path. There is no function for
			// it: `plugins_url()` answers for the plugins directory, and passing
			// it a mu-plugin needs a path to one.
			'mu_plugins' => defined( 'WPMU_PLUGIN_URL' ) ? (string) constant( 'WPMU_PLUGIN_URL' ) : content_url( 'mu-plugins' ),
			'plugins'    => plugins_url(),
			'themes'     => get_theme_root_uri(),
			'includes'   => includes_url(),
			'admin'      => admin_url(),
			'content'    => content_url(),
		);

		$bases = array();

		foreach ( $urls as $kind => $url ) {
			$url  = rtrim( (string) $url, '/' );
			$path = wp_parse_url( $url, PHP_URL_PATH );

			if ( '' === $url ) {
				continue;
			}

			$bases[] = array(
				'kind' => $kind,
				'url'  => $url,
				'path' => rtrim( is_string( $path ) ? $path : '', '/' ),
			);
		}

		usort(
			$bases,
			static fn ( array $left, array $right ): int => strlen( $right['url'] ) <=> strlen( $left['url'] )
		);

		self::$bases = $bases;

		return self::$bases;
	}

	/**
	 * Where a callable's code lives, or null when it cannot be established.
	 *
	 * @param mixed $callback Anything WordPress would accept as a callback.
	 * @return string|null
	 */
	private static function fileOf( mixed $callback ): ?string {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$parts = explode( '::', $callback, 2 );

				return self::orNull( ( new ReflectionMethod( $parts[0], $parts[1] ) )->getFileName() );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

				return self::orNull( ( new ReflectionMethod( $target, (string) $callback[1] ) )->getFileName() );
			}

			if ( is_object( $callback ) && ! $callback instanceof \Closure && method_exists( $callback, '__invoke' ) ) {
				return self::orNull( ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName() );
			}

			if ( is_string( $callback ) || $callback instanceof \Closure ) {
				return self::orNull( ( new ReflectionFunction( $callback ) )->getFileName() );
			}
		} catch ( ReflectionException $error ) {
			unset( $error );

			return null;
		}

		return null;
	}

	/**
	 * A reflection file name, or null when there is not one.
	 *
	 * @param string|false $file What reflection reported.
	 * @return string|null
	 */
	private static function orNull( string|false $file ): ?string {
		return false === $file || '' === $file ? null : $file;
	}

	/**
	 * The directories a callable's file can live in, longest first.
	 *
	 * Used by {@see self::of()} only, and only compared against — see there.
	 *
	 * mu-plugins is checked before plugins because on most installs it sits
	 * inside the plugins directory's parent, and on some it sits inside a path
	 * that would also match a less specific root.
	 *
	 * @return array<string,string>
	 */
	private static function roots(): array {
		if ( null !== self::$roots ) {
			return self::$roots;
		}

		$roots = array(
			'mu_plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'plugins'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'themes'     => get_theme_root(),
			'core'       => defined( 'ABSPATH' ) ? ABSPATH : '',
		);

		foreach ( $roots as $kind => $path ) {
			$roots[ $kind ] = '' === (string) $path ? '' : rtrim( str_replace( '\\', '/', (string) $path ), '/' ) . '/';
		}

		// Longest first, so wp-content/plugins wins over the ABSPATH it is
		// inside.
		uasort(
			$roots,
			static fn ( string $left, string $right ): int => strlen( $right ) <=> strlen( $left )
		);

		self::$roots = $roots;

		return self::$roots;
	}

	/**
	 * A file or directory name reduced to a slug.
	 *
	 * @param string $name File or directory name.
	 * @return string
	 */
	private static function slug( string $name ): string {
		$slug = strtolower( basename( $name, '.php' ) );
		$slug = (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug );

		$trimmed = trim( $slug, '-' );

		return '' === $trimmed ? self::UNKNOWN : $trimmed;
	}

	/**
	 * Forget the cached roots and bases.
	 *
	 * Only tests need this; a request has one set of directories.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$roots = null;
		self::$bases = null;
	}
}
