<?php
/**
 * Status of a stored snapshot.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Snapshot status (BUILD-SPEC §8).
 *
 * Only COMPLETE snapshots may be restored, and only after the checksum and
 * site_hash have been verified (BUILD-SPEC §13 rule 7). A destructive data
 * operation may not execute unless its Level B snapshot is COMPLETE
 * (§13 rule 8).
 */
enum SnapshotStatus: string {

	case PENDING  = 'pending';
	case COMPLETE = 'complete';
	case RESTORED = 'restored';
	case EXPIRED  = 'expired';
	case CORRUPT  = 'corrupt';

	/**
	 * Whether a snapshot in this status may be restored.
	 *
	 * @return bool
	 */
	public function isRestorable(): bool {
		return self::COMPLETE === $this;
	}

	/**
	 * Whether a snapshot in this status satisfies the recovery requirement for
	 * a destructive operation.
	 *
	 * @return bool
	 */
	public function satisfiesRecoveryRequirement(): bool {
		return self::COMPLETE === $this;
	}
}
