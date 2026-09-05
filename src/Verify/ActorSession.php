<?php
/**
 * Making a loopback request as the person who asked for the change.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify;

use WP_Session_Tokens;
use Debloater\Contracts\Context;

/**
 * The credentials the `admin` and `runtime_loaded` probes need (BUILD-SPEC §11).
 *
 * Two of the probes ask for pages an anonymous visitor cannot see. §11 requires
 * `/wp-admin/` to be fetched "with cookie of actor", and the plugin's own status
 * endpoint is behind the same capability check as everything else in its REST
 * namespace.
 *
 * There are two situations, and they are handled differently on purpose:
 *
 * - **The change was made from the admin.** The request that is running right
 *   now already carries the user's logged-in cookie. It is forwarded verbatim.
 *   Nothing is minted, nothing new is granted, and the loopback request is
 *   exactly as privileged as the request that asked for it.
 * - **The change was made from WP-CLI or cron.** There is no cookie to forward,
 *   so one is created for the acting user against a real session token. That
 *   session is destroyed as soon as verification is done, so the credential
 *   cannot outlive the run that needed it.
 *
 * When neither is possible — no acting user, or an actor that is not a real
 * account — no credential is produced and the probes that need one report that
 * they could not check, rather than reporting a pass they did not earn.
 *
 * ## Two cookies, not one
 *
 * The first version sent only `LOGGED_IN_COOKIE`, and every admin probe on
 * every real site came back with the login form. The cause is in core and is
 * not obvious: `auth_redirect()`, which guards `/wp-admin/`, calls
 * `wp_validate_auth_cookie( '', '' )`, and `wp_parse_auth_cookie()` resolves an
 * empty scheme to `secure_auth` when `is_ssl()` and to `auth` otherwise —
 * **never** to `logged_in`. So the admin reads `AUTH_COOKIE` or
 * `SECURE_AUTH_COOKIE` and nothing else, and a request carrying only the
 * logged-in cookie is an anonymous request as far as the dashboard is
 * concerned.
 *
 * `LOGGED_IN_COOKIE` is still sent: it is what `wp_get_current_user()` reads,
 * so it is what makes the page render *as the actor* rather than merely letting
 * the request through.
 *
 * The scheme is chosen from the URL being fetched rather than from the current
 * request, because `force_ssl_admin()` can put the admin on https while the
 * request doing the verifying arrived over http. Only the matching cookie is
 * sent: a `secure_auth` credential put on a plaintext request would be a
 * credential minted for TLS and then sent without it.
 */
final class ActorSession {

	/**
	 * How long a minted session lives, in seconds.
	 *
	 * Long enough for six probes on a slow site, short enough that a session
	 * left behind by a crashed run expires before it is useful to anyone.
	 */
	private const LIFETIME = 300;

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The cookie header value, once worked out.
	 *
	 * @var string|null
	 */
	private ?string $cookie = null;

	/**
	 * The REST nonce that matches the cookie.
	 *
	 * @var string
	 */
	private string $nonce = '';

	/**
	 * A session token created for this verification, if one was.
	 *
	 * @var string
	 */
	private string $minted_token = '';

	/**
	 * The session token the credential is bound to, minted or forwarded.
	 *
	 * The admin cookie has to carry the same token as the logged-in one, or
	 * core rejects it: `wp_validate_auth_cookie()` checks the token against the
	 * user's sessions.
	 *
	 * @var string
	 */
	private string $session_token = '';

	/**
	 * When the credential stops being valid.
	 *
	 * @var int
	 */
	private int $expiration = 0;

