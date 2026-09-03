<?php
/**
 * A recovery point taken before a run changes anything.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

/**
 * Snapshot metadata (BUILD-SPEC §8, locked decision #3).
 *
 * A snapshot is the recovery guarantee that makes an apply safe to attempt. The
 * checksum and site_hash are not bookkeeping: a restore refuses unless both
 * match, so a snapshot taken on another site or corrupted in storage can never
 * be written back over live data (§13 rule 7).
 *
 * Level A carries its config payload inline. Level B carries item metadata here
 * and the rows themselves in debloater_snapshot_items or, above the spill
 * threshold, in a gzipped file whose path is recorded.
 */
final class Snapshot {

	/**
	 * Row id, or null before the snapshot has been persisted.
	 *
	 * @var int|null
	 */
	public readonly ?int $id;

	/**
	 * The run this snapshot belongs to.
	 *
	 * @var int
	 */
	public readonly int $run_id;

	/**
	 * Recovery level.
	 *
	 * @var SnapshotLevel
	 */
	public readonly SnapshotLevel $level;

	/**
	 * Creation timestamp, UTC, "Y-m-d H:i:s".
	 *
	 * @var string
	 */
	public readonly string $created_at;

	/**
	 * sha256 of home_url plus ABSPATH, identifying the site.
	 *
	 * @var string
	 */
	public readonly string $site_hash;

	/**
	 * Plugin version that took the snapshot.
	 *
	 * @var string
	 */
	public readonly string $plugin_version;

	/**
	 * Level A payload: previous selection, runtime hash, affected option values.
	 *
	 * @var array<string,mixed>|null
	 */
	public readonly ?array $config;

	/**
	 * Number of Level B items stored.
	 *
	 * @var int
	 */
	public readonly int $items_count;

	/**
	 * Approximate stored size in bytes.
	 *
	 * @var int
	 */
	public readonly int $bytes;

	/**
	 * Where the items live: "db" or "file".
	 *
	 * @var string
	 */
	public readonly string $storage;

	/**
	 * Path of the spill file, when storage is "file".
	 *
	 * @var string|null
	 */
	public readonly ?string $file_path;

	/**
	 * sha256 over the canonical snapshot contents.
	 *
	 * @var string
	 */
	public readonly string $checksum;

	/**
	 * Lifecycle status.
	 *
	 * @var SnapshotStatus
	 */
	public readonly SnapshotStatus $status;

	/**
	 * Constructor.
	 *
	 * @param int|null                 $id             Row id, null when unsaved.
	 * @param int                      $run_id         Owning run id.
	 * @param SnapshotLevel            $level          Recovery level.
	 * @param string                   $created_at     UTC timestamp.
	 * @param string                   $site_hash      Site identity hash.
	 * @param string                   $plugin_version Plugin version.
	 * @param array<string,mixed>|null $config         Level A payload.
	 * @param int                      $items_count    Level B item count.
	 * @param int                      $bytes          Stored size.
	 * @param string                   $storage        "db" or "file".
	 * @param string|null              $file_path      Spill file path.
	 * @param string                   $checksum       sha256 checksum.
	 * @param SnapshotStatus           $status         Lifecycle status.
	 * @throws ContractViolation When an invariant is violated.
	 */
	public function __construct(
		?int $id,
		int $run_id,
		SnapshotLevel $level,
		string $created_at,
		string $site_hash,
		string $plugin_version,
		?array $config,
		int $items_count,
		int $bytes,
		string $storage,
		?string $file_path,
		string $checksum,
		SnapshotStatus $status
	) {
		if ( null !== $id && $id < 1 ) {
			throw ContractViolation::range( self::class, 'id', 'must be a positive row id or null' );
		}

		if ( $run_id < 1 ) {
			throw ContractViolation::range( self::class, 'run_id', 'must be a positive run id' );
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created_at ) ) {
			throw ContractViolation::range( self::class, 'created_at', 'must be a UTC "Y-m-d H:i:s" timestamp' );
		}

		if ( 1 !== preg_match( Identifier::SHA256_PATTERN, $site_hash ) ) {
			throw ContractViolation::range( self::class, 'site_hash', 'must be a lowercase sha256 hex digest' );
		}

		if ( 1 !== preg_match( Identifier::SHA256_PATTERN, $checksum ) ) {
			throw ContractViolation::range( self::class, 'checksum', 'must be a lowercase sha256 hex digest' );
		}

		if ( '' === trim( $plugin_version ) ) {
			throw ContractViolation::range( self::class, 'plugin_version', 'must not be empty' );
		}

		if ( 'db' !== $storage && 'file' !== $storage ) {
			throw ContractViolation::range( self::class, 'storage', 'must be "db" or "file"' );
		}

