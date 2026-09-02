<?php
/**
 * Runs the probes and adds up what they found.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify;

use Throwable;
use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\ProbeInterface;
use WPDebloat\Contracts\ProbeResult;
use WPDebloat\Contracts\ProbeStatus;
use WPDebloat\Contracts\VerificationResult;

/**
 * Verification (BUILD-SPEC §11).
 *
 * The aggregation rule lives in `VerificationResult`, not here, so this class
 * only has to decide what to run and how to treat an environment that will not
 * let it run anything.
 *
 * Two behaviours are worth stating plainly:
 *
 * - **Loopback is checked first.** If the site cannot reach itself, every HTTP
 *   probe would fail in the same uninformative way, one fifteen-second timeout
 *   at a time. Instead they are all reported UNKNOWN with the reason, which
 *   aggregates to a warning: the change is kept, and the user is told the
 *   checks could not run (docs/DECISIONS.md D-0020).
 * - **A probe that throws is UNKNOWN, not FAIL.** A bug in a probe is not
 *   evidence that the site is broken, and rolling a user's site back because
 *   our own code threw would be the plugin punishing them for our mistake.
 */
final class Verifier {

	/**
	 * Constant naming a probe that must report FAIL.
	 *
	 * Exists so the rollback path can be exercised end to end without breaking
	 * a real site to do it. Defining it on a production site makes verification
	 * fail and every apply roll back, which is inconvenient but never unsafe.
	 */
	public const TEST_FAIL_CONSTANT = 'WPDEBLOAT_TEST_FAIL_PROBE';

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The probes to run, in order.
	 *
	 * @var array<int,ProbeInterface>
	 */
	private array $probes;

	/**
	 * The HTTP client, for the loopback check.
	 *
	 * @var HttpClient
	 */
	private HttpClient $http;

	/**
	 * Constructor.
	 *
	 * @param Context                   $context Site context.
	 * @param array<int,ProbeInterface> $probes  Probes to run.
	 * @param HttpClient                $http    HTTP client.
	 */
	public function __construct( Context $context, array $probes, HttpClient $http ) {
		$this->context = $context;
		$this->probes  = $probes;
		$this->http    = $http;
	}

	/**
	 * Run every probe and aggregate the results.
	 *
	 * @param FactSet|null $facts Facts from the most recent scan, when there is one.
	 * @return VerificationResult
	 */
	public function verify( ?FactSet $facts = null ): VerificationResult {
		$facts     = $facts ?? new FactSet( array() );
		$loopback  = $this->http->loopbackCheck();
		$reachable = $loopback->reachable();
		$results   = array();

		try {
			foreach ( $this->probes as $probe ) {
				$results[] = $this->runOne( $probe, $facts, $reachable, $loopback->error );
			}
		} finally {
			// A credential minted for this verification has no business
			// outliving it.
			$this->http->releaseSession();
		}

		return new VerificationResult( $results );
	}

	/**
	 * The names of the probes this verifier will run.
	 *
	 * @return array<int,string>
	 */
	public function probeNames(): array {
		return array_map( static fn ( ProbeInterface $probe ): string => $probe->name(), $this->probes );
	}

	/**
	 * Run one probe, with every safety net in place.
	 *
	 * @param ProbeInterface $probe     The probe.
	 * @param FactSet        $facts     Facts from the most recent scan.
	 * @param bool           $reachable Whether the site can reach itself.
	 * @param string         $error     The loopback error, when it cannot.
	 * @return ProbeResult
	 */
	private function runOne( ProbeInterface $probe, FactSet $facts, bool $reachable, string $error ): ProbeResult {
		$forced = $this->forcedFailure( $probe->name() );

		if ( null !== $forced ) {
			return $forced;
		}

		if ( ! $probe->applies( $this->context, $facts ) ) {
			return new ProbeResult(
				$probe->name(),
				ProbeStatus::NOT_TESTED,
				sprintf(
					/* translators: %s: probe name. */
					__( 'The "%s" check does not apply to this site, so it was not run.', 'wp-debloat' ),
					$probe->name()
				)
			);
		}

		if ( ! $reachable ) {
			return new ProbeResult(
				$probe->name(),
				ProbeStatus::UNKNOWN,
				sprintf(
					/* translators: %s: the underlying connection error. */
					__( 'This site cannot make requests to itself, so nothing could be checked over HTTP: %s', 'wp-debloat' ),
					$error
				),
				array( 'loopback_blocked' => true )
			);
		}

		try {
			return $probe->run( $this->context );
		} catch ( Throwable $thrown ) {
			return new ProbeResult(
				$probe->name(),
				ProbeStatus::UNKNOWN,
				sprintf(
					/* translators: 1: probe name, 2: the error. */
					__( 'The "%1$s" check could not complete: %2$s', 'wp-debloat' ),
					$probe->name(),
					$thrown->getMessage()
				)
			);
		}
	}

	/**
	 * A forced failure for the named probe, when the test constant asks for one.
	 *
	 * @param string $name Probe name.
	 * @return ProbeResult|null
	 */
	private function forcedFailure( string $name ): ?ProbeResult {
		if ( ! defined( self::TEST_FAIL_CONSTANT ) ) {
			return null;
		}

		$forced = constant( self::TEST_FAIL_CONSTANT );

		if ( ! is_string( $forced ) || $forced !== $name ) {
			return null;
		}

		return new ProbeResult(
			$name,
			ProbeStatus::FAIL,
			sprintf(
				/* translators: 1: probe name, 2: constant name. */
				__( 'The "%1$s" check was forced to fail by the %2$s constant.', 'wp-debloat' ),
				$name,
				self::TEST_FAIL_CONSTANT
			),
			array( 'forced' => true )
		);
	}
}
