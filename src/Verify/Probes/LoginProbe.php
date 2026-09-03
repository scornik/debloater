<?php
/**
 * Is there still a way back in.
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
 * GET `/wp-login.php` (BUILD-SPEC §11).
 *
 * The last door. If everything else broke and this still works, the site's
 * owner can sign in and undo the change by hand; if this broke too, they
 * cannot. That is why a non-2xx here is a failure and not a warning.
 */
final class LoginProbe extends AbstractHttpProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'login';
	}

	/**
	 * Fetch the login page.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		$response = $this->http->get( wp_login_url() );
		$verdict  = $this->judgeHtml( $response );

		if ( null !== $verdict ) {
			return $verdict;
		}

		if ( ! Markers::anyPresent( $response->body, Markers::LOGIN_FORM ) ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::WARN,
				__( 'The login page answered, but no sign-in form was found on it.', 'debloater' ),
				$response->evidence()
			);
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			__( 'The login page loaded normally.', 'debloater' ),
			$response->evidence()
		);
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The login page', 'debloater' );
	}
}
