<?php
/**
 * Puts the site back.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Snapshot;

use RuntimeException;
use Debloater\Apply\Compiler;
use Debloater\Apply\RuntimeLoader;
use Debloater\Apply\RuntimeWriter;
use Debloater\Contracts\Context;
use Debloater\Contracts\DataOperationInterface;
use Debloater\Contracts\Snapshot;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Contracts\SnapshotStatus;
use Debloater\Registry\Registry;
use Debloater\Storage\Repositories\SnapshotRepository;
use Debloater\Storage\State;

/**
 * Restores a recovery point (BUILD-SPEC §17 Phase 5).
 *
 * The bar here is exactness, not approximation. After a Level A restore the
 * runtime file must be byte-identical to what was there before and the options
 * must hold the same values. After a Level B restore the rows must be back with
 * their original ids and timestamps — a restored post revision that is a *new*
 * revision with the same text is not a restore, it is a replacement, and
 * anything that referenced the original id is now wrong.
 *
 * Three refusals, all before anything is written:
 *
 * - **Wrong site.** The site hash must match. A snapshot taken on staging must
 *   never be written over production, and the two are trivially easy to mix up.
 * - **Not complete.** A pending or corrupt snapshot is not a recovery point.
 * - **Unreadable rows.** One item that cannot be decoded makes the whole
 *   snapshot untrustworthy, and half a restore leaves the site in a state
 *   nobody has a name for.
 */
final class RollbackManager {

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
	 * Plugin state.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * The registry, for resolving data operations.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Data operations by tweak id.
	 *
	 * @var array<string,DataOperationInterface>
	 */
	private array $operations;

	/**
	 * Snapshot reading and verification.
	 *
	 * @var SnapshotManager
	 */
	private SnapshotManager $snapshotter;

	/**
	 * Constructor.
	 *
	 * @param Context                              $context     Site context.
	 * @param SnapshotRepository                   $snapshots   Snapshot storage.
	 * @param State                                $state       Plugin state.
	 * @param Registry                             $registry    Registry.
	 * @param SnapshotManager                      $snapshotter Snapshot reading and verification.
	 * @param array<string,DataOperationInterface> $operations  Data operations by tweak id.
	 */
	public function __construct(
		Context $context,
		SnapshotRepository $snapshots,
		State $state,
		Registry $registry,
		SnapshotManager $snapshotter,
		array $operations = array()
	) {
		$this->context     = $context;
		$this->snapshots   = $snapshots;
		$this->state       = $state;
		$this->registry    = $registry;
		$this->snapshotter = $snapshotter;
		$this->operations  = $operations;
	}

	/**
	 * Restore a snapshot.
	 *
	 * @param Snapshot $snapshot Snapshot to restore.
	 * @return RestoreResult
	 * @throws RuntimeException When the snapshot may not be restored.
	 */
	public function restore( Snapshot $snapshot ): RestoreResult {
		$this->assertRestorable( $snapshot );

		$result = SnapshotLevel::A === $snapshot->level
			? $this->restoreConfig( $snapshot )
			: $this->restoreData( $snapshot );

		$this->snapshots->update( $snapshot->withStatus( SnapshotStatus::RESTORED ) );

		return $result;
	}

	/**
	 * Restore every snapshot belonging to a run.
	 *
	 * Data first, then configuration. The rows are what a user would miss, and
	 * putting them back before rewriting the runtime means an interruption
	 * partway through leaves the data recovered and only the configuration
	 * out of step — which is the less alarming of the two halves to be left in.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,RestoreResult>
	 */
	public function restoreRun( int $run_id ): array {
		$snapshots = $this->snapshots->forRun( $run_id );

		usort(
			$snapshots,
			static fn ( Snapshot $a, Snapshot $b ): int => ( SnapshotLevel::B === $a->level ? 0 : 1 )
				<=> ( SnapshotLevel::B === $b->level ? 0 : 1 )
		);

		$results = array();

		foreach ( $snapshots as $snapshot ) {
			if ( SnapshotStatus::RESTORED === $snapshot->status ) {
				// Already put back, by an earlier attempt at this same rollback.
				continue;
			}

			// Anything else that cannot be restored stops the rollback. Skipping
			// it would be worse than failing: the configuration would go back,
			// the run would report "previous configuration restored", and the
			// rows the change deleted would still be gone — a success message
			// over a data loss.
			$refusal = $this->refusalReason( $snapshot );

			if ( null !== $refusal ) {
				throw new RuntimeException(
					sprintf(
						/* translators: 1: snapshot id, 2: why it cannot be restored. */
						__( 'Recovery point %1$d could not be restored, so the rollback stopped: %2$s', 'debloater' ),
						(int) $snapshot->id,
						$refusal
					)
				);
			}

			$results[] = $this->restore( $snapshot );
		}

		return $results;
	}

	/**
	 * Why a snapshot cannot be restored, or null when it can.
	 *
	 * @param Snapshot $snapshot Snapshot to check.
	 * @return string|null
	 */
	public function refusalReason( Snapshot $snapshot ): ?string {
		if ( ! hash_equals( $snapshot->site_hash, $this->context->siteHash() ) ) {
			return __(
				'This recovery point was taken on a different site. Restoring it here would write another site\'s settings over this one.',
				'debloater'
			);
		}

		if ( SnapshotStatus::CORRUPT === $snapshot->status ) {
			return __( 'This recovery point did not verify, so it will not be restored.', 'debloater' );
		}

		if ( ! $snapshot->status->isRestorable() ) {
			return sprintf(
				/* translators: %s: snapshot status. */
				__( 'This recovery point is %s, so there is nothing to restore from it.', 'debloater' ),
				$snapshot->status->value
			);
		}

		return null;
	}

