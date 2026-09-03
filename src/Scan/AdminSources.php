<?php
/**
 * Who put this here.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan;

use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Attributes an admin callback to whatever registered it (BUILD-SPEC §17
 * Phase 12).
 *
 * "Fourteen admin notices" is a number. "Six of them are from one plugin" is
 * something a person can act on, and it is the difference between a fact that
 * informs and a fact that only alarms.
 *
 * WordPress does not record who added a hook, so the attribution is done by
 * asking the callable where its code lives and matching that against the
 * plugins, mu-plugins and themes directories. That is reliable for a normal
 * install and has two honest failure modes, both of which produce
 * {@see self::UNKNOWN} rather than a guess:
 *
 * - a closure defined in a file that was included from somewhere else;
 * - a callable this code cannot reflect at all.
 *
 * `unknown` is a real answer and appears in the facts as one. A source list that
 * quietly attributed everything to the nearest plausible plugin would be worse
 * than a list that admits what it could not see.
 */
final class AdminSources {

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
