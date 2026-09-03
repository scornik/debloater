<?php
/**
 * Runtime handler: remove the dashboard welcome panel.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Admin_Remove_Welcome_Panel', false ) ) {

	/**
	 * Removes the "Welcome to WordPress!" panel from the dashboard.
	 *
	 * WordPress already lets each user dismiss this, and the dismissal is stored
	 * per user. On a site with several people that means each of them meets it
	 * once. This removes it for everybody without touching anyone's stored
	 * preference, so unselecting the change puts each person back exactly where
	 * they were.
	 */
	final class Debloater_Handler_Admin_Remove_Welcome_Panel {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}

		/**
		 * Put back what register() removed.
		 *
		 * @return void
		 */
		public static function unregister() {
			if ( ! has_action( 'welcome_panel', 'wp_welcome_panel' ) ) {
				add_action( 'welcome_panel', 'wp_welcome_panel' );
			}
		}
	}
}
