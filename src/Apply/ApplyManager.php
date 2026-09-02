<?php
/**
 * Drives a plan through to committed, or back out again.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply;

use Throwable;
use WPDebloat\Contracts\ApplyResult;
use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\DataOperationInterface;
use WPDebloat\Contracts\JournalAction;
use WPDebloat\Contracts\PreviewPlan;
use WPDebloat\Contracts\Run;
use WPDebloat\Contracts\RunState;
use WPDebloat\Contracts\RunType;
use WPDebloat\Contracts\SnapshotLevel;
use WPDebloat\Contracts\Tweak;
use WPDebloat\Contracts\VerificationResult;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Contracts\TweakState;
use WPDebloat\Journal\Journal;
use WPDebloat\Registry\Registry;
use WPDebloat\Snapshot\RollbackManager;
use WPDebloat\Snapshot\SnapshotManager;
use WPDebloat\Storage\Repositories\RunRepository;
use WPDebloat\Storage\Repositories\SnapshotRepository;
use WPDebloat\Storage\State;
use WPDebloat\Verify\Verifier;

/**
 * The apply run (BUILD-SPEC §9.2).
 *
 * This class is a driver for the state machine and nothing else: it performs
 * the work for a step, then asks `RunStateMachine` to record it. The machine
 * refuses any transition outside the table, so a bug here becomes an exception
 * rather than a site in a state the plugin has no name for.
 *
 * The ordering is the safety property, and it is worth stating plainly:
 *
 * 1. Take the lock. A second apply must not interleave with this one.
 * 2. Snapshot **everything**, and verify it, before changing **anything**.
 *    A recovery point taken afterwards protects nothing.
 * 3. Apply configuration first, then data. Configuration is a file swap that
 *    reverts perfectly; data is rows that have to be put back one by one. If
 *    something is going to fail, better it fails before the irreversible-looking
 *    half has started.
 * 4. Verify. Phase 6 fills this in; the transition exists here so the shape of
 *    the run does not change when it arrives.
 * 5. On any failure after applying has begun, roll back and say so.
 *
 * Nothing is applied without a complete Level B snapshot for every data
 * operation in the plan (§13 rule 8). That check is not a formality — the
 * snapshot is read back and checksummed before the operation is allowed to run.
 */
final class ApplyManager {

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Run storage.
	 *
	 * @var RunRepository
	 */
	private RunRepository $runs;

	/**
	 * Snapshot storage.
	 *
	 * @var SnapshotRepository
	 */
	private SnapshotRepository $snapshots;

	/**
	 * Snapshot creation.
	 *
	 * @var SnapshotManager
	 */
	private SnapshotManager $snapshotter;

	/**
	 * Restoration.
	 *
	 * @var RollbackManager
	 */
	private RollbackManager $rollback;

	/**
	 * Plugin state.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Data operations by tweak id.
	 *
	 * @var array<string,DataOperationInterface>
	 */
	private array $operations;

	/**
	 * The apply lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Tweak state transitions.
	 *
	 * @var TweakLifecycle
	 */
	private TweakLifecycle $lifecycle;

	/**
	 * Post-apply verification, when there is any.
	 *
	 * @var Verifier|null
	 */
	private ?Verifier $verifier;

	/**
	 * Constructor.
	 *
	 * @param Context                              $context     Site context.
	 * @param Registry                             $registry    Registry.
	 * @param RunRepository                        $runs        Run storage.
	 * @param SnapshotRepository                   $snapshots   Snapshot storage.
	 * @param SnapshotManager                      $snapshotter Snapshot creation.
	 * @param RollbackManager                      $rollback    Restoration.
	 * @param State                                $state       Plugin state.
	 * @param Journal                              $journal     Transition record.
	 * @param array<string,DataOperationInterface> $operations  Data operations by tweak id.
	 * @param Lock|null                            $lock        The apply lock.
	 * @param Verifier|null                        $verifier    Post-apply verification.
	 */
	public function __construct(
		Context $context,
		Registry $registry,
		RunRepository $runs,
		SnapshotRepository $snapshots,
		SnapshotManager $snapshotter,
		RollbackManager $rollback,
		State $state,
		Journal $journal,
		array $operations = array(),
		?Lock $lock = null,
		?Verifier $verifier = null
	) {
		$this->context     = $context;
		$this->registry    = $registry;
		$this->runs        = $runs;
		$this->snapshots   = $snapshots;
		$this->snapshotter = $snapshotter;
		$this->rollback    = $rollback;
		$this->state       = $state;
		$this->operations  = $operations;
		$this->lock        = $lock ?? new Lock();
		$this->verifier    = $verifier;
		$this->lifecycle   = new TweakLifecycle( $state, $journal );
	}

