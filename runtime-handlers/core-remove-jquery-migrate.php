<?php
/**
 * Runtime handler: stop loading jQuery Migrate.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Core_Remove_Jquery_Migrate', false ) ) {

	/**
	 * Removes jQuery Migrate from the jQuery bundle on the front end.
	 *
	 * jQuery Migrate exists to keep code written for jQuery 1.x working on
	 * modern jQuery. Removing it is safe on a site whose plugins and theme are
	 * current, and breaks the front end quietly and completely on a site where
	 * something still depends on the old APIs — which is why this tweak is rated
	 * medium risk and never appears in "Fix Safe Issues".
	 *
	 * The admin is left alone. Plenty of plugins ship admin scripts that still
	 * need Migrate, and the admin is not where page weight matters.
	 */
	final class WPDebloat_Handler_Core_Remove_Jquery_Migrate {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_action( 'wp_default_scripts', array( __CLASS__, 'remove_from_bundle' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'wp_default_scripts', array( __CLASS__, 'remove_from_bundle' ), 99 );
		}

		/**
		 * Drop jquery-migrate from the dependencies of the jquery bundle.
		 *
		 * Editing the registered dependency list is the supported way to do this:
		 * dequeuing the handle would leave the bundle declaring a dependency that
		 * never loads, which WordPress resolves by loading it anyway.
		 *
		 * @param \WP_Scripts $scripts The scripts registry.
		 * @return void
		 */
		public static function remove_from_bundle( $scripts ) {
			if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
				return;
			}

			$dependencies = $scripts->registered['jquery']->deps;

			if ( ! is_array( $dependencies ) ) {
				return;
			}

			$scripts->registered['jquery']->deps = array_values(
				array_diff( $dependencies, array( 'jquery-migrate' ) )
			);
		}
	}
}
