<?php
/**
 * Runtime handler: hide the core update notice from people who cannot act on it.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Admin_Hide_Update_Nags_Non_Admins', false ) ) {

	/**
	 * Hides the "WordPress x.y is available" notice from users without the
	 * capability to update.
	 *
	 * The notice is shown to everyone who can see the admin, including authors,
	 * editors and shop managers, none of whom can do anything about it. What
	 * they can do is worry, or ask somebody.
	 *
	 * Anyone who *can* update still sees it, which is the whole point: this
	 * hides an instruction from the people it is not addressed to, and never
	 * from the person responsible for acting on it. It is not an update-nag
	 * blocker and must never become one.
	 */
	final class WPDebloat_Handler_Admin_Hide_Update_Nags_Non_Admins {

		/**
		 * Hooks the notice is printed from.
		 *
		 * @var array<int,string>
		 */
		private static $hooks = array( 'admin_notices', 'network_admin_notices' );

		/**
		 * Hooks this handler actually took the notice off.
		 *
		 * Tracked rather than assumed. Not every install has the notice on both
		 * hooks, and unregister() putting one back where it never was would be
		 * this handler adding a notice — which is the opposite of its job.
		 *
		 * @var array<int,string>
		 */
		private static $removed = array();

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_action( 'admin_init', array( __CLASS__, 'hide_for_others' ) );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'admin_init', array( __CLASS__, 'hide_for_others' ) );

			foreach ( self::$removed as $hook ) {
				if ( ! has_action( $hook, 'update_nag' ) ) {
					// @phpstan-ignore-next-line -- update_nag() is core's own callback with core's own signature; putting it back exactly as WordPress registered it is the point.
					add_action( $hook, 'update_nag', 3 );
				}
			}

			self::$removed = array();
		}

		/**
		 * Take the notice away from users who cannot update.
		 *
		 * @return void
		 */
		public static function hide_for_others() {
			if ( current_user_can( 'update_core' ) ) {
				return;
			}

			foreach ( self::$hooks as $hook ) {
				if ( false === has_action( $hook, 'update_nag' ) ) {
					continue;
				}

				remove_action( $hook, 'update_nag', 3 );

				if ( ! in_array( $hook, self::$removed, true ) ) {
					self::$removed[] = $hook;
				}
			}
		}
	}
}
