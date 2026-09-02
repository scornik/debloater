<?php
/**
 * The set of changes a run would make, shown before anything happens.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * A preview plan (BUILD-SPEC §17 Phase 4).
 *
 * The plan is the contract with the user: this is what will change, this is what
 * will not, this is the recovery that will exist first. Two of the §7.4
 * invariants are structural and are enforced here so no code path can construct
 * an illegal plan:
 *
 * - a tweak appears at most once,
 * - two conflicting tweaks are never both present.
 *
 * The remaining two invariants (no tweak named by an active dont_touch finding,
 * no unresolved `requires`) need the finding set and the fact set, so they are
 * enforced by PreviewPlanner, which is the only thing allowed to build a plan.
 */
final class PreviewPlan {

	/**
	 * Tweaks in the plan, in deterministic id order.
	 *
	 * @var array<int,Tweak>
	 */
	public readonly array $tweaks;

	/**
	 * Human-readable statements of what will change.
	 *
	 * @var array<int,string>
	 */
	public readonly array $will_change;

	/**
	 * Human-readable statements of what will explicitly not change.
	 *
	 * @var array<int,string>
	 */
	public readonly array $will_not;

	/**
	 * Whether any tweak in the plan deletes rows.
	 *
	 * @var bool
	 */
	public readonly bool $destructive;

	/**
	 * Snapshot levels that must be complete before applying.
	 *
	 * @var array<int,SnapshotLevel>
	 */
	public readonly array $snapshot_levels;

	/**
	 * Constructor.
	 *
	 * @param array<int,Tweak>  $tweaks      Tweaks to apply.
	 * @param array<int,string> $will_change Statements of what changes.
	 * @param array<int,string> $will_not    Statements of what does not change.
	 * @throws ContractViolation When the plan violates a structural invariant.
	 */
	public function __construct( array $tweaks, array $will_change = array(), array $will_not = array() ) {
		$by_id = array();

		foreach ( $tweaks as $index => $tweak ) {
			if ( ! $tweak instanceof Tweak ) {
				throw ContractViolation::type( self::class, 'tweaks[' . $index . ']', Tweak::class, $tweak );
			}

			if ( array_key_exists( $tweak->id, $by_id ) ) {
				throw ContractViolation::range(
					self::class,
					'tweaks',
					sprintf( 'tweak "%s" appears more than once in the plan', $tweak->id )
				);
			}

			$by_id[ $tweak->id ] = $tweak;
		}

		ksort( $by_id, SORT_STRING );

		foreach ( $by_id as $tweak ) {
			foreach ( $tweak->conflicts as $conflict_id ) {
				if ( array_key_exists( $conflict_id, $by_id ) ) {
					throw ContractViolation::range(
						self::class,
						'tweaks',
						sprintf( 'conflicting tweaks "%s" and "%s" cannot be in one plan', $tweak->id, $conflict_id )
					);
				}
			}
		}

		foreach ( array(
			'will_change' => $will_change,
			'will_not'    => $will_not,
		) as $field => $lines ) {
			foreach ( $lines as $index => $line ) {
				if ( ! is_string( $line ) || '' === trim( $line ) ) {
					throw ContractViolation::type( self::class, $field . '[' . $index . ']', 'non-empty string', $line );
				}
			}
		}

		$destructive = false;
		$levels      = array();

		foreach ( $by_id as $tweak ) {
			$destructive = $destructive || $tweak->destructive;

			$levels[ $tweak->requiredSnapshotLevel()->value ] = $tweak->requiredSnapshotLevel();
		}

		if ( array() !== $by_id ) {
			$levels[ SnapshotLevel::A->value ] = SnapshotLevel::A;
		}

		ksort( $levels, SORT_STRING );

		$this->tweaks          = array_values( $by_id );
		$this->will_change     = array_values( $will_change );
		$this->will_not        = array_values( $will_not );
		$this->destructive     = $destructive;
		$this->snapshot_levels = array_values( $levels );
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
			array( 'tweaks', 'will_change', 'will_not', 'destructive', 'snapshot_levels' )
		);

		$tweaks = array();

		foreach ( Assert::arrayList( self::class, $data, 'tweaks' ) as $entry ) {
			$tweaks[] = Tweak::fromArray( $entry );
		}

		$plan = new self(
			$tweaks,
			Assert::stringList( self::class, $data, 'will_change' ),
			Assert::stringList( self::class, $data, 'will_not' )
		);

		// destructive and snapshot_levels are derived, never trusted from input.
		if ( array_key_exists( 'destructive', $data ) && $data['destructive'] !== $plan->destructive ) {
			throw ContractViolation::range(
				self::class,
				'destructive',
				'is derived from the plan contents and must match the tweaks provided'
			);
		}

		if ( array_key_exists( 'snapshot_levels', $data ) ) {
			$claimed = Assert::stringList( self::class, $data, 'snapshot_levels' );
			$actual  = array_map(
				static fn ( SnapshotLevel $level ): string => $level->value,
				$plan->snapshot_levels
			);

			if ( $claimed !== $actual ) {
				throw ContractViolation::range(
					self::class,
					'snapshot_levels',
					'is derived from the plan contents and must match the tweaks provided'
				);
			}
		}

		return $plan;
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'tweaks'          => array_map(
				static fn ( Tweak $tweak ): array => $tweak->toArray(),
				$this->tweaks
			),
			'will_change'     => $this->will_change,
			'will_not'        => $this->will_not,
			'destructive'     => $this->destructive,
			'snapshot_levels' => array_map(
				static fn ( SnapshotLevel $level ): string => $level->value,
				$this->snapshot_levels
			),
		);
	}

	/**
	 * Whether the plan contains no tweaks.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->tweaks;
	}

	/**
	 * Tweak ids in the plan, in deterministic order.
	 *
	 * @return array<int,string>
	 */
	public function tweakIds(): array {
		return array_map( static fn ( Tweak $tweak ): string => $tweak->id, $this->tweaks );
	}

	/**
	 * Whether the plan contains a given tweak id.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return bool
	 */
	public function contains( string $tweak_id ): bool {
		return in_array( $tweak_id, $this->tweakIds(), true );
	}

	/**
	 * Only the configuration tweaks, which compile into runtime.php.
	 *
	 * @return array<int,Tweak>
	 */
	public function configTweaks(): array {
		return array_values(
			array_filter( $this->tweaks, static fn ( Tweak $tweak ): bool => TweakKind::CONFIG === $tweak->kind )
		);
	}

	/**
	 * Only the data tweaks, which run as DataOperations.
	 *
	 * @return array<int,Tweak>
	 */
	public function dataTweaks(): array {
		return array_values(
			array_filter( $this->tweaks, static fn ( Tweak $tweak ): bool => TweakKind::DATA === $tweak->kind )
		);
	}

	/**
	 * The union of probes declared by the tweaks in the plan, sorted.
	 *
	 * @return array<int,string>
	 */
	public function probes(): array {
		$probes = array();

		foreach ( $this->tweaks as $tweak ) {
			foreach ( $tweak->probes as $probe ) {
				$probes[ $probe ] = true;
			}
		}

		$names = array_keys( $probes );
		sort( $names, SORT_STRING );

		return $names;
	}
}
