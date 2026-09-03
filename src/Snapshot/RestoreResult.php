<?php
/**
 * What a restore put back.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Snapshot;

use Debloater\Contracts\Snapshot;

/**
 * The outcome of restoring one recovery point.
 *
 * Counts rather than a boolean, because "the rollback succeeded" is not what a
 * user wants to read after something went wrong on their site. "4 832 rows put
 * back, previous configuration restored" is.
 */
final class RestoreResult {

	/**
	 * The snapshot that was restored.
	 *
	 * @var Snapshot
	 */
	public readonly Snapshot $snapshot;

	/**
	 * How many options were put back.
	 *
	 * @var int
	 */
	public readonly int $options_restored;

	/**
	 * How many rows were put back.
	 *
	 * @var int
	 */
	public readonly int $rows_restored;

	/**
	 * The runtime hash after the restore, or '' when there is no runtime.
	 *
	 * @var string
	 */
	public readonly string $runtime_hash;

	/**
	 * Constructor.
	 *
	 * @param Snapshot $snapshot         The snapshot restored.
	 * @param int      $options_restored Options put back.
	 * @param int      $rows_restored    Rows put back.
	 * @param string   $runtime_hash     Runtime hash afterwards.
	 */
	public function __construct( Snapshot $snapshot, int $options_restored, int $rows_restored, string $runtime_hash ) {
		$this->snapshot         = $snapshot;
		$this->options_restored = $options_restored;
		$this->rows_restored    = $rows_restored;
		$this->runtime_hash     = $runtime_hash;
	}

	/**
	 * Array shape, for the report and the API.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'snapshot_id'      => $this->snapshot->id,
			'level'            => $this->snapshot->level->value,
			'options_restored' => $this->options_restored,
			'rows_restored'    => $this->rows_restored,
			'runtime_hash'     => $this->runtime_hash,
		);
	}
}
