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
 * Everything here resolves to a path and matches it against the plugins,
 * mu-plugins and themes directories. For a hook, WordPress does not record who
 * added it, so the callable is asked where its code lives; for an enqueued asset
 * the URL is mapped back to the file it is served from.
 *
 * Both have honest failure modes, and all of them produce {@see self::UNKNOWN}
 * rather than a guess:
 *
 * - a closure defined in a file that was included from somewhere else;
 * - a callable this code cannot reflect at all;
 * - an asset served from a CDN or another host, which belongs to somebody but
 *   not to a directory on this disk.
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
	 * Cached directory prefixes, resolved once.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $roots = null;

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * The source of a callable, as a slug.
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
	 * @param string $path Absolute path, or a URL under one of the known roots.
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
	 * A URL under this site's own content, includes or admin directories maps
	 * back to the file it is served from. Anything else — a CDN, a font service,
	 * an analytics host — is not on this disk and is reported as unknown, which
	 * is exactly what it is. `externalHost()` is the question to ask about those.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return string
	 */
	public static function fromUrl( string $url ): string {
		$path = self::pathOfUrl( $url );

		return null === $path ? self::UNKNOWN : self::fromPath( $path );
	}

	/**
	 * The size on disk of an asset served from this site, or null.
	 *
	 * Read from the filesystem rather than fetched, which is both exact and
	 * free. An asset on another host has no answer here, and inventing one from
	 * a HEAD request nobody asked for would be a request nobody asked for.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return int|null
	 */
	public static function bytesOfUrl( string $url ): ?int {
		$path = self::pathOfUrl( $url );

		if ( null === $path || ! is_file( $path ) ) {
			return null;
		}

		$size = filesize( $path );

		return false === $size ? null : (int) $size;
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
	 * The file an asset URL is served from, or null when it is not local.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return string|null
	 */
	private static function pathOfUrl( string $url ): ?string {
		$url = strtok( $url, '?' );

		if ( false === $url ) {
			return null;
		}

		if ( null !== self::externalHost( $url ) ) {
			return null;
		}

		// WPINC is a WordPress constant, so it is read rather than referenced:
		// this class is also loaded by the unit suite, which runs with no
		// WordPress at all.
		$includes = defined( 'WPINC' ) ? (string) constant( 'WPINC' ) : 'wp-includes';

		$candidates = array(
			content_url()   => WP_CONTENT_DIR,
			includes_url()  => ABSPATH . $includes,
			admin_url()     => ABSPATH . 'wp-admin',
			site_url( '/' ) => ABSPATH,
		);

		foreach ( $candidates as $prefix => $directory ) {
			$prefix = rtrim( (string) $prefix, '/' );

			if ( '' !== $prefix && 0 === strpos( $url, $prefix ) ) {
				return rtrim( (string) $directory, '/' ) . substr( $url, strlen( $prefix ) );
			}
		}

		// Root-relative: /wp-includes/js/jquery/jquery.min.js
		if ( 0 === strpos( $url, '/' ) ) {
			return rtrim( ABSPATH, '/' ) . $url;
		}

		return null;
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
	 * The directories a source can live in, longest first.
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
	 * Forget the cached roots.
	 *
	 * Only tests need this; a request has one set of directories.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$roots = null;
	}
}
