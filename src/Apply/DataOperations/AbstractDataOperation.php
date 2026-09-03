<?php
/**
 * What every data operation does the same way.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply\DataOperations;

use Debloater\Contracts\Context;
use Debloater\Contracts\DataOperationInterface;
use Debloater\Contracts\TweakParams;

/**
 * Shared machinery for the operations that delete rows (BUILD-SPEC §17 Phase 10).
 *
 * The order of the three methods is the safety property, and it is worth
 * restating here because every subclass depends on it: `collect()` runs first
 * and its output becomes the Level B recovery point; `execute()` only runs once
 * that recovery point has been stored and verified; `restore()` must be able to
 * put back exactly what `collect()` described — the same ids, the same dates,
 * the same metadata.
 *
 * "Exactly" is not a figure of speech. A restored post that is a *new* post with
 * the same text is not a restore, it is a replacement, and every menu item,
 * relationship and permalink that referenced the original id is now wrong. So
 * rows go back through `$wpdb` with their original primary keys rather than
 * through `wp_insert_post()`, which allocates a new one. Deletion goes the other
 * way and uses WordPress's own functions, so that the hooks other plugins rely
 * on to keep their own tables in step actually fire.
 */
abstract class AbstractDataOperation implements DataOperationInterface {

	/**
	 * Objects handled per batch when the tweak does not say otherwise.
	 */
	public const DEFAULT_BATCH_SIZE = 200;

	/**
	 * Largest batch anyone may ask for.
	 *
	 * A batch is a unit of work between progress updates and a unit of memory
	 * while collecting. Neither is served by making it enormous.
	 */
	protected const MAX_BATCH_SIZE = 1000;

	/**
	 * The highest primary key collect() saw, per set.
	 *
	 * Nothing above this is ever deleted. See ceilingFor().
	 *
	 * @var array<string,int>
	 */
	private array $ceilings = array();

	/**
	 * Record how far collect() got, so execute() cannot go further.
	 *
	 * @param string $set Name of the set being collected.
	 * @param int    $id  Primary key just collected.
	 * @return void
	 */
	protected function raiseCeiling( string $set, int $id ): void {
		$this->ceilings[ $set ] = max( $this->ceilings[ $set ] ?? 0, $id );
	}

	/**
	 * The highest primary key this operation may delete from a set.
	 *
	 * This is the fix for a real hole. `collect()` writes the recovery point and
	 * `execute()` then asks the database again for what matches — so a row that
	 * came to match *in between* would be deleted without ever having been
	 * backed up. On a busy site that is not hypothetical: a post is trashed, a
	 * comment is marked as spam, a plugin writes metadata before creating the
	 * object it belongs to.
	 *
	 * So execute() is bounded by the highest id collect() saw. Rows that arrived
	 * afterwards are left for the next run, when they will be collected first
	 * like everything else.
	 *
	 * Zero means collect() found nothing, and nothing is what execute() may
	 * delete. That is the safe direction: an operation that never collected must
	 * never delete, because §13 rule 8 is about *this* run's recovery point, not
	 * about one that exists in principle.
	 *
	 * @param string $set Name of the set.
	 * @return int
	 */
	protected function ceilingFor( string $set ): int {
		return $this->ceilings[ $set ] ?? 0;
	}

	/**
	 * Whether this operation deletes rows a user would miss.
	 *
	 * Every operation in this phase does. `ExpiredTransientsCleanup` is the
	 * exception and says so itself.
	 *
	 * @return bool
	 */
	public function isDestructive(): bool {
		return true;
	}

	/**
	 * The batch size to use.
	 *
	 * @param TweakParams $params Operation parameters.
	 * @return int
	 */
	protected function batchSize( TweakParams $params ): int {
		$size = $params->get( 'batch_size', self::DEFAULT_BATCH_SIZE );

		if ( ! is_numeric( $size ) ) {
			return self::DEFAULT_BATCH_SIZE;
		}

		return max( 1, min( self::MAX_BATCH_SIZE, (int) $size ) );
	}

	/**
	 * An integer parameter, bounded.
	 *
	 * @param TweakParams $params   Operation parameters.
	 * @param string      $name     Parameter name.
	 * @param int         $fallback Value when absent or unusable.
	 * @param int         $minimum  Lowest permitted value.
	 * @param int         $maximum  Highest permitted value.
	 * @return int
	 */
	protected function intParam(
		TweakParams $params,
		string $name,
		int $fallback,
		int $minimum,
		int $maximum
	): int {
		$value = $params->get( $name, $fallback );

		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $minimum, min( $maximum, (int) $value ) );
	}

	/**
	 * The cut-off timestamp for an "older than N days" parameter, in site time.
	 *
	 * @param TweakParams $params   Operation parameters.
	 * @param int         $fallback Number of days when the tweak does not say.
	 * @return string MySQL datetime.
	 */
	protected function olderThan( TweakParams $params, int $fallback ): string {
		$days = $this->intParam( $params, 'older_than_days', $fallback, 1, 3650 );

		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Whether this operation may run on this site at all.
	 *
	 * Multisite is refused across the board in v1: several of these tables are
	 * shared across a network, and "no row in this site's tables" is a different
	 * question there (docs/DECISIONS.md D-0026).
	 *
	 * @param Context $context Site context.
	 * @return bool
	 */
	protected function isSupported( Context $context ): bool {
		return ! $context->is_multisite;
	}

	/**
	 * Forget everything WordPress has cached about the rows just restored.
	 *
	 * A restore writes rows underneath WordPress rather than through it, so
	 * anything it had cached about them — including "this post does not exist" —
	 * is now wrong.
	 *
	 * @return void
	 */
	protected function forgetCaches(): void {
		wp_cache_flush();
	}
}
