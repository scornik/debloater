<?php
/**
 * Kill switch for the selection.
 *
 * Loaded by Debloater\Apply\Runtime before any handler, so it follows the same
 * rules as a handler: no namespace, no autoloader, no options, no database, no
 * output.
 *
 * @package Debloater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Debloater_Runtime_Guard', false ) ) {

	/**
	 * Decides whether anything should be registered at all.
	 *
	 * Two ways out exist, and they are deliberately different in strength.
	 *
	 * The DEBLOATER_DISABLE constant is absolute: someone who can edit wp-config
	 * can always switch the selection off, including when the site is too broken
	 * to reach the admin. It needs no authentication because being able to set it
	 * already implies full access.
	 *
	 * The ?debloater=off query bypass is for logged-in administrators and must be
	 * authenticated, so it needs current_user_can() and wp_verify_nonce() to
	 * exist when it is asked.
	 *
	 * They now do. This used to run from mu-plugins, long before WordPress loads
	 * pluggable.php, so the guard recorded the request, returned false, and
	 * Debloater\Apply\RuntimeLoader finished the check at plugins_loaded and
	 * unregistered the handlers again if the request turned out to be authorised
	 * (D-0007). Registration happens at plugins_loaded -999 now, and
	 * wp-settings.php loads pluggable.php before firing that hook — so the
	 * question can simply be answered, and the deferral, the second hook and the
	 * unregister pass are all gone (D-0070).
	 */
	final class Debloater_Runtime_Guard {

		/**
		 * Query variable that requests a bypass.
		 */
		const QUERY_VAR = 'debloater';

		/**
		 * Value of the query variable that requests a bypass.
		 */
		const QUERY_VALUE = 'off';

		/**
		 * Nonce action the bypass request must be signed with.
		 */
		const NONCE_ACTION = 'debloater_bypass';

		/**
		 * Query variable carrying the nonce.
		 */
		const NONCE_VAR = 'debloater_nonce';

		/**
		 * Capability required to bypass the runtime.
		 */
		const CAPABILITY = 'debloater_manage';

		/**
		 * Whether the runtime is switched off outright.
		 *
		 * @return bool
		 */
		public static function disabled() {
			return defined( 'DEBLOATER_DISABLE' ) && DEBLOATER_DISABLE;
		}

		/**
		 * Whether this request asks for the runtime to be skipped.
		 *
		 * Reading the query string is not a decision; nothing is skipped on the
		 * strength of this alone.
		 *
		 * @return bool
		 */
		public static function bypass_requested() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is checked in bypass_allowed(); this only detects the request.
			if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
				return false;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			$value = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

			return self::QUERY_VALUE === $value;
		}

		/**
		 * Whether the bypass may be honoured right now.
		 *
		 * @return bool
		 */
		public static function bypass_allowed() {
			if ( ! self::bypass_requested() ) {
				return false;
			}

			return self::authorised();
		}

		/**
		 * Whether the current user may bypass the runtime.
		 *
		 * Both a capability and a valid nonce are required: the capability so that
		 * only an administrator can do it, the nonce so that nobody can be led into
		 * doing it by following a link.
		 *
		 * @return bool
		 */
		public static function authorised() {
			if ( ! self::auth_available() ) {
				return false;
			}

			if ( ! current_user_can( self::CAPABILITY ) ) {
				return false;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the nonce check.
			$nonce = isset( $_GET[ self::NONCE_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::NONCE_VAR ] ) ) : '';

			if ( '' === $nonce ) {
				return false;
			}

			return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
		}

		/**
		 * Whether WordPress can yet say who is making this request.
		 *
		 * @return bool
		 */
		private static function auth_available() {
			return function_exists( 'current_user_can' ) && function_exists( 'wp_verify_nonce' );
		}
	}
}
