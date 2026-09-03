<?php
/**
 * Runtime handler: stop the site pinging itself.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Core_Disable_Self_Pingbacks', false ) ) {

	/**
	 * Drops internal links from the list of URLs a post pings on publish.
	 *
	 * Links to other sites are left alone: the tweak removes self-pings, not
	 * pingbacks. Filtering happens at ping time rather than being compiled in,
	 * because the site's own address can change after the runtime is generated
	 * and a stale address would silently let self-pings resume
	 * (docs/DECISIONS.md D-0006).
	 */
	final class Debloater_Handler_Core_Disable_Self_Pingbacks {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_action( 'pre_ping', array( __CLASS__, 'drop_internal_links' ), 10, 1 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_action( 'pre_ping', array( __CLASS__, 'drop_internal_links' ), 10 );
		}

		/**
		 * Remove links pointing at this site from the ping list.
		 *
		 * The parameter is passed by reference by do_action_ref_array(), which is
		 * how WordPress expects pre_ping listeners to edit the list.
		 *
		 * @param array<int,string> $links Links about to be pinged, by reference.
		 * @return void
		 */
		public static function drop_internal_links( &$links ) {
			if ( ! is_array( $links ) ) {
				return;
			}

			$home = home_url();

			foreach ( $links as $index => $link ) {
				if ( is_string( $link ) && 0 === strpos( $link, $home ) ) {
					unset( $links[ $index ] );
				}
			}
		}
	}
}
