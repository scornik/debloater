<?php
/**
 * Takes the recovery point before anything is changed.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Snapshot;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;
use Debloater\Apply\RuntimeWriter;
use Debloater\Contracts\Context;
use Debloater\Contracts\DataOperationInterface;
use Debloater\Contracts\Json;
use Debloater\Contracts\Snapshot;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Contracts\SnapshotStatus;
use Debloater\Contracts\Tweak;
use Debloater\Storage\Repositories\SnapshotRepository;
use Debloater\Storage\State;

/**
 * Creates Level A and Level B recovery points (BUILD-SPEC §8, decision #3).
 *
 * The order of operations is the whole design. A snapshot is written, verified,
 * and marked complete **before** the thing it protects against happens. A
 * snapshot taken afterwards protects nothing, and a snapshot that is written but
 * not verified is a promise nobody has checked.
 *
 * **Level A** is the configuration: the previous selection, the runtime hash,
 * and the current value of every option a selected tweak touches. Small, always
 * taken, and enough to put a config change back exactly.
 *
 * **Level B** is the rows a data operation is about to delete, stored verbatim
 * with everything needed to reinsert them — ids, timestamps and all. It is
 * collected by asking the operation itself what it will remove, before it
 * removes anything, so the two cannot disagree about what was there.
 *
 * **Level C** is not here, and that is deliberate. It is the user telling us
 * they have their own external backup, which is a statement we record and never
 * treat as a substitute for Level B.
 *
 * Every snapshot is checksummed over its canonical content, and a restore
 * refuses on a mismatch (§13 rule 7). The checksum is not about corruption in
 * transit so much as about somebody having edited the table by hand: an
 * unverifiable recovery point should fail loudly rather than write unknown data
 * over live rows.
 */
final class SnapshotManager {

	/**
	 * Above this many bytes, Level B items spill to a gzipped file.
	 *
	 * Eight megabytes, from BUILD-SPEC §4. A LONGTEXT column can hold far more,
	 * but a single row that large makes every backup of the options and
	 * snapshot tables heavier, and some hosts cap max_allowed_packet well below
	 * it — at which point the insert fails and the operation is refused, which
	 * is safe but useless. See docs/DECISIONS.md D-0015.
	 */
	public const SPILL_THRESHOLD_BYTES = 8 * 1024 * 1024;

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Snapshot storage.
	 *
	 * @var SnapshotRepository
	 */
	private SnapshotRepository $snapshots;

	/**
	 * Plugin state, for the previous selection.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Constructor.
	 *
	 * @param Context            $context   Site context.
	 * @param SnapshotRepository $snapshots Snapshot storage.
	 * @param State              $state     Plugin state.
	 */
	public function __construct( Context $context, SnapshotRepository $snapshots, State $state ) {
		$this->context   = $context;
		$this->snapshots = $snapshots;
		$this->state     = $state;
	}

	/**
	 * Take the Level A configuration snapshot.
	 *
	 * Records what the site looks like now, so that whatever the apply does can
	 * be undone by putting this back.
	 *
	 * @param int              $run_id The run this belongs to.
	 * @param array<int,Tweak> $tweaks Tweaks about to be applied.
	 * @return Snapshot
	 * @throws RuntimeException When the snapshot cannot be stored.
	 */
	public function captureConfig( int $run_id, array $tweaks ): Snapshot {
		$writer = new RuntimeWriter( $this->context );

		$config = array(
			'selection'    => $this->state->selection(),
			'tweak_states' => $this->serialisedTweakStates(),
			'runtime_hash' => $writer->actualHash(),
			'loader_mode'  => $this->state->loaderMode(),
			'options'      => $this->affectedOptions( $tweaks ),
		);

		$snapshot = new Snapshot(
			null,
			$run_id,
			SnapshotLevel::A,
			gmdate( 'Y-m-d H:i:s' ),
			$this->context->siteHash(),
			$this->context->plugin_version,
			$config,
			0,
			strlen( Json::canonical( $config ) ),
			'db',
			null,
			self::checksumOf( $config ),
			SnapshotStatus::COMPLETE
		);

		return $this->snapshots->insert( $snapshot );
	}

