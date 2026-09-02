<?php
/**
 * Runtime handler: remove the shortlink tag and header.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Core_Remove_Shortlink', false ) ) {

	/**
	 * Removes the rel=shortlink markup and the matching Link: HTTP header.
	 *
	 * Both are removed together. Removing only the head tag would leave the
	 * header advertising the same ?p=123 URL, which is the thing the tweak is
	 * meant to stop exposing.
	 */
	final class WPDebloat_Handler_Core_Remove_Shortlink {

		/**
		 * Priority WordPress registers wp_shortlink_header at.
		 */
		const HEADER_PRIORITY = 11;

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', self::HEADER_PRIORITY );
		}

		/**
		 * Restore what register() removed.
		 *
		 * @return void
		 */
		public static function unregister() {
			add_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			add_action( 'template_redirect', 'wp_shortlink_header', self::HEADER_PRIORITY, 0 );
		}
	}
}
