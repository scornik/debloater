<?php
/**
 * Whether a tweak changes configuration or operates on data.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Tweak kind (BUILD-SPEC §7.1).
 *
 * CONFIG tweaks compile into runtime.php as hook registrations and are reverted
 * by regenerating the runtime. DATA tweaks are one-shot DataOperations against
 * the database and are reverted by restoring a Level B snapshot.
 */
enum TweakKind: string {

	case CONFIG = 'config';
	case DATA   = 'data';

	/**
	 * The recovery level required before a tweak of this kind may run.
	 *
	 * Config tweaks need only the Level A configuration snapshot. Data tweaks
	 * always take Level B as well, including non-destructive ones such as
	 * expired-transient cleanup (BUILD-SPEC §15).
	 *
	 * @return SnapshotLevel
	 */
	public function requiredSnapshotLevel(): SnapshotLevel {
		return match ( $this ) {
			self::CONFIG => SnapshotLevel::A,
			self::DATA   => SnapshotLevel::B,
		};
	}
}
