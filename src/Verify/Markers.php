<?php
/**
 * The strings that decide whether a page rendered.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify;

/**
 * Fatal and render markers (BUILD-SPEC §11, docs/DECISIONS.md D-0019).
 *
 * A probe cannot run the site's code and ask it how it feels; it has a status
 * code and a blob of HTML. So the question "did this page render" has to be
 * answered from the text, and which text counts is a decision worth writing
 * down rather than scattering through six probe classes.
 *
 * Two rules shaped the lists below:
 *
 * - **A fatal marker must never appear on a healthy page.** "Fatal error" and
 *   "There has been a critical error on this website" are WordPress's own
 *   wording for a page that died. They are matched case-insensitively but as
 *   whole phrases, because a blog post about a fatal error is a page that
 *   rendered perfectly.
 *
 *   §11 lists `WP_Error` among them. Matched as a bare class name it fires on
 *   any page that so much as mentions the class — a tutorial, a changelog, this
 *   plugin's own documentation — and the consequence of a false positive here is
 *   rolling back a change that was working. What actually indicates a broken
 *   page is a WP_Error that has been *printed*, so the marker is its dumped
 *   form: `WP_Error Object` from print_r and `object(WP_Error)` from var_dump.
 *   See docs/DECISIONS.md D-0019.
 * - **A render marker must appear on every page a theme could reasonably
 *   produce.** `</html>` is the only thing genuinely universal — a truncated
 *   response, which is what a mid-render fatal looks like when display_errors
 *   is off, cannot have one. A missing `<title>` is a warning, not a failure,
 *   because a theme is allowed to be strange.
 */
final class Markers {

	/**
	 * Text that means the page died (§11).
	 *
	 * @var array<int,string>
	 */
	public const FATAL = array(
		'Fatal error',
		'Parse error',
		'There has been a critical error',
		'Error establishing a database connection',
		'WP_Error Object',
		'object(WP_Error)',
	);

	/**
	 * Text that proves a full HTML document arrived.
	 *
	 * @var array<int,string>
	 */
	public const DOCUMENT_END = array(
		'</html>',
	);

	/**
	 * Text that proves a document had a head worth speaking of.
	 *
	 * @var array<int,string>
	 */
	public const DOCUMENT_TITLE = array(
		'<title',
	);

	/**
	 * Text that proves the WordPress admin rendered, rather than a login form
	 * or an error page wearing the admin's clothes.
	 *
	 * @var array<int,string>
	 */
	public const ADMIN = array(
		'id="wpbody"',
		'id="adminmenu"',
	);

	/**
	 * Text that means the request was answered with a login form.
	 *
	 * @var array<int,string>
	 */
	public const LOGIN_FORM = array(
		'id="loginform"',
		'name="log"',
	);

	/**
	 * Not instantiable: this is a list, not an object.
	 */
	private function __construct() {
	}

	/**
	 * The first fatal marker present in a body, or '' when there is none.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public static function fatalIn( string $body ): string {
		foreach ( self::FATAL as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				return $marker;
			}
		}

		return '';
	}

	/**
	 * Whether every one of the given markers is present.
	 *
	 * @param string            $body    Response body.
	 * @param array<int,string> $markers Markers to look for.
	 * @return bool
	 */
	public static function allPresent( string $body, array $markers ): bool {
		foreach ( $markers as $marker ) {
			if ( false === stripos( $body, $marker ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether any one of the given markers is present.
	 *
	 * @param string            $body    Response body.
	 * @param array<int,string> $markers Markers to look for.
	 * @return bool
	 */
	public static function anyPresent( string $body, array $markers ): bool {
		foreach ( $markers as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The markers from a list that are missing from a body.
	 *
	 * @param string            $body    Response body.
	 * @param array<int,string> $markers Markers to look for.
	 * @return array<int,string>
	 */
	public static function missing( string $body, array $markers ): array {
		$absent = array();

		foreach ( $markers as $marker ) {
			if ( false === stripos( $body, $marker ) ) {
				$absent[] = $marker;
			}
		}

		return $absent;
	}
}