	/**
	 * Take the Level B data snapshot for one operation.
	 *
	 * Asks the operation what it will delete, stores exactly that, verifies what
	 * was stored, and only then marks the snapshot complete. An operation whose
	 * snapshot is not complete may not run (§13 rule 8), so a failure anywhere in
	 * this sequence stops the deletion rather than proceeding without cover.
	 *
	 * @param int                    $run_id    The run this belongs to.
	 * @param Tweak                  $tweak     The data tweak about to run.
	 * @param DataOperationInterface $operation The operation itself.
	 * @return Snapshot
	 * @throws RuntimeException When the rows cannot be stored or verified.
	 */
	public function captureData( int $run_id, Tweak $tweak, DataOperationInterface $operation ): Snapshot {
		$pending = $this->snapshots->insert(
			new Snapshot(
				null,
				$run_id,
				SnapshotLevel::B,
				gmdate( 'Y-m-d H:i:s' ),
				$this->context->siteHash(),
				$this->context->plugin_version,
				null,
				0,
				0,
				'db',
				null,
				str_repeat( '0', 64 ),
				SnapshotStatus::PENDING
			)
		);

		$id = (int) $pending->id;

		try {
			$collected = $this->collectItems( $id, $tweak, $operation );
		} catch ( RuntimeException $error ) {
			$this->snapshots->update( $pending->withStatus( SnapshotStatus::CORRUPT ) );

			throw $error;
		}

		$complete = new Snapshot(
			$id,
			$run_id,
			SnapshotLevel::B,
			$pending->created_at,
			$pending->site_hash,
			$pending->plugin_version,
			array(
				'tweak_id' => $tweak->id,
				'params'   => $tweak->params->toArray(),
			),
			$collected['count'],
			$collected['bytes'],
			$collected['storage'],
			$collected['file_path'],
			$collected['checksum'],
			SnapshotStatus::COMPLETE
		);

		$this->snapshots->update( $complete );

		// Read back what was written. The whole point of this snapshot is that it
		// can be read later; finding out then that it cannot is too late.
		$this->verify( $complete );

		return $complete;
	}

	/**
	 * Collect the rows, choosing storage by how much there turns out to be.
	 *
	 * The choice cannot be made up front: an operation reports how many rows it
	 * will touch, not how large they are. So the items accumulate in memory
	 * until they pass the threshold, at which point everything held so far is
	 * flushed to a gzipped file and the rest streams straight to it. Below the
	 * threshold nothing touches the disk at all.
	 *
	 * @param int                    $snapshot_id Snapshot being filled.
	 * @param Tweak                  $tweak       The data tweak.
	 * @param DataOperationInterface $operation   The operation.
	 * @return array{count:int,bytes:int,storage:string,file_path:string|null,checksum:string}
	 * @throws RuntimeException When the rows cannot be stored.
	 */
	private function collectItems( int $snapshot_id, Tweak $tweak, DataOperationInterface $operation ): array {
		$spill    = new SpillFile( $this->context );
		$buffered = array();
		$digests  = array();
		$handle   = null;
		$count    = 0;
		$bytes    = 0;

		try {
			foreach ( $operation->collect( $this->context, $tweak->params ) as $item ) {
				if ( ! $item instanceof SnapshotItem ) {
					throw new RuntimeException(
						sprintf( 'The operation for "%s" produced something that is not a snapshot item.', $tweak->id )
					);
				}

				++$count;

				$bytes    += strlen( Json::encode( $item->payload ) );
				$digests[] = self::digestOf( $item );

				if ( null === $handle && $bytes > self::SPILL_THRESHOLD_BYTES ) {
					$handle = $spill->open( $snapshot_id );

					foreach ( $buffered as $held ) {
						$spill->append( $handle, $held );
					}

					$buffered = array();
				}

				if ( null === $handle ) {
					$buffered[] = $item;
				} else {
					$spill->append( $handle, $item );
				}
			}
		} catch ( RuntimeException $error ) {
			if ( null !== $handle ) {
				$spill->close( $handle, $snapshot_id );
				$spill->delete( $spill->pathFor( $snapshot_id ) );
			}

			throw $error;
		}

		if ( null !== $handle ) {
			$file_bytes = $spill->close( $handle, $snapshot_id );

			return array(
				'count'     => $count,
				'bytes'     => $file_bytes,
				'storage'   => 'file',
				'file_path' => $spill->pathFor( $snapshot_id ),
				'checksum'  => self::checksumOfDigests( $digests ),
			);
		}

		$stored = $this->snapshots->addItems( $snapshot_id, $buffered );

		if ( $count !== $stored ) {
			throw new RuntimeException(
				sprintf(
					'Only %d of %d rows could be stored, so "%s" will not run.',
					$stored,
					$count,
					$tweak->id
				)
			);
		}

		return array(
			'count'     => $count,
			'bytes'     => $bytes,
			'storage'   => 'db',
			'file_path' => null,
			'checksum'  => self::checksumOfDigests( $digests ),
		);
	}

