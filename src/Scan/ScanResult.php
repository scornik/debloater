<?php
/**
 * What a scan produced.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan;

use WPDebloat\Contracts\FactSet;

/**
 * The facts, plus how the scan went getting them.
 *
 * The diagnostics are not decoration. A scan that took eleven seconds, or where
 * the database scanner threw, produces findings that are incomplete in ways the
 * findings themselves cannot express — so the incompleteness travels alongside
 * them and is shown, rather than being left for someone to notice.
 */
final class ScanResult {

	/**
	 * The facts collected.
	 *
	 * @var FactSet
	 */
	public readonly FactSet $facts;

	/**
	 * Elapsed milliseconds per scanner, keyed by scanner name.
	 *
	 * @var array<string,int>
	 */
	public readonly array $timings;

	/**
	 * Scanners that exceeded the soft budget.
	 *
	 * @var array<int,string>
	 */
	public readonly array $over_budget;

	/**
	 * Scanners that threw, mapped to the failure.
	 *
	 * @var array<string,string>
	 */
	public readonly array $failed;

	/**
	 * Total elapsed milliseconds.
	 *
	 * @var int
	 */
	public readonly int $elapsed_ms;

	/**
	 * Constructor.
	 *
	 * @param FactSet              $facts       Facts collected.
	 * @param array<string,int>    $timings     Elapsed ms per scanner.
	 * @param array<int,string>    $over_budget Scanners over the soft budget.
	 * @param array<string,string> $failed      Scanners that threw.
	 * @param int                  $elapsed_ms  Total elapsed ms.
	 */
	public function __construct(
		FactSet $facts,
		array $timings = array(),
		array $over_budget = array(),
		array $failed = array(),
		int $elapsed_ms = 0
	) {
		$this->facts       = $facts;
		$this->timings     = $timings;
		$this->over_budget = array_values( $over_budget );
		$this->failed      = $failed;
		$this->elapsed_ms  = $elapsed_ms;
	}

	/**
	 * Whether every scanner finished inside its budget without throwing.
	 *
	 * @return bool
	 */
	public function isClean(): bool {
		return array() === $this->failed && array() === $this->over_budget;
	}

	/**
	 * The diagnostics, for the run payload.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		return array(
			'elapsed_ms'  => $this->elapsed_ms,
			'timings'     => $this->timings,
			'over_budget' => $this->over_budget,
			'failed'      => $this->failed,
		);
	}

	/**
	 * A one-line summary of what went wrong, or null when nothing did.
	 *
	 * @return string|null
	 */
	public function errorSummary(): ?string {
		if ( array() === $this->failed ) {
			return null;
		}

		$lines = array();

		foreach ( $this->failed as $scanner => $message ) {
			$lines[] = $scanner . ' — ' . $message;
		}

		return implode( '; ', $lines );
	}
}
