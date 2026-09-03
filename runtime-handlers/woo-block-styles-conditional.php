<?php
/**
 * Runtime handler: load WooCommerce block styles only where blocks are used.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Woo_Block_Styles_Conditional', false ) ) {

	/**
	 * Dequeues WooCommerce's block stylesheets away from the shop.
	 *
	 * The same shape as the cart-fragments change and the same caution: every
	 * WooCommerce page keeps them, and so does any page whose content contains a
	 * WooCommerce block. A stylesheet dropped from a page that needed it is a
	 * visibly broken layout, so every test that says "keep" is preferred to any
	 * cleverness that says "drop".
	 */
	final class Debloater_Handler_Woo_Block_Styles_Conditional {

		/**
		 * The handles this manages.
		 *
		 * @var array<int,string>
		 */
		private static $handles = array( 'wc-blocks-style', 'wc-all-blocks-style' );

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

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
		 * Drop the stylesheets where no WooCommerce block can appear.
		 *
		 * @return void
		 */
		public static function maybe_dequeue() {
			if ( self::needs_block_styles() ) {
				return;
			}

			foreach ( self::$handles as $handle ) {
				wp_dequeue_style( $handle );
			}
		}

		/**
		 * Whether this page could contain a WooCommerce block.
		 *
		 * @return bool
		 */
		private static function needs_block_styles() {
			foreach ( array( 'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page' ) as $conditional ) {
				if ( function_exists( $conditional ) && call_user_func( $conditional ) ) {
					return true;
				}
			}

			if ( is_singular() ) {
				$post = get_post();

				if ( $post instanceof WP_Post && false !== strpos( (string) $post->post_content, 'wp:woocommerce/' ) ) {
					return true;
				}
			}

			// A block in a template part, a widget area or a page builder is not
			// visible from here, so a theme that has one says so.
			return (bool) apply_filters( 'debloater_woo_page_needs_block_styles', false );
		}
	}
}
