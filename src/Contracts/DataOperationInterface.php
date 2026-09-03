<?php
/**
 * Contract for a one-shot database operation.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * A data tweak's operation (BUILD-SPEC §17 Phase 5).
 *
 * The three methods exist in a fixed order for a reason: collect() must run and
 * be persisted as a Level B snapshot **before** execute() touches anything, and
 * restore() must be able to put back exactly what collect() described. An
 * operation that cannot enumerate what it will delete cannot be made safe, so
 * it cannot be implemented here.
 */
interface DataOperationInterface {

	/**
	 * The tweak id this operation implements.
	 *
	 * @return string
	 */
	public function tweakId(): string;

	/**
	 * Whether this operation deletes rows a user would miss.
	 *
	 * Destructive operations are excluded from "Fix Safe Issues" unconditionally
	 * (BUILD-SPEC §7.4) and require a complete Level B snapshot before they may
	 * run (§13 rule 8).
	 *
	 * @return bool
	 */
	public function isDestructive(): bool;

	/**
	 * Count the rows this operation would affect, without changing anything.
	 *
	 * Used by preview and by the analyzer's impact estimate.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int
	 */
	public function countAffected( Context $context, TweakParams $params ): int;

	/**
	 * Yield every row this operation will delete, verbatim.
	 *
	 * Called before execute(). The yielded items become the Level B snapshot.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return iterable<int,SnapshotItem>
	 */
	public function collect( Context $context, TweakParams $params ): iterable;

	/**
	 * Perform the operation.
	 *
	 * @param Context     $context Site context.
	 * @param TweakParams $params  Operation parameters.
	 * @return int Number of rows affected.
	 */
	public function execute( Context $context, TweakParams $params ): int;

	/**
	 * Put back the rows described by the given snapshot items.
	 *
	 * Must restore original identifiers and timestamps, so a round-trip is
	 * indistinguishable from never having run.
	 *
	 * @param Context                $context Site context.
	 * @param array<int,SnapshotItem> $items  Items to restore.
	 * @return int Number of rows restored.
	 */
	public function restore( Context $context, array $items ): int;
}
