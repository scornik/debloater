<?php
/**
 * Everything measured in one pass.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Meter;

/**
 * One complete reading of a site (BUILD-SPEC §12).
 *
 * Taken before a change and again after it, so the two can be subtracted. The
 * set records when it was taken and on which pages, because a "before" and an
 * "after" measured on different pages would produce a delta that means nothing.
 */
final class MeasurementSet {

	/**
	 * Measurements, keyed by metric name.
	 *
	 * @var array<string,Measurement>
	 */
	public readonly array $measurements;

	/**
	 * When it was taken, UTC.
	 *
	 * @var string
	 */
	public readonly string $taken_at;

	/**
	 * The pages that were fetched, if any.
	 *
	 * @var array<int,string>
	 */
	public readonly array $targets;

	/**
	 * Constructor.
	 *
	 * @param array<int,Measurement> $measurements Measurements.
	 * @param array<int,string>      $targets      Pages fetched.
	 * @param string                 $taken_at     UTC timestamp.
	 */
	public function __construct( array $measurements, array $targets = array(), string $taken_at = '' ) {
		$keyed = array();

		foreach ( $measurements as $measurement ) {
			if ( $measurement instanceof Measurement ) {
				$keyed[ $measurement->metric ] = $measurement;
			}
		}

		ksort( $keyed, SORT_STRING );

		$this->measurements = $keyed;
		$this->targets      = array_values( $targets );
		$this->taken_at     = '' === $taken_at ? gmdate( 'Y-m-d\TH:i:s\Z' ) : $taken_at;
	}

	/**
	 * One measurement by name.
	 *
	 * @param string $metric Metric name.
	 * @return Measurement|null
	 */
	public function get( string $metric ): ?Measurement {
		return $this->measurements[ $metric ] ?? null;
	}

	/**
	 * Whether anything at all was measured.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		foreach ( $this->measurements as $measurement ) {
			if ( $measurement->isAvailable() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Array shape.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'taken_at'     => $this->taken_at,
			'targets'      => $this->targets,
			'measurements' => array_map(
				static fn ( Measurement $measurement ): array => $measurement->toArray(),
				array_values( $this->measurements )
			),
		);
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$measurements = array();

		foreach ( is_array( $data['measurements'] ?? null ) ? $data['measurements'] : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$measurements[] = Measurement::fromArray( $entry );
			}
		}

		return new self(
			$measurements,
			is_array( $data['targets'] ?? null ) ? array_values( array_filter( $data['targets'], 'is_string' ) ) : array(),
			is_string( $data['taken_at'] ?? null ) ? $data['taken_at'] : ''
		);
	}
}
