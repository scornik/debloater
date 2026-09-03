<?php
/**
 * Is the runtime we generated the one the site is running.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Apply\RuntimeLoader;
use Debloater\Contracts\Context;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;
use Debloater\Storage\State;

/**
 * GET `/wp-json/debloater/v1/status` (BUILD-SPEC §11).
 *
 * Every other probe asks whether the site survived. This one asks whether the
 * change actually happened — over HTTP, in a real request, rather than by
 * reading our own state option and believing it.
 *
 * The distinction matters more than it looks. A selection that was saved but
 * whose runtime never loaded is a site that is unchanged while reporting that
 * it has been optimised, and a user acting on that report would be acting on
 * fiction.
 */
final class RuntimeLoadedProbe extends AbstractHttpProbe {

	/**
	 * Plugin state, for what we believe we generated.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Constructor.
	 *
	 * @param \Debloater\Verify\HttpClient $http  The HTTP client.
	 * @param State                        $state Plugin state.
	 */
	public function __construct( \Debloater\Verify\HttpClient $http, State $state ) {
		parent::__construct( $http );

		$this->state = $state;
	}

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'runtime_loaded';
	}

	/**
	 * Ask the status endpoint what the site is running.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		$expected = $this->state->runtimeHash();
		$selected = $this->state->selection();

		if ( array() === $selected && '' === $expected ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::PASS,
				__( 'Nothing is selected, so there is no runtime to load — which is exactly what was found.', 'debloater' ),
				array( 'selection_count' => 0 )
			);
		}

		$response = $this->http->getAsActor( rest_url( 'debloater/v1/status' ) );

		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		if ( 401 === $response->status || 403 === $response->status ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::UNKNOWN,
				__(
					'The status endpoint could not be read as the signed-in user, so it is not known whether the generated runtime is loaded.',
					'debloater'
				),
				$response->evidence()
			);
		}

		$status = $response->json();

		if ( ! $response->isSuccess() || null === $status || ! is_array( $status['runtime'] ?? null ) ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The status endpoint did not answer with a readable status (HTTP %d).', 'debloater' ),
					$response->status
				),
				$response->evidence()
			);
		}

		/** @var array<string,mixed> $runtime */
		$runtime  = $status['runtime'];
		$actual   = is_string( $runtime['hash'] ?? null ) ? $runtime['hash'] : '';
		$loader   = is_array( $status['loader'] ?? null ) ? $status['loader'] : array();
		$mode     = is_string( $loader['mode'] ?? null ) ? $loader['mode'] : RuntimeLoader::MODE_NONE;
		$evidence = array_merge(
			$response->evidence(),
			array(
				'expected_hash' => substr( $expected, 0, 12 ),
				'actual_hash'   => substr( $actual, 0, 12 ),
				'loader_mode'   => $mode,
			)
		);

		if ( '' === $actual ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				__( 'There is a selection saved, but no runtime file is in place, so none of the selected changes are active.', 'debloater' ),
				$evidence
			);
		}

		if ( '' !== $expected && ! hash_equals( $expected, $actual ) ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				__( 'The runtime file on disk is not the one this change generated, so the site is running something else.', 'debloater' ),
				$evidence
			);
		}

		if ( RuntimeLoader::MODE_FALLBACK === $mode ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::WARN,
				__(
					'The changes are active, but they load from the plugin rather than an mu-plugin, so they start later in the request than they could.',
					'debloater'
				),
				$evidence
			);
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			__( 'The generated runtime is in place and matches what was written.', 'debloater' ),
			$evidence
		);
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The status endpoint', 'debloater' );
	}
}
