<?php
/**
 * The outcome of an apply run.
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
 * Apply result (BUILD-SPEC §9.2).
 *
 * Records where the run finished, what it did to each tweak, and what
 * verification concluded. The final state is the authority on what happened;
 * the lists exist so the report can say which tweak was applied, which was
 * skipped and why, without re-deriving it from the journal.
 */
final class ApplyResult {

	/**
	 * The run this result describes.
	 *
	 * @var int
	 */
	public readonly int $run_id;

	/**
	 * Final run state.
	 *
	 * @var RunState
	 */
	public readonly RunState $state;

	/**
	 * Tweak ids that were applied.
	 *
	 * @var array<int,string>
	 */
	public readonly array $applied;

	/**
	 * Tweak ids that were skipped, mapped to the reason.
	 *
	 * @var array<string,string>
	 */
	public readonly array $skipped;

	/**
	 * Snapshot ids created for this run.
	 *
	 * @var array<int,int>
	 */
	public readonly array $snapshot_ids;

	/**
	 * Verification outcome, when verification ran.
	 *
	 * @var VerificationResult|null
	 */
	public readonly ?VerificationResult $verification;

	/**
	 * Failure message, when the run did not succeed.
	 *
	 * @var string|null
	 */
	public readonly ?string $error;

	/**
	 * Non-fatal warnings, e.g. a metering step that could not run.
	 *
	 * @var array<int,string>
	 */
	public readonly array $warnings;

	/**
	 * Constructor.
	 *
	 * @param int                     $run_id       Run id.
	 * @param RunState                $state        Final state.
	 * @param array<int,string>       $applied      Applied tweak ids.
	 * @param array<string,string>    $skipped      Skipped tweak ids to reasons.
	 * @param array<int,int>          $snapshot_ids Snapshot ids created.
	 * @param VerificationResult|null $verification Verification outcome.
	 * @param string|null             $error        Failure message.
	 * @param array<int,string>       $warnings     Non-fatal warnings.
	 * @throws ContractViolation When an invariant is violated.
	 */
	public function __construct(
		int $run_id,
		RunState $state,
		array $applied = array(),
		array $skipped = array(),
		array $snapshot_ids = array(),
		?VerificationResult $verification = null,
		?string $error = null,
		array $warnings = array()
	) {
		if ( $run_id < 1 ) {
			throw ContractViolation::range( self::class, 'run_id', 'must be a positive run id' );
		}

		foreach ( $applied as $index => $tweak_id ) {
			if ( ! is_string( $tweak_id ) || 1 !== preg_match( Identifier::TWEAK_ID_PATTERN, $tweak_id ) ) {
				throw ContractViolation::type( self::class, 'applied[' . $index . ']', 'tweak id', $tweak_id );
			}
		}

		foreach ( $skipped as $tweak_id => $reason ) {
			if ( ! is_string( $tweak_id ) || 1 !== preg_match( Identifier::TWEAK_ID_PATTERN, $tweak_id ) ) {
				throw ContractViolation::type( self::class, 'skipped key', 'tweak id', $tweak_id );
			}

			if ( ! is_string( $reason ) || '' === trim( $reason ) ) {
				throw ContractViolation::range(
					self::class,
					'skipped[' . $tweak_id . ']',
					'must state why the tweak was skipped'
				);
			}
		}

		foreach ( $snapshot_ids as $index => $snapshot_id ) {
			if ( ! is_int( $snapshot_id ) || $snapshot_id < 1 ) {
				throw ContractViolation::type( self::class, 'snapshot_ids[' . $index . ']', 'positive int', $snapshot_id );
			}
		}

		foreach ( $warnings as $index => $warning ) {
			if ( ! is_string( $warning ) || '' === trim( $warning ) ) {
				throw ContractViolation::type( self::class, 'warnings[' . $index . ']', 'non-empty string', $warning );
			}
		}

		$failed = array( RunState::ABORTED, RunState::APPLY_FAILED, RunState::VERIFICATION_FAILED, RunState::ROLLED_BACK );

		if ( in_array( $state, $failed, true ) && ( null === $error || '' === trim( $error ) ) ) {
			throw ContractViolation::range(
				self::class,
				'error',
				sprintf( 'is required when the run ends in %s; a failure must say what failed', $state->value )
			);
		}

		$applied_sorted = array_values( array_unique( $applied ) );
		sort( $applied_sorted, SORT_STRING );

		ksort( $skipped, SORT_STRING );

		$this->run_id       = $run_id;
		$this->state        = $state;
		$this->applied      = $applied_sorted;
		$this->skipped      = $skipped;
		$this->snapshot_ids = array_values( $snapshot_ids );
		$this->verification = $verification;
		$this->error        = $error;
		$this->warnings     = array_values( $warnings );
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
			array( 'run_id', 'state', 'applied', 'skipped', 'snapshot_ids', 'verification', 'error', 'warnings' )
		);

		$verification = array_key_exists( 'verification', $data ) ? $data['verification'] : null;

		if ( null !== $verification && ! is_array( $verification ) ) {
			throw ContractViolation::type( self::class, 'verification', 'array or null', $verification );
		}

		$snapshot_ids = array();

		foreach ( Assert::arrayOrEmpty( self::class, $data, 'snapshot_ids' ) as $index => $snapshot_id ) {
			if ( ! is_int( $snapshot_id ) ) {
				throw ContractViolation::type( self::class, 'snapshot_ids[' . $index . ']', 'int', $snapshot_id );
			}

			$snapshot_ids[] = $snapshot_id;
		}

		$skipped = array();

		foreach ( Assert::stringKeyedMap( self::class, $data, 'skipped' ) as $tweak_id => $reason ) {
			if ( ! is_string( $reason ) ) {
				throw ContractViolation::type( self::class, 'skipped[' . $tweak_id . ']', 'string', $reason );
			}

			$skipped[ $tweak_id ] = $reason;
		}

		return new self(
			Assert::int( self::class, $data, 'run_id' ),
			Assert::enum( self::class, $data, 'state', RunState::class ),
			Assert::stringList( self::class, $data, 'applied' ),
			$skipped,
			$snapshot_ids,
			null === $verification ? null : VerificationResult::fromArray( $verification ),
			Assert::nullableString( self::class, $data, 'error' ),
			Assert::stringList( self::class, $data, 'warnings' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'run_id'       => $this->run_id,
			'state'        => $this->state->value,
			'applied'      => $this->applied,
			'skipped'      => $this->skipped,
			'snapshot_ids' => $this->snapshot_ids,
			'verification' => null === $this->verification ? null : $this->verification->toArray(),
			'error'        => $this->error,
			'warnings'     => $this->warnings,
		);
	}

	/**
	 * Whether the run committed its changes.
	 *
	 * @return bool
	 */
	public function isCommitted(): bool {
		return RunState::COMMITTED === $this->state;
	}

	/**
	 * Whether the run was rolled back.
	 *
	 * @return bool
	 */
	public function isRolledBack(): bool {
		return RunState::ROLLED_BACK === $this->state;
	}
}