		if ( 'file' === $storage && ( null === $file_path || '' === $file_path ) ) {
			throw ContractViolation::range( self::class, 'file_path', 'is required when storage is "file"' );
		}

		if ( 'db' === $storage && null !== $file_path ) {
			throw ContractViolation::range( self::class, 'file_path', 'must be null when storage is "db"' );
		}

		if ( $items_count < 0 ) {
			throw ContractViolation::range( self::class, 'items_count', 'must not be negative' );
		}

		if ( $bytes < 0 ) {
			throw ContractViolation::range( self::class, 'bytes', 'must not be negative' );
		}

		if ( SnapshotLevel::A === $level && null === $config ) {
			throw ContractViolation::range(
				self::class,
				'config',
				'a Level A snapshot must carry the configuration it is restoring to'
			);
		}

		$this->id             = $id;
		$this->run_id         = $run_id;
		$this->level          = $level;
		$this->created_at     = $created_at;
		$this->site_hash      = $site_hash;
		$this->plugin_version = $plugin_version;
		$this->config         = $config;
		$this->items_count    = $items_count;
		$this->bytes          = $bytes;
		$this->storage        = $storage;
		$this->file_path      = $file_path;
		$this->checksum       = $checksum;
		$this->status         = $status;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys(
			self::class,
			$data,
			array(
				'id',
				'run_id',
				'level',
				'created_at',
				'site_hash',
				'plugin_version',
				'config',
				'items_count',
				'bytes',
				'storage',
				'file_path',
				'checksum',
				'status',
			)
		);

		$id = array_key_exists( 'id', $data ) ? $data['id'] : null;

		if ( null !== $id && ! is_int( $id ) ) {
			throw ContractViolation::type( self::class, 'id', 'int or null', $id );
		}

		$config = array_key_exists( 'config', $data ) ? $data['config'] : null;

		if ( null !== $config && ! is_array( $config ) ) {
			throw ContractViolation::type( self::class, 'config', 'array or null', $config );
		}

		return new self(
			$id,
			Assert::int( self::class, $data, 'run_id' ),
			Assert::enum( self::class, $data, 'level', SnapshotLevel::class ),
			Assert::string( self::class, $data, 'created_at' ),
			Assert::string( self::class, $data, 'site_hash' ),
			Assert::string( self::class, $data, 'plugin_version' ),
			$config,
			Assert::intOr( self::class, $data, 'items_count', 0 ),
			Assert::intOr( self::class, $data, 'bytes', 0 ),
			Assert::stringOr( self::class, $data, 'storage', 'db' ),
			Assert::nullableString( self::class, $data, 'file_path' ),
			Assert::string( self::class, $data, 'checksum' ),
			Assert::enum( self::class, $data, 'status', SnapshotStatus::class )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'             => $this->id,
			'run_id'         => $this->run_id,
			'level'          => $this->level->value,
			'created_at'     => $this->created_at,
			'site_hash'      => $this->site_hash,
			'plugin_version' => $this->plugin_version,
			'config'         => $this->config,
			'items_count'    => $this->items_count,
			'bytes'          => $this->bytes,
			'storage'        => $this->storage,
			'file_path'      => $this->file_path,
			'checksum'       => $this->checksum,
			'status'         => $this->status->value,
		);
	}

	/**
	 * Whether this snapshot may be restored onto the given site.
	 *
	 * Both conditions are required by §13 rule 7 and are checked together here
	 * so no caller can accidentally check only one.
	 *
	 * @param string $site_hash Hash of the site being restored onto.
	 * @return bool
	 */
	public function isRestorableOn( string $site_hash ): bool {
		return $this->status->isRestorable() && hash_equals( $this->site_hash, $site_hash );
	}

	/**
	 * A copy with a new status.
	 *
	 * @param SnapshotStatus $status New status.
	 * @return self
	 */
	public function withStatus( SnapshotStatus $status ): self {
		return new self(
			$this->id,
			$this->run_id,
			$this->level,
			$this->created_at,
			$this->site_hash,
			$this->plugin_version,
			$this->config,
			$this->items_count,
			$this->bytes,
			$this->storage,
			$this->file_path,
			$this->checksum,
			$status
		);
	}

	/**
	 * A copy carrying the row id assigned on insert.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function withId( int $id ): self {
		return new self(
			$id,
			$this->run_id,
			$this->level,
			$this->created_at,
			$this->site_hash,
			$this->plugin_version,
			$this->config,
			$this->items_count,
			$this->bytes,
			$this->storage,
			$this->file_path,
			$this->checksum,
			$this->status
		);
	}
}
