<?php
/**
 * Probe: the stored selection registers in a fresh request.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Apply\Runtime;
use Debloater\Contracts\Context;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;
use Debloater\Verify\HttpClient;

/**
 * Asks a new request whether the handlers just stored actually registered.
 *
 * Every other probe asks whether the site still works. None of them asks
 * whether the change happened, so a run could commit with every handler
 * skipped — a file missing, the guard unreadable, `DEBLOATER_DISABLE` defined —
 * and report the changes as applied. This one does (`D-0079`).
 *
 * It cannot ask its own request. The apply request loaded the runtime at
 * `plugins_loaded`, before the selection changed, so only a later request
 * registers what was just stored. It fetches `GET debloater/v1/status` over
 * loopback as the acting user, and compares that request's
 * `runtime.registered` with the handler list this request stored.
 *
 * - Every stored handler registered: PASS.
 * - Any stored handler did not: FAIL, which rolls the run back. A plan whose
 *   changes are not in effect is not a plan that was applied.
 * - The status could not be read as the signed-in user, or the site cannot
 *   reach itself: UNKNOWN, like every other probe that cannot ask.
 */
final class RuntimeRegisteredProbe extends AbstractHttpProbe {

	/**
	 * The runtime, for what this request stored.
	 *
	 * @var Runtime
	 */
	private Runtime $runtime;

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http    Verification HTTP client.
	 * @param Runtime    $runtime The runtime.
	 */
	public function __construct( HttpClient $http, Runtime $runtime ) {
		parent::__construct( $http );

		$this->runtime = $runtime;
	}

	/**
	 * Probe name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'runtime_registered';
	}

	/**
	 * Run the probe.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		$expected = $this->runtime->storedClasses();

		if ( array() === $expected ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::PASS,
				__( 'No handlers are stored, so there is nothing that needed to register.', 'hakeemify-debloater' ),
				array( 'stored' => 0 )
			);
		}

		if ( ! $this->http->canActAsUser() ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__( 'Whether the stored changes registered could not be checked, because there was no signed-in user to ask as.', 'hakeemify-debloater' ),
				array( 'stored' => count( $expected ) )
			);
		}

		$response = $this->http->getAsActor( rest_url( 'debloater/v1/status' ) );

		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		if ( $this->http->redirectLeavesSite( $response ) ) {
			return $this->offsiteRedirect( $response );
		}

		if ( 401 === $response->status || 403 === $response->status ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__( 'The status endpoint refused the signed-in check, so whether the stored changes registered is not known.', 'hakeemify-debloater' ),
				$response->evidence()
			);
		}

		$status  = $response->json();
		$runtime = is_array( $status ) && is_array( $status['runtime'] ?? null ) ? $status['runtime'] : null;

		if ( ! $response->isSuccess() || null === $runtime || ! is_array( $runtime['registered'] ?? null ) ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The status endpoint did not say what registered (HTTP %d), so the stored changes cannot be shown to be in effect.', 'hakeemify-debloater' ),
					$response->status
				),
				$response->evidence()
			);
		}

		$registered = array_values( array_filter( $runtime['registered'], 'is_string' ) );
		$missing    = array_values( array_diff( $expected, $registered ) );
		$guard      = is_string( $runtime['guard'] ?? null ) ? $runtime['guard'] : '';
		$skipped    = array();

		foreach ( is_array( $runtime['skipped'] ?? null ) ? $runtime['skipped'] : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$skipped[] = (string) ( $entry['class'] ?? '' ) . ': ' . (string) ( $entry['reason'] ?? '' );
			}
		}

		// Evidence is flat scalars (ProbeResult), so the lists travel as text.
		$evidence = array(
			'stored'     => count( $expected ),
			'registered' => count( $registered ),
			'missing'    => implode( ', ', $missing ),
			'guard'      => $guard,
			'skipped'    => implode( '; ', $skipped ),
		);

		if ( array() === $missing ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::PASS,
				sprintf(
					/* translators: %d: number of handlers. */
					_n(
						'%d stored change registered in a fresh request.',
						'All %d stored changes registered in a fresh request.',
						count( $expected ),
						'hakeemify-debloater'
					),
					count( $expected )
				),
				$evidence
			);
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::FAIL,
			sprintf(
				/* translators: 1: number of handlers that did not register, 2: number stored, 3: comma-separated handler names. */
				__( '%1$d of %2$d stored changes did not register in a fresh request, so they are not in effect: %3$s', 'hakeemify-debloater' ),
				count( $missing ),
				count( $expected ),
				implode( ', ', $missing )
			),
			$evidence
		);
	}

	/**
	 * What this probe checks, for messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The stored changes', 'hakeemify-debloater' );
	}
}