	/**
	 * Restore the configuration.
	 *
	 * @param Snapshot $snapshot Level A snapshot.
	 * @return RestoreResult
	 * @throws RuntimeException When the runtime cannot be rewritten.
	 */
	private function restoreConfig( Snapshot $snapshot ): RestoreResult {
		$config = $snapshot->config ?? array();

		$selection = isset( $config['selection'] ) && is_array( $config['selection'] ) ? $config['selection'] : array();
		$options   = isset( $config['options'] ) && is_array( $config['options'] ) ? $config['options'] : array();

		$restored_options = 0;

		foreach ( $options as $name => $record ) {
			if ( ! is_string( $name ) || ! is_array( $record ) ) {
				continue;
			}

			if ( empty( $record['exists'] ) ) {
				// It did not exist before, so putting it back means removing it.
				delete_option( $name );
			} else {
				update_option( $name, $record['value'] );
			}

			++$restored_options;
		}

		/** @var array<string,array<string,mixed>> $selection */
		$this->state->setSelection( $selection );

		if ( isset( $config['tweak_states'] ) && is_array( $config['tweak_states'] ) ) {
			$this->state->set( array( 'tweak_states' => $config['tweak_states'] ) );
		}

		$hash = $this->rewriteRuntime( $selection );
		$mode = '' === $hash ? RuntimeLoader::MODE_NONE : ( new RuntimeLoader( $this->context ) )->install();

		$this->state->setRuntime( $hash, $mode );

		$expected = is_string( $config['runtime_hash'] ?? null ) ? $config['runtime_hash'] : '';

		if ( $expected !== $hash ) {
			throw new RuntimeException(
				sprintf(
					'The restored runtime does not match the one recorded in the recovery point (%s vs %s).',
					'' === $expected ? 'none' : substr( $expected, 0, 12 ),
					'' === $hash ? 'none' : substr( $hash, 0, 12 )
				)
			);
		}

		return new RestoreResult( $snapshot, $restored_options, 0, $hash );
	}

	/**
	 * Restore the rows a data operation deleted.
	 *
	 * @param Snapshot $snapshot Level B snapshot.
	 * @return RestoreResult
	 * @throws RuntimeException When the operation is unavailable or fails.
	 */
	private function restoreData( Snapshot $snapshot ): RestoreResult {
		$tweak_id = is_array( $snapshot->config ) && is_string( $snapshot->config['tweak_id'] ?? null )
			? $snapshot->config['tweak_id']
			: '';

		$operation = $this->operations[ $tweak_id ] ?? null;

		if ( null === $operation ) {
			throw new RuntimeException(
				sprintf(
					'The operation that removed these rows ("%s") is not available in this version, so they cannot be put back automatically.',
					'' === $tweak_id ? 'unknown' : $tweak_id
				)
			);
		}

		// The rows are read back and checksummed before a single one is written.
		// A recovery point that no longer matches what was taken is not a
		// recovery point, and putting unknown rows into a live database would be
		// a worse outcome than refusing (§13 rule 7).
		$this->snapshotter->verify( $snapshot );

		$items = array();
		$ids   = array();

		if ( 'file' === $snapshot->storage ) {
			foreach ( $this->snapshotter->readItems( $snapshot ) as $item ) {
				$items[] = $item;
			}
		} else {
			foreach ( $this->snapshots->items( (int) $snapshot->id, true ) as $entry ) {
				$items[] = $entry['item'];
				$ids[]   = $entry['id'];
			}
		}

		if ( array() === $items ) {
			return new RestoreResult( $snapshot, 0, 0, '' );
		}

		$restored = $operation->restore( $this->context, $items );

		if ( array() !== $ids ) {
			$this->snapshots->markRestored( $ids );
		}

		return new RestoreResult( $snapshot, 0, $restored, '' );
	}

	/**
	 * Rewrite the runtime from a selection.
	 *
	 * @param array<string,array<string,mixed>> $selection Tweak id to parameters.
	 * @return string The runtime hash, or '' when the selection is empty.
	 */
	private function rewriteRuntime( array $selection ): string {
		$compiler = new Compiler( $this->context );
		$tweaks   = array();

		foreach ( $selection as $tweak_id => $params ) {
			if ( ! $this->registry->has( $tweak_id ) ) {
				continue;
			}

			$tweaks[] = $this->registry->tweak( $tweak_id )->resolve( is_array( $params ) ? $params : array() );
		}

		$source = $compiler->compile( $tweaks, $this->registry->hash() );

		return ( new RuntimeWriter( $this->context ) )->write(
			$source,
			$compiler->selectionHash( $tweaks ),
			$this->registry->hash()
		);
	}

	/**
	 * Refuse a snapshot that must not be restored.
	 *
	 * @param Snapshot $snapshot Snapshot to check.
	 * @return void
	 * @throws RuntimeException When the snapshot may not be restored.
	 */
	private function assertRestorable( Snapshot $snapshot ): void {
		$reason = $this->refusalReason( $snapshot );

		if ( null !== $reason ) {
			throw new RuntimeException( $reason );
		}

		if ( null === $snapshot->id ) {
			throw new RuntimeException( 'Cannot restore a recovery point that has not been saved.' );
		}
	}
}
