<?php
/**
 * Runtime handler: remove the WordPress Events and News dashboard widget.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Admin_Remove_Wp_News_Widget', false ) ) {

	/**
	 * Removes the WordPress Events and News widget from the dashboard.
	 *
	 * This is the one dashboard widget that fetches something over the network
	 * on an admin page load, so it is worth naming separately from the general
	 * "remove these widgets" change: the reason to remove it is not only that it
	 * takes up room.
	 */
	final class Debloater_Handler_Admin_Remove_Wp_News_Widget {

		/**
		 * The widget's meta box id.
		 */
		const WIDGET_ID = 'dashboard_primary';

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_action( 'wp_dashboard_setup', array( __CLASS__, 'remove_widget' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'wp_dashboard_setup', array( __CLASS__, 'remove_widget' ), 99 );
		}

		/**
		 * Take the widget off the dashboard.
		 *
		 * @return void
		 */
		public static function remove_widget() {
			remove_meta_box( self::WIDGET_ID, 'dashboard', 'side' );
			remove_meta_box( self::WIDGET_ID, 'dashboard', 'normal' );
		}
	}
}
