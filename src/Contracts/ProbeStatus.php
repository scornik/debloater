<?php
/**
 * Outcome of a single verification probe.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Probe status (BUILD-SPEC §11, locked decision #11).
 *
 * NOT_TESTED is deliberately distinct from UNKNOWN. NOT_TESTED means the probe
 * does not apply to this stack (no WooCommerce, so no checkout probe) and is
 * shown to the user so confidence is never overstated. UNKNOWN means the probe
 * applies but could not run, for example because loopback is blocked, and it
 * counts as a warning in the aggregate.
 */
enum ProbeStatus: string {

	case PASS       = 'PASS';
	case WARN       = 'WARN';
	case FAIL       = 'FAIL';
	case UNKNOWN    = 'UNKNOWN';
	case NOT_TESTED = 'NOT_TESTED';

	/**
	 * Whether this status forces a rollback.
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return self::FAIL === $this;
	}

	/**
	 * Whether this status contributes a warning to the aggregate.
	 *
	 * @return bool
	 */
	public function isWarning(): bool {
		return self::WARN === $this || self::UNKNOWN === $this;
	}

	/**
	 * Whether this status participates in the aggregate at all.
	 *
	 * @return bool
	 */
	public function countsTowardAggregate(): bool {
		return self::NOT_TESTED !== $this;
	}
}
