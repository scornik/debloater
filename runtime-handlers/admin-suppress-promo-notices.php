<?php
/**
 * Runtime handler: hide admin notices belonging to named plugins.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Admin_Suppress_Promo_Notices', false ) ) {

	/**
	 * Hides admin notices printed by the plugins the site owner chose.
	 *
	 * **This does not tell marketing from warnings, and does not pretend to.**
	 * The plugins it covers print both from the same hook: an upgrade prompt and
	 * "your database needs updating" arrive by the same route, and no reliable
	 * signal separates them. So this hides everything that plugin says in the
	 * admin notice area, the tweak says exactly that, and the choice is made per
	 * plugin by a person who has read it.
	 *
	 * Two things keep the blast radius where it belongs.
	 *
	 * - A callback is removed only when `plugin_basename()` places the file it
	 *   is defined in under one of the selected slugs. A plugin cannot silence
	 *   another plugin, and a slug the user invented matches no file and
	 *   silences nothing. `plugin_basename()` is core's own answer to "which
	 *   plugin is this file in", including for symlinked plugins, which a
	 *   comparison against a directory string got wrong. It also strips the
	 *   mu-plugins directory, so a mu-plugin kept in a folder named after the
	 *   selected plugin counts as that plugin — the same vendor's loader, in
	 *   practice.
	 * - Nothing is uninstalled, disabled or written to. The notice is not shown
	 *   on this request; unselecting the change brings it back on the next one.
	 *
	 * ## No path, and no option
	 *
	 * This used to check `is_dir( WP_PLUGIN_DIR . '/' . $slug )` before arming
	 * itself, which wordpress.org's round 2 review asked to replace. The
	 * obvious replacement, "is that plugin active", reads `active_plugins` —
	 * and a runtime handler reads no options (BUILD-SPEC §10, invariant 4;
	 * `LoaderTest` greps for it and caught the first attempt). Neither check
	 * was doing anything attribution does not already do: a plugin that is not
	 * active has not loaded, so it has no callbacks to match, and a slug that
	 * names nothing matches no file. So there is no check at all. The cost is
	 * one `admin_head` callback on a request where nothing matches.
	 */
	final class Debloater_Handler_Admin_Suppress_Promo_Notices {

		/**
		 * Hooks a notice can be printed from.
		 *
		 * @var array<int,string>
		 */
		private static $hooks = array(
			'admin_notices',
			'all_admin_notices',
			'network_admin_notices',
			'user_admin_notices',
		);

		/**
		 * Slugs of the plugins whose notices are hidden.
		 *
		 * @var array<int,string>
		 */
		private static $slugs = array();

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters: sources, a list of plugin directory slugs.
		 * @return void
		 */
		public static function register( $params = array() ) {
			self::$slugs = array();

			$sources = isset( $params['sources'] ) && is_array( $params['sources'] ) ? $params['sources'] : array();

			foreach ( $sources as $slug ) {
				// A slug is a single directory name. Anything with a separator
				// in it is not one, and is refused here as well as by the
				// parameter schema.
				if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
					continue;
				}

				self::$slugs[] = $slug;
			}

			if ( array() === self::$slugs ) {
				return;
			}

			// admin_head runs while the document head is being written, which is
			// before admin-header.php reaches the notice hooks in the body.
			add_action( 'admin_head', array( __CLASS__, 'hide_notices' ), 1 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * Notices removed on a request are not restored here: hooks are rebuilt
		 * from scratch on the next one, so there is nothing left to put back.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'admin_head', array( __CLASS__, 'hide_notices' ), 1 );

			self::$slugs = array();
		}

		/**
		 * Detach every notice callback belonging to the named plugins.
		 *
		 * @return void
		 */
		public static function hide_notices() {
			global $wp_filter;

			foreach ( self::$hooks as $hook ) {
				if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
					continue;
				}

				foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $registered ) {
						if ( ! isset( $registered['function'] ) ) {
							continue;
						}

						if ( self::belongs_to_selected( $registered['function'] ) ) {
							remove_action( $hook, $registered['function'], (int) $priority );
						}
					}
				}
			}
		}

		/**
		 * Whether a callback's code lives inside one of the selected plugins.
		 *
		 * @param mixed $callback Callback registered on a notice hook.
		 * @return bool
		 */
		private static function belongs_to_selected( $callback ) {
			$file = self::file_of( $callback );

			if ( null === $file ) {
				// Not attributable, so not ours to remove. Leaving a notice
				// showing is the safe failure here; hiding one nobody asked to
				// hide is not.
				return false;
			}

			$basename = plugin_basename( $file );

			// plugin_basename() hands back the path itself, trimmed of slashes,
			// when the file is in no plugin directory. That is core or a theme,
			// and it is never ours to remove.
			if ( trim( wp_normalize_path( $file ), '/' ) === $basename ) {
				return false;
			}

			$slug = strtok( $basename, '/' );

			return false !== $slug && in_array( $slug, self::$slugs, true );
		}

		/**
		 * Where a callback's code lives, or null when it cannot be established.
		 *
		 * @param mixed $callback Callback registered on a notice hook.
		 * @return string|null
		 */
		private static function file_of( $callback ) {
			try {
				if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
					$parts = explode( '::', $callback, 2 );
					$file  = ( new ReflectionMethod( $parts[0], $parts[1] ) )->getFileName();
				} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
					$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
					$file   = ( new ReflectionMethod( $target, (string) $callback[1] ) )->getFileName();
				} elseif ( is_object( $callback ) && ! $callback instanceof Closure && method_exists( $callback, '__invoke' ) ) {
					$file = ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName();
				} elseif ( is_string( $callback ) || $callback instanceof Closure ) {
					$file = ( new ReflectionFunction( $callback ) )->getFileName();
				} else {
					return null;
				}
			} catch ( ReflectionException $error ) {
				unset( $error );

				return null;
			}

			return false === $file ? null : str_replace( '\\', '/', $file );
		}
	}
}
