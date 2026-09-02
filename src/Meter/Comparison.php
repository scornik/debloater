<?php
/**
 * Before, after, and the difference between them.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Meter;

/**
 * Deltas from two readings (BUILD-SPEC §12).
 *
 * Every rule here exists to stop a number being claimed that was not measured:
 *
 * - A metric measured before but not after produces **no delta**, not a fall to
 *   zero. The most common reason for a missing "after" is that the site could
 *   not be reached, and "we could not check" must never be reported as "it went
 *   to nothing".
 * - A metric that did not change is reported as unchanged rather than omitted.
 *   A report that lists only improvements is an advertisement.
 * - Percentages are only computed when the "before" was non-zero. There is no
 *   honest percentage change from zero.
 * - Nothing here converts a count into time. §12 is explicit: never reported as
 *   time saved, and a test asserts the word "faster" appears nowhere in what
 *   this produces.
 */
final class Comparison {

	/**
	 * The reading taken before the change.
	 *
	 * @var MeasurementSet
	 */
	public readonly MeasurementSet $before;

	/**
	 * The reading taken after it.
	 *
	 * @var MeasurementSet
	 */
	public readonly MeasurementSet $after;

	/**
	 * Constructor.
	 *
	 * @param MeasurementSet $before Reading before the change.
	 * @param MeasurementSet $after  Reading after it.
	 */
	public function __construct( MeasurementSet $before, MeasurementSet $after ) {
		$this->before = $before;
		$this->after  = $after;
	}

	/**
	 * Every metric that can be compared, with its delta.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function deltas(): array {
		$deltas = array();

		foreach ( $this->before->measurements as $metric => $before ) {
			$after = $this->after->get( $metric );

			if ( null === $after || ! $before->isAvailable() || ! $after->isAvailable() ) {
				$deltas[] = array(
					'metric'    => $metric,
					'unit'      => $before->unit,
					'before'    => $before->value,
					'after'     => null === $after ? null : $after->value,
					'delta'     => null,
					'percent'   => null,
					'direction' => 'unknown',
					'reason'    => $this->whyUnknown( $before, $after ),
				);

				continue;
			}

			$change = $after->value - $before->value;

			$deltas[] = array(
				'metric'    => $metric,
				'unit'      => $before->unit,
				'before'    => $before->value,
				'after'     => $after->value,
				'delta'     => $change,
				'percent'   => $this->percent( $before->value, $change ),
				'direction' => $this->direction( $change ),
				'reason'    => null,
			);
		}

		return $deltas;
	}

	/**
	 * Only the metrics that actually moved.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function changed(): array {
		return array_values(
			array_filter(
				$this->deltas(),
				static fn ( array $delta ): bool => is_float( $delta['delta'] ) && abs( $delta['delta'] ) > 0.0
			)
		);
	}

	/**
	 * Metrics that could not be compared, and why.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function unknown(): array {
		return array_values(
			array_filter(
				$this->deltas(),
				static fn ( array $delta ): bool => 'unknown' === $delta['direction']
			)
		);
	}

	/**
	 * Array shape, for the report and the API.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'before'  => $this->before->toArray(),
			'after'   => $this->after->toArray(),
			'deltas'  => $this->deltas(),
			'changed' => count( $this->changed() ),
			'unknown' => count( $this->unknown() ),
		);
	}

	/**
	 * The percentage change, when there is an honest one.
	 *
	 * @param float $before Value before.
	 * @param float $change The difference.
	 * @return float|null
	 */
	private function percent( float $before, float $change ): ?float {
		if ( 0.0 === $before ) {
			// There is no percentage change from nothing, and inventing one
			// ("infinite improvement") is exactly the kind of claim this plugin
			// exists not to make.
			return null;
		}

		return round( ( $change / $before ) * 100, 1 );
	}

	/**
	 * Which way a metric moved.
	 *
	 * Deliberately "down" and "up" rather than "better" and "worse". Fewer
	 * requests is usually good and fewer cron events sometimes is not, and this
	 * class has no business deciding which.
	 *
	 * @param float $change The difference.
	 * @return string
	 */
	private function direction( float $change ): string {
		if ( $change < 0 ) {
			return 'down';
		}

		return $change > 0 ? 'up' : 'unchanged';
	}

	/**
	 * Why a metric has no delta.
	 *
	 * @param Measurement      $before The before reading.
	 * @param Measurement|null $after  The after reading, if there was one.
	 * @return string
	 */
	private function whyUnknown( Measurement $before, ?Measurement $after ): string {
		if ( ! $before->isAvailable() ) {
			return '' === $before->unavailable_because
				? __( 'This was not measured before the change.', 'wp-debloat' )
				: $before->unavailable_because;
		}

		if ( null === $after ) {
			return __( 'This was not measured after the change.', 'wp-debloat' );
		}

		return '' === $after->unavailable_because
			? __( 'This could not be measured after the change.', 'wp-debloat' )
			: $after->unavailable_because;
	}
}
