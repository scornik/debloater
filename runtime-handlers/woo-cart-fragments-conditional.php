<?php
/**
 * Runtime handler: load WooCommerce cart fragments only where a cart is shown.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Woo_Cart_Fragments_Conditional', false ) ) {

	/**
	 * Dequeues wc-cart-fragments on pages that are not part of the shop.
	 *
	 * Cart fragments make an admin-ajax request on every page load to find out
	 * what is in the cart. On a store page that is necessary; on the blog, the
	 * contact page and the privacy policy it is a request per visitor per page
	 * for a cart nothing on the page displays.
	 *
	 * **Conditional, not off.** Every WooCommerce page keeps it: the cart, the
	 * checkout, the account pages, any product or shop archive, and anything
	 * built from a WooCommerce block or shortcode. The decision is made by
	 * WooCommerce's own conditional tags at the moment the page is built, which
	 * is the only place the question can be answered correctly.
	 *
	 * The engine refuses this change entirely on a site with a mini-cart away
	 * from the shop, because there the fragments really are needed everywhere.
	 * That refusal lives in the analyzer, where it can see the whole site; this
	 * handler is told what to do and does it.
	 */
	final class Debloater_Handler_Woo_Cart_Fragments_Conditional {

		/**
		 * The handle this manages.
		 */
		const HANDLE = 'wc-cart-fragments';

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			// Late, so that WooCommerce has enqueued it and any theme that wants
			// it has had its say.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_dequeue' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_dequeue' ), 99 );
		}

		/**
		 * Drop the script where nothing on the page can show a cart.
		 *
		 * @return void
		 */
		public static function maybe_dequeue() {
			if ( self::needs_cart() ) {
				return;
			}

			wp_dequeue_script( self::HANDLE );
		}

		/**
		 * Whether this page could show or change a cart.
		 *
		 * Deliberately generous. Every test that says "keep it" is cheap; the one
		 * that wrongly says "drop it" breaks a checkout.
		 *
		 * @return bool
		 */
		private static function needs_cart() {
			foreach ( array( 'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page' ) as $conditional ) {
				if ( function_exists( $conditional ) && call_user_func( $conditional ) ) {
					return true;
				}
			}

			if ( is_singular() ) {
				$post = get_post();

				if ( $post instanceof WP_Post ) {
					$content = (string) $post->post_content;

					foreach ( array( 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'products', 'product_page' ) as $shortcode ) {
						if ( has_shortcode( $content, $shortcode ) ) {
							return true;
						}
					}

					if ( false !== strpos( $content, 'wp:woocommerce/' ) ) {
						return true;
					}
				}
			}

			// A theme showing a cart in its header, in a widget, or anywhere else
			// this cannot see, says so through this filter.
			return (bool) apply_filters( 'debloater_woo_page_needs_cart', false );
		}
	}
}