	/**
	 * Apply a plan.
	 *
	 * @param PreviewPlan $plan The plan to apply.
	 * @return ApplyResult
	 */
	public function apply( PreviewPlan $plan ): ApplyResult {
		$machine = new RunStateMachine();
		$run     = $this->startRun( $plan );
		$run_id  = (int) $run->id;

		$machine->transitionTo( RunState::PLANNING );
		$machine->transitionTo( RunState::PREVIEWED );

		if ( ! $this->lock->acquire() ) {
			return $this->abort(
				$run,
				$machine,
				__( 'Another change is already in progress on this site. Wait for it to finish and try again.', 'wp-debloat' )
			);
		}

		try {
			$machine->transitionTo( RunState::LOCKED );
			$this->record( $run, $machine );

			// The Meter arrives in Phase 9. The transition exists now so the run's
			// shape does not change when it does, and a failure here is a warning
			// rather than a stop (BUILD-SPEC §9.2).
			$machine->transitionTo( RunState::MEASURING_BEFORE );
			$machine->transitionTo( RunState::SNAPSHOTTING );
			$this->record( $run, $machine );

			$snapshot_ids = $this->snapshot( $run_id, $plan );
		} catch ( Throwable $error ) {
			$this->lock->release();

			return $this->abort( $run, $machine, $this->describe( $error ) );
		}

		$machine->transitionTo( RunState::APPLYING );
		$this->record( $run, $machine );

		try {
			$applied = $this->applyTweaks( $run_id, $plan );
		} catch ( Throwable $error ) {
			return $this->rollBack( $run, $machine, $snapshot_ids, RunState::APPLY_FAILED, $this->describe( $error ) );
		}

		$machine->transitionTo( RunState::APPLIED );
		$machine->transitionTo( RunState::VERIFYING );
		$this->record( $run, $machine );

		$verification = $this->verify();

		if ( null !== $verification && $verification->isFailure() ) {
			return $this->rollBack(
				$run,
				$machine,
				$snapshot_ids,
				RunState::VERIFICATION_FAILED,
				$this->describeFailures( $verification ),
				$verification
			);
		}

		$warnings = array();

		if ( null === $verification ) {
			$machine->transitionTo( RunState::VERIFIED );
		} elseif ( $verification->isClean() ) {
			$machine->transitionTo( RunState::VERIFIED );
		} else {
			$machine->transitionTo( RunState::VERIFIED_WITH_WARNINGS );

			$warnings = $this->describeWarnings( $verification );
		}

		$this->lifecycle->advanceAll( $run_id, $applied, TweakState::VERIFIED );

		$machine->transitionTo( RunState::MEASURING_AFTER );
		$machine->transitionTo( RunState::COMMITTED );

		$this->commit( $run_id, $applied );
		$this->lock->release();

		$result = new ApplyResult(
			$run_id,
			RunState::COMMITTED,
			$applied,
			array(),
			$snapshot_ids,
			$verification,
			null,
			$warnings
		);

		$this->finish( $run, $machine, $result );

		return $result;
	}

