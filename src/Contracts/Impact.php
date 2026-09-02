<?php
/**
 * The estimated effect of acting on a finding.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Potential impact of a finding (BUILD-SPEC §6).
 *
 * Impact is always expressed in a countable unit — requests, bytes, rows,
 * events — never in time. The `measurable` flag says whether the Meter can
 * prove the delta after applying; an estimate that cannot be measured must say
 * so rather than being presented as a result (locked decision #1, §12).
 */
final class Impact {

	/**
	 * What is being estimated, e.g. "admin_ajax_requests_per_hour".
	 *
	 * @var string
	 */
	public readonly string $kind;

	/**
	 * Estimated magnitude in the given unit.
	 *
	 * @var float
	 */
	public readonly float $estimate;

	/**
	 * Unit of the estimate, e.g. "requests", "bytes", "rows".
	 *
	 * @var string
	 */
	public readonly string $unit;

	/**
	 * Whether the Meter can measure this before and after.
	 *
	 * @var bool
	 */
	public readonly bool $measurable;

	/**
	 * Constructor.
	 *
	 * @param string $kind       Estimated quantity.
	 * @param float  $estimate   Magnitude.
	 * @param string $unit       Unit of measure.
	 * @param bool   $measurable Whether the Meter can prove the delta.
	 * @throws ContractViolation When a field is empty or the estimate is not finite.
	 */
	public function __construct( string $kind, float $estimate, string $unit, bool $measurable ) {
		if ( '' === $kind ) {
			throw ContractViolation::range( self::class, 'kind', 'must not be empty' );
		}

		if ( '' === $unit ) {
			throw ContractViolation::range( self::class, 'unit', 'must not be empty' );
		}

		if ( is_nan( $estimate ) || is_infinite( $estimate ) ) {
			throw ContractViolation::range( self::class, 'estimate', 'must be a finite number' );
		}

		if ( $estimate < 0.0 ) {
			throw ContractViolation::range( self::class, 'estimate', 'must not be negative' );
		}

		$this->kind       = $kind;
		$this->estimate   = $estimate;
		$this->unit       = $unit;
		$this->measurable = $measurable;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'kind', 'estimate', 'unit', 'measurable' ) );

		return new self(
			Assert::string( self::class, $data, 'kind' ),
			Assert::float( self::class, $data, 'estimate' ),
			Assert::string( self::class, $data, 'unit' ),
			Assert::bool( self::class, $data, 'measurable' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array{kind:string,estimate:float,unit:string,measurable:bool}
	 */
	public function toArray(): array {
		return array(
			'kind'       => $this->kind,
			'estimate'   => $this->estimate,
			'unit'       => $this->unit,
			'measurable' => $this->measurable,
		);
	}
}
