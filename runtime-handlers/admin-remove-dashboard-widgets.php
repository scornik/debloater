<?php
/**
 * Runtime handler: remove named dashboard widgets.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Admin_Remove_Dashboard_Widgets', false ) ) {

	/**
	 * Removes the dashboard widgets a site owner named.
	 *
	 * Widgets are removed by id, and the ids come from the scan: the user picked
	 * from a list of what is actually on their dashboard. Nothing here has an
	 * opinion about which widgets are worth having.
	 *
	 * Removal is a matter of not registering a meta box. The widget's data is
	 * untouched, the plugin that provides it carries on, and unselecting the
	 * change brings it straight back on the next request.
	 */
	final class Debloater_Handler_Admin_Remove_Dashboard_Widgets {

		/**
		 * Widget ids to remove.
		 *
		 * @var array<int,string>
		 */
		private static $widgets = array();

		/**
		 * The dashboard contexts a widget can be registered in.
		 *
		 * @var array<int,string>
		 */
		private static $contexts = array( 'normal', 'side', 'column3', 'column4', 'advanced' );

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters: widgets, a list of ids.
		 * @return void
		 */
		public static function register( $params = array() ) {
			self::$widgets = array();

			if ( isset( $params['widgets'] ) && is_array( $params['widgets'] ) ) {
				foreach ( $params['widgets'] as $widget ) {
					if ( is_string( $widget ) && '' !== $widget ) {
						self::$widgets[] = $widget;
					}
				}
			}

			if ( array() === self::$widgets ) {
				return;
			}

			// Late, so that widgets registered at the default priority exist by
			// the time this runs.
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'remove_widgets' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'wp_dashboard_setup', array( __CLASS__, 'remove_widgets' ), 99 );

			self::$widgets = array();
		}

		/**
		 * Take the named widgets off the dashboard.
		 *
		 * @return void
		 */
		public static function remove_widgets() {
			foreach ( self::$widgets as $widget ) {
				foreach ( self::$contexts as $context ) {
					remove_meta_box( $widget, 'dashboard', $context );
				}
			}
		}
	}
}