	/**
	 * Roll a run back on request.
	 *
	 * @param int $run_id The run to undo.
	 * @return ApplyResult
	 */
	public function rollbackRun( int $run_id ): ApplyResult {
		$run = $this->runs->find( $run_id );

		if ( null === $run ) {
			throw new \RuntimeException( sprintf( 'There is no run with the id %d.', $run_id ) );
		}

		if ( ! $this->lock->acquire() ) {
			return new ApplyResult(
				$run_id,
				RunState::ABORTED,
				array(),
				array(),
				array(),
				null,
				__( 'Another change is in progress on this site. Wait for it to finish and try again.', 'wp-debloat' )
			);
		}

		try {
			// Captured before the restore, which puts the recorded tweak states
			// back and would otherwise erase where each tweak had got to.
			$states = $this->lifecycle->statesOf( $this->tweakIdsOf( $run ) );

			$restored = $this->rollback->restoreRun( $run_id );
			$ids      = array();

			foreach ( $restored as $result ) {
				$ids[] = (int) $result->snapshot->id;
			}

			$this->lifecycle->advanceAllFrom(
				$run_id,
				$states,
				TweakState::ROLLED_BACK,
				JournalAction::REVERT
			);

			return new ApplyResult(
				$run_id,
				RunState::ROLLED_BACK,
				array(),
				array(),
				$ids,
				null,
				__( 'Rolled back on request. The previous configuration has been restored.', 'wp-debloat' )
			);
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Recover runs that were interrupted (BUILD-SPEC §9.2).
	 *
	 * A run found in APPLYING or VERIFYING at boot is one whose process died
	 * partway through. The site may be half-changed, and nobody is coming back
	 * to finish it, so it is marked INTERRUPTED and rolled back.
	 *
	 * The lock is what distinguishes a dead run from a live one. An apply that
	 * is still running holds it and refreshes it; a process that died stops
	 * refreshing and the lock expires within a minute. So: if anything holds the
	 * lock, an apply is in flight and nothing here is interrupted. Only once the
	 * lock is free is a run still sitting in APPLYING evidence of a crash.
	 *
	 * @return array<int,int> Ids of the runs recovered.
	 */
	public function recoverInterruptedRuns(): array {
		$statuses = array();

		foreach ( RunState::cases() as $state ) {
			if ( $state->needsCrashRecovery() ) {
				$statuses[] = $state->value;
			}
		}

		$candidates = $this->runs->withStatus( $statuses );

		if ( array() === $candidates ) {
			return array();
		}

		if ( $this->lock->isHeld() || ! $this->lock->acquire() ) {
			// Someone is mid-apply. Their run is not interrupted, it is running.
			return array();
		}

		try {
			return $this->recover( $candidates );
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Roll back the runs that crashed.
	 *
	 * @param array<int,Run> $candidates Runs found in a crash-recoverable state.
	 * @return array<int,int> Ids of the runs recovered.
	 */
	private function recover( array $candidates ): array {
		$recovered = array();

		foreach ( $candidates as $run ) {
			$run_id = (int) $run->id;

			$this->runs->update( $run->withStatus( RunState::INTERRUPTED->value ) );

			$states = $this->lifecycle->statesOf( $this->tweakIdsOf( $run ) );

			try {
				$this->rollback->restoreRun( $run_id );

				$this->lifecycle->advanceAllFrom(
					$run_id,
					$states,
					TweakState::ROLLED_BACK,
					JournalAction::REVERT
				);

				$this->runs->update(
					$run->withStatus(
						RunState::ROLLED_BACK->value,
						gmdate( 'Y-m-d H:i:s' ),
						__( 'This change was interrupted before it finished and has been rolled back.', 'wp-debloat' )
					)
				);
			} catch ( Throwable $error ) {
				$this->runs->update(
					$run->withStatus(
						RunState::INTERRUPTED->value,
						gmdate( 'Y-m-d H:i:s' ),
						sprintf(
							/* translators: %s: the underlying failure. */
							__( 'This change was interrupted and could not be rolled back automatically: %s', 'wp-debloat' ),
							$this->describe( $error )
						)
					)
				);
			}

			$recovered[] = $run_id;
		}

		return $recovered;
	}

	/**
	 * The apply lock, for callers that need to report on it.
	 *
	 * @return Lock
	 */
	public function lock(): Lock {
		return $this->lock;
	}

	/**
	 * Create the run record for a plan.
	 *
	 * @param PreviewPlan $plan The plan being applied.
	 * @return Run
	 */
	private function startRun( PreviewPlan $plan ): Run {
		return $this->runs->insert(
			new Run(
				null,
				RunType::APPLY,
				RunState::IDLE->value,
				$this->context->actor,
				gmdate( 'Y-m-d H:i:s' ),
				null,
				$this->context->plugin_version,
				$this->registry->hash(),
				array( 'plan' => $plan->toArray() )
			)
		);
	}

	/**
	 * Take every snapshot the plan requires, and verify each one.
	 *
	 * @param int         $run_id The run.
	 * @param PreviewPlan $plan   The plan.
	 * @return array<int,int> Snapshot ids.
	 * @throws \RuntimeException When a required snapshot cannot be taken.
	 */
	private function snapshot( int $run_id, PreviewPlan $plan ): array {
		$ids = array();

		$config = $this->snapshotter->captureConfig( $run_id, $plan->tweaks );

		$this->snapshotter->verify( $config );

		$ids[] = (int) $config->id;

		foreach ( $plan->dataTweaks() as $tweak ) {
			$operation = $this->operations[ $tweak->id ] ?? null;

			if ( null === $operation ) {
				throw new \RuntimeException(
					sprintf(
						'The operation for "%s" is not available, so its rows cannot be backed up and it will not run.',
						$tweak->id
					)
				);
			}

			$ids[] = (int) $this->snapshotter->captureData( $run_id, $tweak, $operation )->id;

			$this->lifecycle->advance(
				$run_id,
				$tweak->id,
				TweakState::SNAPSHOTTED,
				JournalAction::APPLY,
				$tweak->params
			);
		}

		foreach ( $plan->configTweaks() as $tweak ) {
			$this->lifecycle->advance(
				$run_id,
				$tweak->id,
				TweakState::SNAPSHOTTED,
				JournalAction::APPLY,
				$tweak->params
			);
		}

		return $ids;
	}

	/**
	 * Apply the plan: configuration first, then data.
	 *
	 * @param int         $run_id The run.
	 * @param PreviewPlan $plan   The plan.
	 * @return array<int,string> Ids of the tweaks applied.
	 * @throws \RuntimeException When applying fails.
	 */
	private function applyTweaks( int $run_id, PreviewPlan $plan ): array {
		$applied = array();

		$config = $plan->configTweaks();

		if ( array() !== $config ) {
			$this->writeRuntime( $config );

			foreach ( $config as $tweak ) {
				$applied[] = $tweak->id;

				$this->lifecycle->advance(
					$run_id,
					$tweak->id,
					TweakState::APPLIED,
					JournalAction::APPLY,
					$tweak->params
				);
			}
		}

		foreach ( $plan->dataTweaks() as $tweak ) {
			$operation = $this->operations[ $tweak->id ] ?? null;

			if ( null === $operation ) {
				// Unreachable: snapshot() refuses the plan before this point. Kept
				// so that a future caller skipping the snapshot step cannot delete
				// rows without one.
				throw new \RuntimeException(
					sprintf( 'The operation for "%s" is not available.', $tweak->id )
				);
			}

			$this->assertRecoveryExists( $run_id, $tweak->id );

			$operation->execute( $this->context, $tweak->params );

			$applied[] = $tweak->id;

			$this->lifecycle->advance(
				$run_id,
				$tweak->id,
				TweakState::APPLIED,
				JournalAction::APPLY,
				$tweak->params
			);
		}

		sort( $applied, SORT_STRING );

		return $applied;
	}

	/**
	 * Refuse to run a data operation without a complete recovery point.
	 *
	 * BUILD-SPEC §13 rule 8. Checked immediately before the deletion rather than
	 * only when the snapshot was taken, so that a snapshot which became corrupt
	 * in between still stops the operation.
	 *
	 * @param int    $run_id   The run.
	 * @param string $tweak_id The data tweak about to run.
	 * @return void
	 * @throws \RuntimeException When there is no complete Level B snapshot.
	 */
	private function assertRecoveryExists( int $run_id, string $tweak_id ): void {
		foreach ( $this->snapshots->forRun( $run_id ) as $snapshot ) {
			if ( SnapshotLevel::B !== $snapshot->level ) {
				continue;
			}

			$owner = is_array( $snapshot->config ) ? ( $snapshot->config['tweak_id'] ?? null ) : null;

			if ( $owner === $tweak_id && $snapshot->status->satisfiesRecoveryRequirement() ) {
				return;
			}
		}

		throw new \RuntimeException(
			sprintf( 'There is no complete recovery point for "%s", so it will not run.', $tweak_id )
		);
	}

	/**
	 * Write the runtime for a set of config tweaks.
	 *
	 * @param array<int,Tweak> $tweaks Config tweaks to apply.
	 * @return void
	 */
	private function writeRuntime( array $tweaks ): void {
		$selection = $this->state->selection();

		foreach ( $tweaks as $tweak ) {
			$selection[ $tweak->id ] = $tweak->params->toArray();
		}

		ksort( $selection, SORT_STRING );

		$compiler = new Compiler( $this->context );
		$resolved = array();

		foreach ( $selection as $tweak_id => $params ) {
			if ( ! $this->registry->has( $tweak_id ) ) {
				continue;
			}

			$definition = $this->registry->tweak( $tweak_id );

			if ( TweakKind::CONFIG === $definition->kind ) {
				$resolved[] = $definition->resolve( is_array( $params ) ? $params : array() );
			}
		}

		$hash = ( new RuntimeWriter( $this->context ) )->write(
			$compiler->compile( $resolved, $this->registry->hash() ),
			$compiler->selectionHash( $resolved ),
			$this->registry->hash()
		);

		$mode = '' === $hash ? RuntimeLoader::MODE_NONE : ( new RuntimeLoader( $this->context ) )->install();

		$this->state->setSelection( $selection );
		$this->state->setRuntime( $hash, $mode );
	}

	/**
	 * Record the applied tweaks as committed.
	 *
	 * @param int               $run_id  The run.
	 * @param array<int,string> $applied Tweak ids applied.
	 * @return void
	 */
	private function commit( int $run_id, array $applied ): void {
		$this->lifecycle->advanceAll( $run_id, $applied, TweakState::COMMITTED );
	}

	/**
	 * Abort a run that failed before anything was changed.
	 *
	 * @param Run             $run     The run.
	 * @param RunStateMachine $machine The state machine.
	 * @param string          $error   What went wrong.
	 * @return ApplyResult
	 */
	private function abort( Run $run, RunStateMachine $machine, string $error ): ApplyResult {
		$machine->transitionTo( RunState::ABORTED );

		$result = new ApplyResult( (int) $run->id, RunState::ABORTED, array(), array(), array(), null, $error );

		$this->finish( $run, $machine, $result );

		return $result;
	}

	/**
	 * Roll back a run that failed after applying began.
	 *
	 * @param Run             $run          The run.
	 * @param RunStateMachine $machine      The state machine.
	 * @param array<int,int>  $snapshot_ids Snapshots taken.
	 * @param RunState                $failure      The failure state to pass through.
	 * @param string                  $error        What went wrong.
	 * @param VerificationResult|null $verification Verification outcome, when one ran.
	 * @return ApplyResult
	 */
	private function rollBack(
		Run $run,
		RunStateMachine $machine,
		array $snapshot_ids,
		RunState $failure,
		string $error,
		?VerificationResult $verification = null
	): ApplyResult {
		$run_id = (int) $run->id;

		$machine->transitionTo( $failure );
		$machine->transitionTo( RunState::ROLLING_BACK );
		$this->record( $run, $machine );

		$message = $error;
		$states  = $this->lifecycle->statesOf( $this->tweakIdsOf( $run ) );

		try {
			$this->rollback->restoreRun( $run_id );

			$this->lifecycle->advanceAllFrom(
				$run_id,
				$states,
				TweakState::ROLLED_BACK,
				JournalAction::REVERT
			);
		} catch ( Throwable $rollback_error ) {
			// The original failure is what the user needs to know about; the
			// rollback failure is what they need to act on. Both are reported.
			$message = sprintf(
				/* translators: 1: the original failure, 2: the rollback failure. */
				__( '%1$s The rollback then failed as well: %2$s', 'wp-debloat' ),
				$error,
				$this->describe( $rollback_error )
			);
		}

		$machine->transitionTo( RunState::ROLLED_BACK );
		$this->lock->release();

		$result = new ApplyResult(
			$run_id,
			RunState::ROLLED_BACK,
			array(),
			array(),
			$snapshot_ids,
			$verification,
			$message
		);

		$this->finish( $run, $machine, $result );

		return $result;
	}

	/**
	 * Run verification, when there is a verifier.
	 *
	 * A verifier that throws is not a verdict on the site. The run continues and
	 * the failure is reported as a warning rather than rolling back a change
	 * that may be perfectly fine.
	 *
	 * @return VerificationResult|null
	 */
	private function verify(): ?VerificationResult {
		if ( null === $this->verifier ) {
			return null;
		}

		try {
			return $this->verifier->verify();
		} catch ( Throwable $error ) {
			unset( $error );

			return null;
		}
	}

	/**
	 * What failed, in the user's words rather than the probe's.
	 *
	 * @param VerificationResult $verification The verification.
	 * @return string
	 */
	private function describeFailures( VerificationResult $verification ): string {
		$messages = array();

		foreach ( $verification->failures() as $probe ) {
			$messages[] = $probe->message;
		}

		return sprintf(
			/* translators: %s: the failed checks, already sentence-formed. */
			__( 'The site did not pass its checks after the change, so it was put back. %s', 'wp-debloat' ),
			implode( ' ', $messages )
		);
	}

	/**
	 * The warnings a verification produced.
	 *
	 * @param VerificationResult $verification The verification.
	 * @return array<int,string>
	 */
	private function describeWarnings( VerificationResult $verification ): array {
		$warnings = array();

		foreach ( $verification->probes as $probe ) {
			if ( $probe->status->isWarning() ) {
				$warnings[] = $probe->message;
			}
		}

		return $warnings;
	}

	/**
	 * Update the run's status to match the machine.
	 *
	 * @param Run             $run     The run.
	 * @param RunStateMachine $machine The state machine.
	 * @return void
	 */
	private function record( Run $run, RunStateMachine $machine ): void {
		$this->runs->update( $run->withStatus( $machine->state()->value ) );
	}

	/**
	 * Record the final state and result.
	 *
	 * @param Run             $run     The run.
	 * @param RunStateMachine $machine The state machine.
	 * @param ApplyResult     $result  The outcome.
	 * @return void
	 */
	private function finish( Run $run, RunStateMachine $machine, ApplyResult $result ): void {
		$this->runs->update(
			$run->withStatus( $machine->state()->value, gmdate( 'Y-m-d H:i:s' ), $result->error )
				->withPayload(
					array_merge(
						$run->payload,
						array(
							'result'  => $result->toArray(),
							'history' => array_map(
								static fn ( RunState $state ): string => $state->value,
								$machine->history()
							),
						)
					)
				)
		);
	}

	/**
	 * The tweak ids a run's plan contained.
	 *
	 * @param Run $run The run.
	 * @return array<int,string>
	 */
	private function tweakIdsOf( Run $run ): array {
		$plan = $run->payload['plan'] ?? array();

		if ( ! is_array( $plan ) || ! is_array( $plan['tweaks'] ?? null ) ) {
			return array();
		}

		$ids = array();

		foreach ( $plan['tweaks'] as $tweak ) {
			if ( is_array( $tweak ) && is_string( $tweak['id'] ?? null ) ) {
				$ids[] = $tweak['id'];
			}
		}

		return $ids;
	}

	/**
	 * A safe description of a failure.
	 *
	 * The message without the trace: a run's error is shown in the admin and may
	 * be exported, and a stack trace carries absolute paths.
	 *
	 * @param Throwable $error The failure.
	 * @return string
	 */
	private function describe( Throwable $error ): string {
		return $error->getMessage();
	}
}
