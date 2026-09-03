<?php
/**
 * Runtime handler: stop WooCommerce suggesting extensions to buy.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Woo_Suppress_Marketplace_Suggestions', false ) ) {

	/**
	 * Answers WooCommerce's own marketplace-suggestions filters.
	 *
	 * These are the panels offering paid extensions on the products, orders and
	 * settings screens. They are marketing, WooCommerce provides documented
	 * switches for turning them off, and nothing operational travels through
	 * them — which is what makes this the one WooCommerce change here that is
	 * safe rather than medium risk.
	 *
	 * Notices about the store itself are untouched: a pending database update or
	 * a gateway that needs configuring still reaches the person running the shop.
	 */
	final class WPDebloat_Handler_Woo_Suppress_Marketplace_Suggestions {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_filter( 'woocommerce_allow_marketplace_suggestions', array( __CLASS__, 'refuse' ), 99 );
			add_filter( 'woocommerce_helper_suppress_admin_notices', array( __CLASS__, 'accept' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'woocommerce_allow_marketplace_suggestions', array( __CLASS__, 'refuse' ), 99 );
			remove_filter( 'woocommerce_helper_suppress_admin_notices', array( __CLASS__, 'accept' ), 99 );
		}

		/**
		 * No, do not show suggestions.
		 *
		 * @param mixed $allow Whether WooCommerce intends to show them.
		 * @return bool
		 */
		public static function refuse( $allow ) {
			unset( $allow );

			return false;
		}

		/**
		 * Yes, do suppress the helper's marketing notices.
		 *
		 * @param mixed $suppress Whether WooCommerce intends to suppress them.
		 * @return bool
		 */
		public static function accept( $suppress ) {
			unset( $suppress );

			return true;
		}
	}
}