	/**
	 * Every item in a Level B snapshot, from wherever it was stored.
	 *
	 * The caller should not have to know which side of the threshold a snapshot
	 * fell on; a restore reads items, and this is where they come from.
	 *
	 * @param Snapshot $snapshot Snapshot to read.
	 * @return iterable<int,SnapshotItem>
	 * @throws RuntimeException When the stored rows cannot be read.
	 */
	public function readItems( Snapshot $snapshot ): iterable {
		if ( 'file' === $snapshot->storage ) {
			return ( new SpillFile( $this->context ) )->read(
				null === $snapshot->file_path ? '' : $snapshot->file_path
			);
		}

		$items = array();

		foreach ( $this->snapshots->items( (int) $snapshot->id ) as $entry ) {
			$items[] = $entry['item'];
		}

		return $items;
	}

	/**
	 * Check that a snapshot is what it says it is.
	 *
	 * @param Snapshot $snapshot Snapshot to verify.
	 * @return bool
	 * @throws RuntimeException When the snapshot does not verify.
	 */
	public function verify( Snapshot $snapshot ): bool {
		if ( null === $snapshot->id ) {
			throw new RuntimeException( 'Cannot verify a recovery point that has not been saved.' );
		}

		if ( SnapshotLevel::A === $snapshot->level ) {
			if ( null === $snapshot->config || ! hash_equals( $snapshot->checksum, self::checksumOf( $snapshot->config ) ) ) {
				$this->snapshots->update( $snapshot->withStatus( SnapshotStatus::CORRUPT ) );

				throw new RuntimeException( 'The configuration recovery point does not match its checksum.' );
			}

			return true;
		}

		$digests = array();
		$stored  = 0;

		try {
			foreach ( $this->readItems( $snapshot ) as $item ) {
				++$stored;

				$digests[] = self::digestOf( $item );
			}
		} catch ( RuntimeException $error ) {
			$this->snapshots->update( $snapshot->withStatus( SnapshotStatus::CORRUPT ) );

			throw $error;
		}

		if ( $stored !== $snapshot->items_count ) {
			$this->snapshots->update( $snapshot->withStatus( SnapshotStatus::CORRUPT ) );

			throw new RuntimeException(
				sprintf( 'The recovery point holds %d rows but should hold %d.', $stored, $snapshot->items_count )
			);
		}

		if ( ! hash_equals( $snapshot->checksum, self::checksumOfDigests( $digests ) ) ) {
			$this->snapshots->update( $snapshot->withStatus( SnapshotStatus::CORRUPT ) );

			throw new RuntimeException( 'The stored rows do not match the recovery point checksum.' );
		}

		return true;
	}

	/**
	 * Delete a snapshot, its rows and its spill file.
	 *
	 * Nothing calls this on a timer. A recovery point is the only way back from
	 * a change, and a plugin that quietly deleted them after thirty days would
	 * be deciding on the user's behalf when their site stopped being worth
	 * recovering. They go when the user says so, or when the plugin is
	 * uninstalled with cleanup enabled. See docs/DECISIONS.md D-0016.
	 *
	 * @param Snapshot $snapshot Snapshot to forget.
	 * @return bool Whether the snapshot row was removed.
	 */
	public function forget( Snapshot $snapshot ): bool {
		if ( null === $snapshot->id ) {
			return false;
		}

		if ( 'file' === $snapshot->storage && null !== $snapshot->file_path ) {
			( new SpillFile( $this->context ) )->delete( $snapshot->file_path );
		}

		return $this->snapshots->delete( (int) $snapshot->id );
	}

