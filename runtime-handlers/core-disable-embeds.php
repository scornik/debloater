<?php
/**
 * Runtime handler: stop loading the oEmbed discovery links and script.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Core_Disable_Embeds', false ) ) {

	/**
	 * Removes WordPress's own embed machinery.
	 *
	 * Two different things share the name "embeds", and only one of them is
	 * removed here.
	 *
	 * Embedding *other people's* content in your posts — a YouTube video, a
	 * tweet — keeps working: that is oEmbed consumption, and nothing below
	 * touches it.
	 *
	 * What stops is oEmbed *provision*: the discovery links that let other sites
	 * embed your posts, the wp-embed.js script that makes those embeds resize
	 * themselves, and the /embed/ endpoint. On a site nobody embeds, that is a
	 * script and two head tags on every page for a feature never used.
	 */
	final class WPDebloat_Handler_Core_Disable_Embeds {

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			remove_action( 'rest_api_init', 'wp_oembed_register_route' );

			add_filter( 'embed_oembed_discover', '__return_false' );
			add_filter( 'rewrite_rules_array', array( __CLASS__, 'remove_embed_rewrite_rules' ) );
			add_filter( 'tiny_mce_plugins', array( __CLASS__, 'remove_tinymce_plugin' ) );
		}

		/**
		 * Remove every hook register() added and restore what it removed.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'embed_oembed_discover', '__return_false' );
			remove_filter( 'rewrite_rules_array', array( __CLASS__, 'remove_embed_rewrite_rules' ) );
			remove_filter( 'tiny_mce_plugins', array( __CLASS__, 'remove_tinymce_plugin' ) );

			add_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			add_action( 'rest_api_init', 'wp_oembed_register_route' );
		}

		/**
		 * Drop the /embed/ rewrite rules.
		 *
		 * @param array<string,string> $rules Rewrite rules.
		 * @return array<string,string>
		 */
		public static function remove_embed_rewrite_rules( $rules ) {
			if ( ! is_array( $rules ) ) {
				return array();
			}

			foreach ( $rules as $rule => $rewrite ) {
				if ( is_string( $rewrite ) && false !== strpos( $rewrite, 'embed=true' ) ) {
					unset( $rules[ $rule ] );
				}
			}

			return $rules;
		}

		/**
		 * Drop the embed plugin from the TinyMCE plugin list.
		 *
		 * @param array<int,string> $plugins Registered TinyMCE plugins.
		 * @return array<int,string>
		 */
		public static function remove_tinymce_plugin( $plugins ) {
			if ( ! is_array( $plugins ) ) {
				return array();
			}

			return array_values( array_diff( $plugins, array( 'wpembed' ) ) );
		}
	}
}
