<?php
/**
 * Runtime handler: cap how many revisions WordPress keeps per post.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package WPDebloat
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPDebloat_Handler_Core_Limit_Revisions', false ) ) {

	/**
	 * Caps the number of revisions kept for each post.
	 *
	 * This changes what happens from now on. It deletes nothing: WordPress prunes
	 * the oldest revisions of a post the next time that post is saved, so a post
	 * nobody edits again keeps every revision it already had.
	 *
	 * That distinction is the whole reason this is a config tweak with risk
	 * "low" rather than a destructive data operation. Deleting the revisions that
	 * already exist is a separate, destructive tweak that takes a Level B
	 * snapshot first (BUILD-SPEC §7.4, Phase 10).
	 */
	final class WPDebloat_Handler_Core_Limit_Revisions {

		/**
		 * How many revisions to keep per post.
		 *
		 * @var int
		 */
		private static $keep = 5;

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters: keep, the number of revisions.
		 * @return void
		 */
		public static function register( $params = array() ) {
			if ( isset( $params['keep'] ) && is_numeric( $params['keep'] ) ) {
				self::$keep = (int) $params['keep'];
			}

			add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'apply_limit' ), 99, 2 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'wp_revisions_to_keep', array( __CLASS__, 'apply_limit' ), 99 );

			self::$keep = 5;
		}

		/**
		 * Apply the configured limit.
		 *
		 * A site that has already set a *lower* limit keeps it: this tweak exists
		 * to cap unlimited revisions, not to raise a deliberate restriction.
		 *
		 * @param int      $num  Number of revisions WordPress would keep.
		 * @param \WP_Post $post The post being saved.
		 * @return int
		 */
		public static function apply_limit( $num, $post ) {
			unset( $post );

			if ( is_numeric( $num ) && $num >= 0 && (int) $num < self::$keep ) {
				return (int) $num;
			}

			return self::$keep;
		}
	}
}