	/**
	 * The user the credential belongs to.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Constructor.
	 *
	 * @param Context $context Site context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Whether a credential is available.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return '' !== $this->resolve();
	}

	/**
	 * Headers that authenticate a loopback request as the acting user.
	 *
	 * @param string $url The URL about to be fetched. Its scheme decides which
	 *                    of the two admin cookies is sent, and its host decides
	 *                    whether a credential is sent at all.
	 * @return array<string,string>
	 */
	public function headers( string $url = '' ): array {
		$cookie = $this->resolve();

		if ( '' === $cookie ) {
			return array();
		}

		if ( '' !== $url && ! $this->withinCookieDomain( $url ) ) {
			// COOKIE_DOMAIN says where this site's credentials belong. A
			// browser would not send them anywhere else and neither will this,
			// however the URL came to be pointed off-site.
			return array();
		}

		$admin = $this->adminCookie( $url );

		if ( '' !== $admin ) {
			$cookie .= '; ' . $admin;
		}

		$headers = array( 'Cookie' => $cookie );

		if ( '' !== $this->nonce ) {
			// Without this the REST API treats a cookie-authenticated request as
			// anonymous, by design: see rest_cookie_check_errors().
			$headers['X-WP-Nonce'] = $this->nonce;
		}

		return $headers;
	}

	/**
	 * Whether an admin credential can be produced at all.
	 *
	 * There is one only when the session is bound to a token, which is what
	 * core checks the cookie against. Without it the probe is being turned away
	 * for a different reason than a rejected cookie, and saying which is the
	 * difference between a diagnosis and a guess.
	 *
	 * @return bool
	 */
	public function hasAdminCredential(): bool {
		$this->resolve();

		return 0 !== $this->user_id && '' !== $this->session_token;
	}

	/**
	 * Which cookie `auth_redirect()` will read for a given URL.
	 *
	 * @param string $url The URL being fetched.
	 * @return string 'secure_auth' or 'auth'.
	 */
	public function schemeFor( string $url = '' ): string {
		$target = '' === $url ? admin_url() : $url;

		return str_starts_with( strtolower( $target ), 'https://' ) ? 'secure_auth' : 'auth';
	}

	/**
	 * The admin cookie for one request, minted for the scheme that URL needs.
	 *
	 * Not stored: it is derived from the session token, which is, and holding a
	 * second credential for the lifetime of the object buys nothing.
	 *
	 * @param string $url The URL being fetched.
	 * @return string `NAME=value`, or '' when there is nothing to send.
	 */
	private function adminCookie( string $url ): string {
		if ( 0 === $this->user_id || '' === $this->session_token ) {
			return '';
		}

		$scheme = $this->schemeFor( $url );
		$name   = 'secure_auth' === $scheme ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

		if ( ! defined( 'AUTH_COOKIE' ) || ! defined( 'SECURE_AUTH_COOKIE' ) ) {
			return '';
		}

		$forwarded = $this->forwardedAdminCookie( $name, $scheme );

		if ( '' !== $forwarded ) {
			return $name . '=' . $forwarded;
		}

		return $name . '=' . wp_generate_auth_cookie(
			$this->user_id,
			$this->expiration,
			$scheme,
			$this->session_token
		);
	}

	/**
	 * The admin cookie of the request we are running inside, when it has one.
	 *
	 * @param string $name   Cookie name.
	 * @param string $scheme Scheme to validate against.
	 * @return string The cookie value, or '' when there is not a usable one.
	 */
	private function forwardedAdminCookie( string $name, string $scheme ): string {
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Validated below by WordPress itself; sanitising an authentication cookie would corrupt it.
		$value = (string) $_COOKIE[ $name ];

		return wp_validate_auth_cookie( $value, $scheme ) === $this->user_id ? $value : '';
	}

	/**
	 * Whether a URL is somewhere this site's cookies belong.
	 *
	 * @param string $url The URL being fetched.
	 * @return bool
	 */
	private function withinCookieDomain( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return false;
		}

		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '';

		if ( '' === $domain ) {
			// Unset means "the host this site is served from", which is what
			// every probe asks for anyway. Compared against the site's own host
			// rather than waved through.
			$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$domain = ltrim( strtolower( $domain ), '.' );
		$host   = strtolower( $host );

		if ( '' === $domain ) {
			return false;
		}

		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}

	/**
	 * The user the credential belongs to, or 0 when there is none.
	 *
	 * @return int
	 */
	public function userId(): int {
		$this->resolve();

		return $this->user_id;
	}

