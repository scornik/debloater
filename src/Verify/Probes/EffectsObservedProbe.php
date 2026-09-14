<?php
/**
 * Probe: each applied change shows the effect the registry declares.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Contracts\Context;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;
use Debloater\Registry\Registry;
use Debloater\Storage\State;
use Debloater\Verify\EffectCheck;
use Debloater\Verify\HttpClient;

/**
 * Asks a fresh request whether the selected changes can be seen working.
 *
 * `runtime_registered` asks whether each handler registered. This asks the
 * next question: with it registered, does the fact the registry declares for
 * the tweak read the way it should (`D-0079`)? The request that answers is the
 * same kind the probes already use — `GET debloater/v1/status` over loopback as
 * the acting user — where `Plugin::effectReport()` reads the facts after the
 * handlers have run.
 *
 * - Every observable effect holds: PASS.
 * - Any does not: WARN, "applied but not observed". Not FAIL: the site works,
 *   and a change whose effect a request cannot see is a question for the site
 *   owner rather than a reason to undo everything else in the run.
 * - Changes the registry declares unobservable are listed, not judged.
 */
final class EffectsObservedProbe extends AbstractHttpProbe {

	/**
	 * Registry, for which selected tweaks declare an effect.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * State, for the stored selection.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http     Verification HTTP client.
	 * @param Registry   $registry Registry.
	 * @param State      $state    State.
	 */
	public function __construct( HttpClient $http, Registry $registry, State $state ) {
		parent::__construct( $http );

		$this->registry = $registry;
		$this->state    = $state;
	}

	/**
	 * Probe name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'effects_observed';
	}

	/**
	 * Run the probe.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		$observable = 0;

		foreach ( array_keys( $this->state->selection() ) as $tweak_id ) {
			if ( is_string( $tweak_id ) && $this->registry->has( $tweak_id ) ) {
				$effect      = $this->registry->tweak( $tweak_id )->effect;
				$observable += null !== $effect && $effect->observable ? 1 : 0;
			}
		}

		if ( 0 === $observable ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::PASS,
				__( 'No selected change has an effect a request can observe, so there was nothing to check.', 'hakeemify-debloater' ),
				array( 'observable' => 0 )
			);
		}

		if ( ! $this->http->canActAsUser() ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__( 'Whether the changes can be seen working could not be checked, because there was no signed-in user to ask as.', 'hakeemify-debloater' )
			);
		}

		$response = $this->http->getAsActor( rest_url( 'debloater/v1/status' ) );

		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		if ( $this->http->redirectLeavesSite( $response ) ) {
			return $this->offsiteRedirect( $response );
		}

		$status = $response->json();

		if ( ! $response->isSuccess() || ! is_array( $status ) || ! is_array( $status['effects'] ?? null ) ) {
			// UNKNOWN rather than FAIL: `runtime_registered` reads the same
			// response and fails the run when it is unreadable, and one broken
			// response should not be counted twice.
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The status endpoint did not report effects (HTTP %d), so whether the changes can be seen working is not known.', 'hakeemify-debloater' ),
					$response->status
				),
				$response->evidence()
			);
		}

		$counts  = array(
			EffectCheck::OBSERVED      => array(),
			EffectCheck::NOT_OBSERVED  => array(),
			EffectCheck::NOT_COLLECTED => array(),
			EffectCheck::UNOBSERVABLE  => array(),
		);
		$details = array();

		foreach ( $status['effects'] as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['tweak'] ?? null ) || ! isset( $counts[ $row['status'] ?? '' ] ) ) {
				continue;
			}

			$counts[ $row['status'] ][] = $row['tweak'];

			if ( EffectCheck::NOT_OBSERVED === $row['status'] ) {
				$details[] = sprintf(
					'%s (%s is %s, expected %s)',
					$row['tweak'],
					(string) ( $row['fact'] ?? '' ),
					(string) wp_json_encode( $row['actual'] ?? null ),
					(string) ( $row['expected'] ?? '' )
				);
			}
		}

		$evidence = array(
			'observed'      => implode( ', ', $counts[ EffectCheck::OBSERVED ] ),
			'not_observed'  => implode( '; ', $details ),
			'not_collected' => implode( ', ', $counts[ EffectCheck::NOT_COLLECTED ] ),
			'unobservable'  => implode( ', ', $counts[ EffectCheck::UNOBSERVABLE ] ),
		);

		if ( array() !== $details ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::WARN,
				sprintf(
					/* translators: %s: semicolon-separated list of changes and what was read. */
					__( 'Applied but not observed: %s', 'hakeemify-debloater' ),
					implode( '; ', $details )
				),
				$evidence
			);
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			sprintf(
				/* translators: 1: changes observed, 2: changes no request can observe. */
				__( '%1$d changes observed working in a fresh request; %2$d cannot be observed from a request.', 'hakeemify-debloater' ),
				count( $counts[ EffectCheck::OBSERVED ] ),
				count( $counts[ EffectCheck::UNOBSERVABLE ] )
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
		return __( 'The applied changes', 'hakeemify-debloater' );
	}
}
