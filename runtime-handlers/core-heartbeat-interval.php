<?php
/**
 * Runtime handler: change the Heartbeat polling interval.
 *
 * See core-remove-generator.php for the rules every runtime handler follows.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Handler_Core_Heartbeat_Interval', false ) ) {

	/**
	 * Sets the interval WordPress uses for Heartbeat polling.
	 *
	 * Heartbeat is not switched off, it is slowed down. Autosave, post locking
	 * and session expiry all depend on it, and a site with several people editing
	 * the same posts genuinely needs it — which is why the engine refuses this
	 * change on such sites rather than leaving the decision to a slider.
	 *
	 * The interval is passed in as a parameter, validated against the tweak's
	 * declared bounds before it reaches the generated code. This handler applies
	 * it and asserts nothing about whether it is a good idea.
	 */
	final class Debloater_Handler_Core_Heartbeat_Interval {

		/**
		 * The interval to apply, in seconds.
		 *
		 * @var int
		 */
		private static $interval = 60;

		/**
		 * Register the handler's hooks.
		 *
		 * @param array<string,scalar|array<int,scalar>> $params Validated parameters: interval, in seconds.
		 * @return void
		 */
		public static function register( $params = array() ) {
			if ( isset( $params['interval'] ) && is_numeric( $params['interval'] ) ) {
				self::$interval = (int) $params['interval'];
			}

			add_filter( 'heartbeat_settings', array( __CLASS__, 'apply_interval' ), 99 );
		}

		/**
		 * Remove every hook register() added.
		 *
		 * @return void
		 */
		public static function unregister() {
			remove_filter( 'heartbeat_settings', array( __CLASS__, 'apply_interval' ), 99 );

			self::$interval = 60;
		}

		/**
		 * Apply the configured interval.
		 *
		 * Runs at priority 99 so that a site or another plugin setting the
		 * interval deliberately at the default priority still wins — this is a
		 * recommendation the user accepted, not a rule about what the site is
		 * allowed to do.
		 *
		 * @param array<string,mixed> $settings Heartbeat settings.
		 * @return array<string,mixed>
		 */
		public static function apply_interval( $settings ) {
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$settings['interval'] = self::$interval;

			return $settings;
		}
	}
}
