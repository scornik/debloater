<?php
/**
 * Recovery level of a snapshot.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Snapshot level (BUILD-SPEC §8, locked decision #3).
 *
 * Only A and B are Debloater snapshots and therefore only they appear in the
 * debloater_snapshots table.
 *
 * - A: configuration. Previous selection, runtime hash, and the previous values
 *   of every wp_options key a selected tweak touches.
 * - B: data-operation backup. The exact rows a DataOperation will delete, stored
 *   verbatim and checksummed so they can be reinserted.
 *
 * Level C is an **external backup attestation** made by the user. It is not a
 * snapshot, is not stored here, and never substitutes for a required Level B.
 */
enum SnapshotLevel: string {

	case A = 'A';
	case B = 'B';
}
