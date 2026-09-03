<?php
/**
 * Runtime handler: hide admin notices belonging to named plugins.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Admin_Suppress_Promo_Notices', false ) ) {

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
	 * - A callback is removed only when the file it is defined in lives inside
	 *   one of the plugin directories that were passed in. A plugin cannot
	 *   silence another plugin, and a slug the user invented silences nothing.
	 * - Nothing is uninstalled, disabled or written to. The notice is not shown
	 *   on this request; unselecting the change brings it back on the next one.
	 */
	final class WPDebloat_Handler_Admin_Suppress_Promo_Notices {

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
		 * Absolute plugin directories whose notices are hidden.
		 *
		 * @var array<int,string>
		 */
		private static $directories = array();

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters: sources, a list of plugin directory slugs.
		 * @return void
		 */
		public static function register( $params = array() ) {
			self::$directories = array();

			$sources = isset( $params['sources'] ) && is_array( $params['sources'] ) ? $params['sources'] : array();

			foreach ( $sources as $slug ) {
				// A slug is a single directory name. Anything with a separator
				// in it is not one, and is refused here as well as by the
				// parameter schema.
				if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
					continue;
				}

				$directory = WP_PLUGIN_DIR . '/' . $slug;

				if ( is_dir( $directory ) ) {
					self::$directories[] = str_replace( '\\', '/', $directory ) . '/';
				}
			}

			if ( array() === self::$directories ) {
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

			self::$directories = array();
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

			foreach ( self::$directories as $directory ) {
				if ( 0 === strpos( $file, $directory ) ) {
					return true;
				}
			}

			return false;
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
