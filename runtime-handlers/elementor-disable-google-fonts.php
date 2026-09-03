<?php
/**
 * Runtime handler: stop Elementor fetching Google Fonts.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Elementor_Disable_Google_Fonts', false ) ) {

	/**
	 * Turns off Elementor's Google Fonts loading through its own filter.
	 *
	 * `elementor/frontend/print_google_fonts` is a filter Elementor documents and
	 * supports, which is the only reason this tweak exists at all. Debloater
	 * does not reach into another plugin's internals: where a supported switch
	 * exists it uses it, and where one does not, the answer is a finding rather
	 * than a change.
	 *
	 * What this actually does: the font files stop being requested from Google,
	 * and text falls back to whatever the browser has. On a site whose design
	 * depends on a particular typeface that is visible, which is why the tweak is
	 * medium risk and says so.
	 */
	final class Debloater_Handler_Elementor_Disable_Google_Fonts {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			add_filter( 'elementor/frontend/print_google_fonts', array( __CLASS__, 'refuse' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'elementor/frontend/print_google_fonts', array( __CLASS__, 'refuse' ), 99 );
		}

		/**
		 * Answer Elementor's question.
		 *
		 * @param mixed $printing Whether Elementor intends to print the fonts.
		 * @return bool
		 */
		public static function refuse( $printing ) {
			unset( $printing );

			return false;
		}
	}
}
