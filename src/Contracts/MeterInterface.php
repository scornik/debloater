<?php
/**
 * Contract for a single measured metric.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * A meter metric (BUILD-SPEC §12, locked decisions #1 and #2).
 *
 * The Meter is a separate pipeline from Scanner-Analyzer-Engine and is never
 * used to compute the Debloat Score. It exists to prove deltas: measure before,
 * measure after, report the difference in a countable unit.
 *
 * Metrics are counts and sizes — requests, scripts, bytes, rows, events. Time is
 * deliberately absent: a plugin cannot honestly attribute page-load time to its
 * own changes on someone else's host, so Debloater never claims it.
 */
interface MeterInterface {

	/**
	 * The metric name, e.g. "frontend.requests".
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * The unit of the measured value, e.g. "requests", "bytes", "rows".
	 *
	 * @return string
	 */
	public function unit(): string;

	/**
	 * Whether this metric can be measured on this site right now.
	 *
	 * @param Context $context Site context.
	 * @return bool
	 */
	public function isAvailable( Context $context ): bool;

	/**
	 * Measure the metric.
	 *
	 * @param Context     $context Site context.
	 * @param string|null $target  URL or other context the metric applies to.
	 * @return float
	 */
	public function measure( Context $context, ?string $target = null ): float;
}
