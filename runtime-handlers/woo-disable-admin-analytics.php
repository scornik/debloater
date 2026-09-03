<?php
/**
 * Runtime handler: switch off WooCommerce Analytics.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Woo_Disable_Admin_Analytics', false ) ) {

	/**
	 * Turns off the Analytics section of WooCommerce Admin.
	 *
	 * Analytics keeps its own set of lookup tables and fills them in from a
	 * scheduled job whenever an order changes. On a busy store that is a
	 * continuous background cost, and on a store whose owner reads their numbers
	 * somewhere else it buys nothing.
	 *
	 * **This hides the reports; it deletes nothing.** The tables stay, the orders
	 * stay, and unselecting the change brings the section back with its history
	 * intact — though WooCommerce may need to catch up on imports it missed while
	 * it was off.
	 *
	 * Done through `woocommerce_admin_features`, which is WooCommerce's own
	 * documented filter for exactly this.
	 */
	final class WPDebloat_Handler_Woo_Disable_Admin_Analytics {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_filter( 'woocommerce_admin_features', array( __CLASS__, 'remove_analytics' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'woocommerce_admin_features', array( __CLASS__, 'remove_analytics' ), 99 );
		}

		/**
		 * Take analytics out of the feature list.
		 *
		 * @param mixed $features Feature names WooCommerce Admin intends to load.
		 * @return array<int,string>
		 */
		public static function remove_analytics( $features ) {
			if ( ! is_array( $features ) ) {
				return array();
			}

			return array_values(
				array_filter(
					$features,
					static function ( $feature ) {
						return 'analytics' !== $feature;
					}
				)
			);
		}
	}
}
