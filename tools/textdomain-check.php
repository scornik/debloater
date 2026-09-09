<?php
/**
 * Fail if this plugin's translations are loaded before `init`.
 *
 * Run with:
 *
 *     npm run test:textdomain
 *
 * WordPress 6.7 warns when a textdomain is loaded "just in time" — that is,
 * when a translation function is reached before `init`, which is the earliest
 * point a translation can be correct. The warning is a `_doing_it_wrong()`
 * notice, so it is invisible to a test suite that has already finished booting
 * and invisible to anything that is not watching for it. wordpress.org's
 * reviewer sees it on the first page they load.
 *
 * This has to run as a `--require` file, before WordPress loads:
 * `WP_CLI::add_wp_hook()` can register a WordPress hook while WordPress does not
 * yet exist, and the window being watched — plugins loading, up to `init` — is
 * over before `wp eval` gets a turn.
 *
 * @package Debloater
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$GLOBALS['debloater_early_textdomains'] = array();

WP_CLI::add_wp_hook(
	'doing_it_wrong_run',
	static function ( $function_name, $message = '' ) {
		if ( '_load_textdomain_just_in_time' !== $function_name ) {
			return;
		}

		$GLOBALS['debloater_early_textdomains'][] = array(
			'message' => (string) $message,
			'trace'   => ( new Exception() )->getTraceAsString(),
		);
	},
	10,
	2
);

if ( ! function_exists( 'debloater_textdomain_verdict' ) ) {

	/**
	 * Report what was seen, and exit non-zero if any of it was ours.
	 *
	 * Other people's plugins loading their own translations early is their
	 * business and not something this plugin can fix, so only our own domain
	 * fails the check. Theirs are printed, because a reviewer reading the same
	 * screen will not care whose they are and it is better to know.
	 *
	 * @return void
	 */
	function debloater_textdomain_verdict(): void {
		$seen = isset( $GLOBALS['debloater_early_textdomains'] )
			? (array) $GLOBALS['debloater_early_textdomains']
			: array();

		$ours = array();

		foreach ( $seen as $notice ) {
			if ( false !== strpos( (string) $notice['message'], \Debloater\Brand::TEXT_DOMAIN ) ) {
				$ours[] = $notice;
			}
		}

		if ( array() === $ours ) {
			WP_CLI::success(
				sprintf(
					'No early translation of %s. (%d unrelated notice(s) from other plugins.)',
					\Debloater\Brand::TEXT_DOMAIN,
					count( $seen )
				)
			);

			return;
		}

		foreach ( $ours as $notice ) {
			WP_CLI::log( $notice['message'] );
			WP_CLI::log( $notice['trace'] );
		}

		WP_CLI::error(
			sprintf(
				'%s was translated before init, %d time(s). A translation reached before init is a translation that cannot be right.',
				\Debloater\Brand::TEXT_DOMAIN,
				count( $ours )
			)
		);
	}
}