	/**
	 * Whether a snapshot may be restored onto this site.
	 *
	 * Both conditions from §13 rule 7, checked together so a caller cannot
	 * satisfy one and forget the other.
	 *
	 * @param Snapshot $snapshot Snapshot to check.
	 * @return bool
	 */
	public function isRestorable( Snapshot $snapshot ): bool {
		return $snapshot->isRestorableOn( $this->context->siteHash() );
	}

	/**
	 * The canonical checksum of a Level A configuration.
	 *
	 * @param array<string,mixed> $config Configuration payload.
	 * @return string
	 */
	public static function checksumOf( array $config ): string {
		return hash( 'sha256', Json::canonical( $config ) );
	}

	/**
	 * The canonical checksum of a set of Level B items.
	 *
	 * Order-independent: the items are hashed individually and the digests
	 * sorted, so a restore that reads them back in a different order still
	 * verifies. The database makes no promise about row order without an
	 * ORDER BY, and a checksum that depended on it would fail at random.
	 *
	 * @param array<int,SnapshotItem> $items Snapshot items.
	 * @return string
	 */
	public static function checksumOfItems( array $items ): string {
		$digests = array();

		foreach ( $items as $item ) {
			$digests[] = self::digestOf( $item );
		}

		return self::checksumOfDigests( $digests );
	}

	/**
	 * The digest of one item.
	 *
	 * @param SnapshotItem $item Item to digest.
	 * @return string
	 */
	public static function digestOf( SnapshotItem $item ): string {
		return hash( 'sha256', Json::canonical( $item->toArray() ) );
	}

	/**
	 * The checksum of a set of item digests.
	 *
	 * Sorting the digests is what makes the result order-independent, which
	 * matters because a snapshot may be read back from a table without an
	 * ORDER BY or streamed from a file, and the two need not agree on order.
	 *
	 * @param array<int,string> $digests Item digests.
	 * @return string
	 */
	public static function checksumOfDigests( array $digests ): string {
		sort( $digests, SORT_STRING );

		return hash( 'sha256', implode( '', $digests ) );
	}

	/**
	 * The current value of every option a selected tweak touches.
	 *
	 * In the MVP no config tweak writes an option — they register hooks — so
	 * this is normally empty. It exists because §8 requires Level A to carry
	 * "affected wp_options values", and because a tweak that does write one
	 * should not have to remember to add its own recovery.
	 *
	 * @param array<int,Tweak> $tweaks Tweaks about to be applied.
	 * @return array<string,mixed>
	 */
	private function affectedOptions( array $tweaks ): array {
		$options  = array();
		$sentinel = '__debloater_absent__';

		foreach ( $tweaks as $tweak ) {
			foreach ( $this->optionsTouchedBy( $tweak ) as $option ) {
				if ( array_key_exists( $option, $options ) ) {
					continue;
				}

				$value = get_option( $option, $sentinel );

				// A recorded null would be indistinguishable from "the option did
				// not exist", and restoring the two differs: one is an update, the
				// other a delete.
				$options[ $option ] = $sentinel === $value
					? array( 'exists' => false )
					: array(
						'exists' => true,
						'value'  => $value,
					);
			}
		}

		ksort( $options, SORT_STRING );

		return $options;
	}

	/**
	 * The options a tweak writes, if any.
	 *
	 * @param Tweak $tweak Tweak to inspect.
	 * @return array<int,string>
	 */
	private function optionsTouchedBy( Tweak $tweak ): array {
		/**
		 * Filters the options a tweak's recovery point should capture.
		 *
		 * A config tweak that writes an option declares it here, so the Level A
		 * snapshot covers it without the tweak needing its own recovery code.
		 *
		 * @param array<int,string> $options  Option names.
		 * @param string            $tweak_id The tweak being snapshotted.
		 */
		$options = apply_filters( 'debloater_tweak_options', array(), $tweak->id );

		return is_array( $options ) ? array_values( array_filter( $options, 'is_string' ) ) : array();
	}

	/**
	 * The recorded tweak states, as plain strings.
	 *
	 * @return array<string,string>
	 */
	private function serialisedTweakStates(): array {
		$states = array();

		foreach ( $this->state->tweakStates() as $tweak_id => $tweak_state ) {
			$states[ $tweak_id ] = $tweak_state->value;
		}

		ksort( $states, SORT_STRING );

		return $states;
	}
}
