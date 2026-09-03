<?php
/**
 * Runtime handler: remove the WordPress generator tag.
 *
 * Runtime handlers are loaded by the generated wp-content/debloater/runtime.php
 * through a direct require of an absolute path. They are deliberately outside
 * the autoloader and outside the Debloater namespace: nothing about the plugin
 * is loaded on a front-end request, which is what makes the zero-overhead
 * guarantee in BUILD-SPEC §10 true rather than aspirational.
 *
 * A handler registers hooks and nothing else. It reads no options, touches no
 * database, produces no output, and knows nothing about the registry.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Core_Remove_Generator', false ) ) {

	/**
	 * Removes the <meta name="generator"> tag and empties the generator string.
	 */
	final class Debloater_Handler_Core_Remove_Generator {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', array( __CLASS__, 'empty_generator' ) );
		}

		/**
		 * Remove every hook register() added and restore what it removed.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'the_generator', array( __CLASS__, 'empty_generator' ) );
			add_action( 'wp_head', 'wp_generator' );
		}

		/**
		 * Return an empty generator string.
		 *
		 * The tag is emitted in several places besides wp_head, including feeds,
		 * so removing the wp_head action alone would leave the version visible.
		 *
		 * @param string $generator The generator markup.
		 * @return string
		 */
		public static function empty_generator( $generator ) {
			unset( $generator );

			return '';
		}
	}
}
