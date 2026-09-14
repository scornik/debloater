<?php
/**
 * Runtime handler: stop WooCommerce suggesting extensions to buy.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Woo_Suppress_Marketplace_Suggestions', false ) ) {

	/**
	 * Answers WooCommerce's marketplace-suggestions filter.
	 *
	 * `woocommerce_allow_marketplace_suggestions` turns off the panels offering
	 * paid extensions on the products, orders and settings screens. It does not
	 * reach everything: in WooCommerce 11.1.0 the Shipping settings tab's
	 * extension link and the recommendations the newer admin screens read
	 * through the REST options endpoint consult the `Show Suggestions` setting
	 * directly, and this handler does not change that setting.
	 *
	 * Until 0.5.0 it also returned true from
	 * `woocommerce_helper_suppress_admin_notices`. That filter does not hide
	 * marketing: it silences `WC_Helper::admin_notices()`, the note on
	 * Dashboard → Updates saying how many WooCommerce.com extensions have updates
	 * waiting. Hiding an update notice is what `D-0077` removed a tweak for, so
	 * this handler no longer touches it (`D-0079`).
	 *
	 * Store notices — a pending database update, a gateway that needs
	 * configuring — do not travel through the suggestions filter.
	 */
	final class Debloater_Handler_Woo_Suppress_Marketplace_Suggestions {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_filter( 'woocommerce_allow_marketplace_suggestions', array( __CLASS__, 'refuse' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'woocommerce_allow_marketplace_suggestions', array( __CLASS__, 'refuse' ), 99 );
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
	}
}
