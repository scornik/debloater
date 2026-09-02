<?php
/**
 * One number, with its unit and where it came from.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Meter;

/**
 * A single measured value (BUILD-SPEC §12).
 *
 * Three things travel together and must never be separated: the number, the
 * unit it is in, and whether it was actually measured. A metric that could not
 * be taken is `available = false` and carries no value — reporting it as zero
 * would be inventing a measurement, and zero is a perfectly plausible number for
 * most of these metrics.
 */
final class Measurement {

	/**
	 * Metric name, e.g. "frontend.requests".
	 *
	 * @var string
	 */
	public readonly string $metric;

	/**
	 * The measured value, or null when it could not be measured.
	 *
	 * @var float|null
	 */
	public readonly ?float $value;

	/**
	 * The unit, e.g. "requests", "bytes", "rows".
	 *
	 * @var string
	 */
	public readonly string $unit;

	/**
	 * What was measured, e.g. a URL, or '' for site-wide metrics.
	 *
	 * @var string
	 */
	public readonly string $target;

	/**
	 * Why it could not be measured, when it could not.
	 *
	 * @var string
	 */
	public readonly string $unavailable_because;

	/**
	 * Constructor.
	 *
	 * @param string     $metric              Metric name.
	 * @param float|null $value               Measured value, or null.
	 * @param string     $unit                Unit.
	 * @param string     $target              What was measured.
	 * @param string     $unavailable_because Why it could not be measured.
	 */
	public function __construct(
		string $metric,
		?float $value,
		string $unit,
		string $target = '',
		string $unavailable_because = ''
	) {
		$this->metric              = $metric;
		$this->value               = $value;
		$this->unit                = $unit;
		$this->target              = $target;
		$this->unavailable_because = $unavailable_because;
	}

	/**
	 * A measurement that could not be taken.
	 *
	 * @param string $metric Metric name.
	 * @param string $unit   Unit it would have been in.
	 * @param string $reason Why it could not be taken.
	 * @param string $target What would have been measured.
	 * @return self
	 */
	public static function unavailable( string $metric, string $unit, string $reason, string $target = '' ): self {
		return new self( $metric, null, $unit, $target, $reason );
	}

	/**
	 * Whether there is a number here.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return null !== $this->value;
	}

	/**
	 * Array shape.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'metric'    => $this->metric,
			'value'     => $this->value,
			'unit'      => $this->unit,
			'target'    => $this->target,
			'available' => $this->isAvailable(),
			'reason'    => '' === $this->unavailable_because ? null : $this->unavailable_because,
		);
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$value = $data['value'] ?? null;

		return new self(
			is_string( $data['metric'] ?? null ) ? $data['metric'] : '',
			is_numeric( $value ) ? (float) $value : null,
			is_string( $data['unit'] ?? null ) ? $data['unit'] : '',
			is_string( $data['target'] ?? null ) ? $data['target'] : '',
			is_string( $data['reason'] ?? null ) ? $data['reason'] : ''
		);
	}
}
