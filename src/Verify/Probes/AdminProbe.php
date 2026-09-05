<?php
/**
 * Can the site still be administered.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Contracts\Context;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;
use Debloater\Verify\Markers;

/**
 * GET `/wp-admin/` with the acting user's cookie (BUILD-SPEC §11).
 *
 * The probe that protects the user from being locked out of their own site,
 * which is the worst thing this plugin could do to somebody. A change that
 * breaks the dashboard also breaks the way back — so if this fails, the run is
 * rolled back without asking.
 *
 * Being redirected to the login form is not a pass, and it is not a failure
 * either: it means the credential did not work, which says nothing about
 * whether the dashboard renders. That is UNKNOWN, and it says so.
 *
 * ## Two ways to be turned away, and why they are told apart
 *
 * Redirects are not followed here, because following them destroys the
 * evidence. A **302 to `wp-login.php`** is core rejecting the credential: it
 * read the cookie, it did not accept it, and the usual cause is the scheme —
 * an `auth` cookie sent to an admin that `force_ssl_admin()` has put behind
 * https reads as a hash mismatch. A **200 carrying the login form** is
 * something in front of core removing the cookie before it arrived: a proxy, a
 * security plugin, a host that strips `Cookie` from loopback requests.
 *
 * Both are UNKNOWN — neither says anything about whether the change broke the
 * dashboard — but they are different problems with different fixes, and a
 * probe that reports "could not confirm" for both is asking the site owner to
 * guess. Status codes alone are not enough for either: the second arrives as a
 * perfectly ordinary 200.
 */
final class AdminProbe extends AbstractHttpProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'admin';
	}

	/**
	 * Fetch the dashboard.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		if ( ! $this->http->canActAsUser() ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__(
					'The dashboard could not be checked, because there was no signed-in user to check it as.',
					'debloater'
				)
			);
		}

		$url      = admin_url();
		$response = $this->http->getAsActorWithoutRedirects( $url );

		// One redirect is followed by hand, and only one: an admin behind
		// `force_ssl_admin()` answers http with a redirect to itself over
		// https, and the credential for that URL is a different cookie. Asking
		// again at the URL it named is the whole of the handling.
		if ( $response->isRedirect() && ! $response->redirectsToLogin() && '' !== $response->location ) {
			$response = $this->http->getAsActorWithoutRedirects( $response->location );
		}

		if ( $response->redirectsToLogin() ) {
			return $this->credentialRejected( $response, $url );
		}

		$verdict = $this->judgeHtml( $response );

		if ( null !== $verdict ) {
			return $verdict;
		}

		// A 200 is not proof of anything on its own. A host that strips the
		// cookie produces a perfectly ordinary 200 containing a login form.
		if ( Markers::anyPresent( $response->body, Markers::LOGIN_FORM ) ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__(
					'The dashboard answered with the login form rather than a redirect, which usually means something between this site and itself removed the sign-in cookie. This check could not confirm whether the dashboard renders.',
					'debloater'
				),
				array_merge( $response->evidence(), array( 'cookie_reached_core' => 'no' ) )
			);
		}

		$missing = Markers::missing( $response->body, Markers::ADMIN );

		if ( array() !== $missing ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::WARN,
				sprintf(
					/* translators: %s: comma-separated markers. */
					__( 'The dashboard loaded, but parts of it are missing: %s not found.', 'debloater' ),
					implode( ', ', $missing )
				),
				array_merge( $response->evidence(), array( 'missing_markers' => implode( ',', $missing ) ) )
			);
		}

		// The dashboard rendered. Whether it rendered *for the actor* is a
		// second question: the admin bar's account item is only printed for a
		// signed-in user, so its absence means the page came back without the
		// session having been established.
		$anonymous = Markers::missing( $response->body, Markers::ADMIN_BAR );

		if ( array() !== $anonymous ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::WARN,
				sprintf(
					/* translators: %s: comma-separated markers. */
					__( 'The dashboard loaded, but not as a signed-in user: %s not found.', 'debloater' ),
					implode( ', ', $anonymous )
				),
				array_merge( $response->evidence(), array( 'missing_markers' => implode( ',', $anonymous ) ) )
			);
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			__( 'The dashboard loaded normally, signed in.', 'debloater' ),
			$response->evidence()
		);
	}

	/**
	 * Core read the credential and would not take it.
	 *
	 * @param \Debloater\Verify\Response $response The redirect.
	 * @param string                      $url      The URL asked for.
	 * @return ProbeResult
	 */
	private function credentialRejected( $response, string $url ): ProbeResult {
		$scheme = $this->http->actorCookieScheme( $url );
		$sent   = $this->http->actorHasAdminCredential();

		// Two different reports, because they are two different problems. One
		// is a credential core would not take; the other is no credential at
		// all, and telling somebody to check their site's address when this
		// site simply had no session to offer would send them looking in the
		// wrong place.
		$message = $sent
			? sprintf(
				/* translators: %s: the cookie scheme, either secure_auth or auth. */
				__(
					'The dashboard sent this check back to the sign-in page, so WordPress read the sign-in cookie and would not accept it. The usual cause is the address: the cookie was signed for %s, and an admin reached over the other scheme rejects it. This check could not confirm whether the dashboard renders.',
					'debloater'
				),
				$scheme
			)
			: __(
				'The dashboard sent this check back to the sign-in page, because there was no sign-in cookie to send: this site had no session for the person making the change. This check could not confirm whether the dashboard renders.',
				'debloater'
			);

		return new ProbeResult(
			$this->name(),
			ProbeStatus::UNKNOWN,
			$message,
			array_merge(
				$response->evidence(),
				array(
					'cookie_scheme'       => $scheme,
					'cookie_reached_core' => $sent ? 'yes' : 'none sent',
					'redirected_to_login' => 'yes',
				)
			)
		);
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The dashboard', 'debloater' );
	}
}
