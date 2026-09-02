<?php
/**
 * Runtime handler: remove the Really Simple Discovery link.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Core_Remove_Rsd', false ) ) {

	/**
	 * Removes the RSD link from wp_head.
	 *
	 * RSD advertises the XML-RPC endpoint to blog clients. Removing the link does
	 * not disable XML-RPC; it only stops announcing it.
	 */
	final class WPDebloat_Handler_Core_Remove_Rsd {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			remove_action( 'wp_head', 'rsd_link' );
		}

		/**
		 * Restore what register() removed.
		 *
		 * @return void
		 */
		public static function unregister() {
			add_action( 'wp_head', 'rsd_link' );
		}
	}
}
