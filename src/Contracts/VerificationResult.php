<?php
/**
 * The aggregate outcome of a verification pass.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Verification result (BUILD-SPEC §11).
 *
 * The aggregate is computed here, not by the caller, so the rule exists exactly
 * once: FAIL if any probe failed; otherwise WARN if any probe warned or was
 * UNKNOWN; otherwise PASS. NOT_TESTED probes are listed and shown to the user
 * but never counted, so an untested checkout can never be mistaken for a
 * passing one.
 *
 * A verification with no probes that count is UNKNOWN, not PASS — an empty
 * check has proved nothing.
 */
final class VerificationResult {

	/**
	 * Individual probe results, in probe-name order.
	 *
	 * @var array<int,ProbeResult>
	 */
	public readonly array $probes;

	/**
	 * Aggregate status.
	 *
	 * @var ProbeStatus
	 */
	public readonly ProbeStatus $status;

	/**
	 * Constructor.
	 *
	 * @param array<int,ProbeResult> $probes Probe results.
	 * @throws ContractViolation When a non-ProbeResult or duplicate probe is given.
	 */
	public function __construct( array $probes ) {
		$by_name = array();

		foreach ( $probes as $index => $probe ) {
			if ( ! $probe instanceof ProbeResult ) {
				throw ContractViolation::type( self::class, 'probes[' . $index . ']', ProbeResult::class, $probe );
			}

			if ( array_key_exists( $probe->probe, $by_name ) ) {
				throw ContractViolation::range(
					self::class,
					'probes',
					sprintf( 'probe "%s" reported more than once', $probe->probe )
				);
			}

			$by_name[ $probe->probe ] = $probe;
		}

		ksort( $by_name, SORT_STRING );

		$this->probes = array_values( $by_name );
		$this->status = self::aggregate( $this->probes );
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid or the status disagrees.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'probes', 'status' ) );

		$probes = array();

		foreach ( Assert::arrayList( self::class, $data, 'probes' ) as $entry ) {
			$probes[] = ProbeResult::fromArray( $entry );
		}

		$result = new self( $probes );

		if ( array_key_exists( 'status', $data ) && $data['status'] !== $result->status->value ) {
			throw ContractViolation::range(
				self::class,
				'status',
				'is derived from the probe results and must match them'
			);
		}

		return $result;
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'probes' => array_map(
				static fn ( ProbeResult $probe ): array => $probe->toArray(),
				$this->probes
			),
			'status' => $this->status->value,
		);
	}

	/**
	 * Whether verification failed and the run must be rolled back.
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ProbeStatus::FAIL === $this->status;
	}

	/**
	 * Whether verification passed cleanly.
	 *
	 * @return bool
	 */
	public function isClean(): bool {
		return ProbeStatus::PASS === $this->status;
	}

	/**
	 * The probes that failed.
	 *
	 * @return array<int,ProbeResult>
	 */
	public function failures(): array {
		return array_values(
			array_filter( $this->probes, static fn ( ProbeResult $probe ): bool => $probe->status->isFailure() )
		);
	}

	/**
	 * The probes that did not apply to this stack.
	 *
	 * @return array<int,ProbeResult>
	 */
	public function notTested(): array {
		return array_values(
			array_filter(
				$this->probes,
				static fn ( ProbeResult $probe ): bool => ProbeStatus::NOT_TESTED === $probe->status
			)
		);
	}

	/**
	 * Compute the aggregate status from probe results.
	 *
	 * @param array<int,ProbeResult> $probes Probe results.
	 * @return ProbeStatus
	 */
	private static function aggregate( array $probes ): ProbeStatus {
		$counted = false;
		$warning = false;

		foreach ( $probes as $probe ) {
			if ( ! $probe->status->countsTowardAggregate() ) {
				continue;
			}

			$counted = true;

			if ( $probe->status->isFailure() ) {
				return ProbeStatus::FAIL;
			}

			if ( $probe->status->isWarning() ) {
				$warning = true;
			}
		}

		if ( ! $counted ) {
			return ProbeStatus::UNKNOWN;
		}

		return $warning ? ProbeStatus::WARN : ProbeStatus::PASS;
	}
}
