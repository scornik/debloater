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
	 * @return array<string,string>
	 */
	public function headers(): array {
		$cookie = $this->resolve();

		if ( '' === $cookie ) {
			return array();
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

		$this->minted_token = '';
		$this->cookie       = null;
		$this->nonce        = '';
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

		$this->minted_token = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );

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
