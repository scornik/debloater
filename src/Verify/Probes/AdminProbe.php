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

		$response = $this->http->getAsActor( admin_url() );
		$verdict  = $this->judgeHtml( $response );

		if ( null !== $verdict ) {
			return $verdict;
		}

		if ( Markers::anyPresent( $response->body, Markers::LOGIN_FORM ) ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__(
					'The dashboard answered with the login form, so this check could not confirm whether it renders.',
					'debloater'
				),
				$response->evidence()
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

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			__( 'The dashboard loaded normally.', 'debloater' ),
			$response->evidence()
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