	/**
	 * Destroy anything created for this verification.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( '' === $this->minted_token || 0 === $this->user_id ) {
			return;
		}

		WP_Session_Tokens::get_instance( $this->user_id )->destroy( $this->minted_token );

		$this->minted_token  = '';
		$this->session_token = '';
		$this->expiration    = 0;
		$this->cookie        = null;
		$this->nonce         = '';
	}

	/**
	 * Work out the credential once.
	 *
	 * @return string The cookie header value, or '' when there is none.
	 */
	private function resolve(): string {
		if ( null !== $this->cookie ) {
			return $this->cookie;
		}

		$this->cookie = '';

		$user_id = $this->context->actorUserId();

		if ( null === $user_id || $user_id < 1 ) {
			return '';
		}

		$this->user_id = $user_id;

		$forwarded = $this->forwardIncomingCookie( $user_id );

		if ( '' !== $forwarded ) {
			$this->cookie = $forwarded;
			$this->nonce  = wp_create_nonce( 'wp_rest' );

			return $this->cookie;
		}

		unset( $forwarded );

		return $this->mintCookie( $user_id );
	}

	/**
	 * Reuse the logged-in cookie of the request we are running inside.
	 *
	 * @param int $user_id The acting user.
	 * @return string Cookie header value, or '' when there is none to forward.
	 */
	private function forwardIncomingCookie( int $user_id ): string {
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Validated below by WordPress itself; sanitising an authentication cookie would corrupt it.
		$cookie = (string) $_COOKIE[ LOGGED_IN_COOKIE ];

		if ( wp_validate_auth_cookie( $cookie, 'logged_in' ) !== $user_id ) {
			// Either it is not valid or it is not this user's. Either way it is
			// not a credential for the actor, and forwarding somebody else's
			// cookie is not something to do by accident.
			return '';
		}

		// The session this cookie belongs to. The admin cookie sent alongside
		// it has to name the same one, because core checks the token against
		// the user's live sessions and a cookie naming a session that does not
		// exist is rejected however well-formed it is.
		$parts = wp_parse_auth_cookie( $cookie, 'logged_in' );

		if ( is_array( $parts ) ) {
			$this->session_token = (string) ( $parts['token'] ?? '' );
			$this->expiration    = (int) ( $parts['expiration'] ?? 0 );
		}

		return LOGGED_IN_COOKIE . '=' . $cookie;
	}

	/**
	 * Create a short-lived credential for the acting user.
	 *
	 * @param int $user_id The acting user.
	 * @return string Cookie header value, or '' when one cannot be made.
	 */
	private function mintCookie( int $user_id ): string {
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! get_userdata( $user_id ) ) {
			return '';
		}

		$expiration = time() + self::LIFETIME;

		$this->minted_token  = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
		$this->session_token = $this->minted_token;
		$this->expiration    = $expiration;

		$value = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $this->minted_token );

		$this->cookie = LOGGED_IN_COOKIE . '=' . $value;
		$this->nonce  = $this->nonceForToken( $user_id, $value );

		return $this->cookie;
	}

	/**
	 * A REST nonce that matches a cookie we just made.
	 *
	 * `wp_create_nonce()` derives the nonce from the *current* request's session
	 * token and user, both of which it reads from the environment. Rather than
	 * reimplementing that derivation — and inheriting the job of keeping the
	 * reimplementation in step with core — the environment is briefly set to
	 * the credential being made, and put back afterwards.
	 *
	 * @param int    $user_id The acting user.
	 * @param string $cookie  The generated cookie value.
	 * @return string
	 */
	private function nonceForToken( int $user_id, string $cookie ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Saved verbatim and put back verbatim; sanitising it would corrupt the caller's own cookie.
		$previous_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		$previous_user   = get_current_user_id();

		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;

		wp_set_current_user( $user_id );

		try {
			return wp_create_nonce( 'wp_rest' );
		} finally {
			if ( null === $previous_cookie ) {
				unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			} else {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $previous_cookie;
			}

			wp_set_current_user( $previous_user );
		}
	}
}
