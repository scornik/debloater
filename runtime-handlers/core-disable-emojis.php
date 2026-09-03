<?php
/**
 * Runtime handler: stop loading the emoji detection script and styles.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Core_Disable_Emojis', false ) ) {

	/**
	 * Removes the emoji detection script, its styles, and its DNS prefetch.
	 *
	 * WordPress registers the emoji pieces across several hooks, and the set has
	 * changed between releases: print_emoji_styles was replaced by
	 * wp_enqueue_emoji_styles in 6.4. Both are removed here. remove_action on a
	 * hook that was never registered is a no-op, so covering the older names
	 * costs nothing and keeps the handler correct across the supported range.
	 *
	 * Emoji characters still display; only the polyfill that rewrites them into
	 * images on browsers that do not need it stops loading.
	 */
	final class Debloater_Handler_Core_Disable_Emojis {

		/**
		 * Priority WordPress registers print_emoji_detection_script at in wp_head.
		 */
		const DETECTION_PRIORITY = 7;

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters. This handler takes none.
		 * @return void
		 */
		public static function register( $params = array() ) {
			unset( $params );

			remove_action( 'wp_head', 'print_emoji_detection_script', self::DETECTION_PRIORITY );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );

			// WordPress 6.4 and later.
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'admin_print_styles', 'wp_enqueue_emoji_styles' );

			// WordPress 6.3 and earlier.
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );

			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

			add_filter( 'tiny_mce_plugins', array( __CLASS__, 'remove_tinymce_plugin' ) );
			add_filter( 'wp_resource_hints', array( __CLASS__, 'remove_resource_hint' ), 10, 2 );
		}

		/**
		 * Remove every hook register() added and restore what it removed.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'tiny_mce_plugins', array( __CLASS__, 'remove_tinymce_plugin' ) );
			remove_filter( 'wp_resource_hints', array( __CLASS__, 'remove_resource_hint' ), 10 );

			add_action( 'wp_head', 'print_emoji_detection_script', self::DETECTION_PRIORITY );
			add_action( 'admin_print_scripts', 'print_emoji_detection_script' );

			if ( function_exists( 'wp_enqueue_emoji_styles' ) ) {
				add_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
				add_action( 'admin_print_styles', 'wp_enqueue_emoji_styles' );
			} else {
				add_action( 'wp_print_styles', 'print_emoji_styles' );
				add_action( 'admin_print_styles', 'print_emoji_styles' );
			}

			add_filter( 'the_content_feed', 'wp_staticize_emoji' );
			add_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			add_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		}

		/**
		 * Drop the emoji plugin from the TinyMCE plugin list.
		 *
		 * @param array<int,string> $plugins Registered TinyMCE plugins.
		 * @return array<int,string>
		 */
		public static function remove_tinymce_plugin( $plugins ) {
			if ( ! is_array( $plugins ) ) {
				return array();
			}

			return array_values( array_diff( $plugins, array( 'wpemoji' ) ) );
		}

		/**
		 * Drop the emoji CDN from the DNS prefetch hints.
		 *
		 * @param array<int,mixed> $urls          URLs for the given relation type.
		 * @param string $relation_type The relation type being requested.
		 * @return array<int,mixed>
		 */
		public static function remove_resource_hint( $urls, $relation_type ) {
			if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
				return $urls;
			}

			$kept = array();

			foreach ( $urls as $url ) {
				$href = is_array( $url ) && isset( $url['href'] ) ? $url['href'] : $url;

				if ( is_string( $href ) && false !== strpos( $href, 'https://s.w.org/images/core/emoji/' ) ) {
					continue;
				}

				$kept[] = $url;
			}

			return $kept;
		}
	}
}
