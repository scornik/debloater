<?php
/**
 * Runtime handler: stop loading dashicons for logged-out visitors.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Core_Disable_Dashicons_Guests', false ) ) {

	/**
	 * Dequeues the dashicons stylesheet for visitors who are not logged in.
	 *
	 * Dashicons is WordPress's admin icon font. Core loads it on the front end
	 * only for the admin bar, which logged-out visitors never see — but plenty of
	 * themes and plugins enqueue it for a menu toggle or a search icon, and then
	 * every visitor downloads a 45 KB font for two glyphs.
	 *
	 * Logged-in users are deliberately untouched: they may have the admin bar,
	 * and breaking its icons to save a request on a page only staff see is a poor
	 * trade. The tweak is rated medium risk because the theme might be using the
	 * font for something visible, and a missing icon font is silent — the layout
	 * simply looks wrong.
	 */
	final class WPDebloat_Handler_Core_Disable_Dashicons_Guests {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_for_guests' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_for_guests' ), 99 );
		}

		/**
		 * Dequeue dashicons unless someone is logged in.
		 *
		 * Runs at priority 99 so it sees what the theme and plugins actually
		 * enqueued rather than what they had enqueued at the point this ran.
		 *
		 * @return void
		 */
		public static function dequeue_for_guests() {
			if ( is_user_logged_in() ) {
				return;
			}

			wp_dequeue_style( 'dashicons' );
			wp_deregister_style( 'dashicons' );
		}
	}
}
