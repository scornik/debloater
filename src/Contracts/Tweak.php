<?php
/**
 * A resolved tweak, ready to be planned and applied.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * A tweak resolved for this site (BUILD-SPEC §7.1).
 *
 * This is the runtime-side counterpart of a registry TweakDefinition: the
 * definition says what a tweak *can* be, a Tweak says what it *is* for this
 * site — parameters chosen, final risk assessed by the RiskEngine, handler
 * resolved. Everything the §7.4 plan invariants need to reason about is
 * present here, so PreviewPlanner never has to consult the registry again to
 * decide whether a plan is legal.
 */
final class Tweak {

	/**
	 * Tweak id, e.g. "core.heartbeat_interval".
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Human-readable title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Category.
	 *
	 * @var Category
	 */
	public readonly Category $category;

	/**
	 * Whether this is a configuration hook or a one-shot data operation.
	 *
	 * @var TweakKind
	 */
	public readonly TweakKind $kind;

	/**
	 * Final risk for this site, after RiskEngine adjustments.
	 *
	 * @var Risk
	 */
	public readonly Risk $risk;

	/**
	 * Whether applying this tweak deletes rows.
	 *
	 * @var bool
	 */
	public readonly bool $destructive;

	/**
	 * Whether the change can be undone.
	 *
	 * @var bool
	 */
	public readonly bool $reversible;

	/**
	 * Chosen parameters.
	 *
	 * @var TweakParams
	 */
	public readonly TweakParams $params;

	/**
	 * Handler: a runtime-handlers/ file for config tweaks, a DataOperation
	 * class name for data tweaks.
	 *
	 * @var string
	 */
	public readonly string $handler;

	/**
	 * Tweak ids or fact predicates that must hold.
	 *
	 * @var array<int,string>
	 */
	public readonly array $requires;

	/**
	 * Tweak ids that must not be applied alongside this one.
	 *
	 * @var array<int,string>
	 */
	public readonly array $conflicts;

	/**
	 * Probes that should verify this tweak after it is applied.
	 *
	 * @var array<int,string>
	 */
	public readonly array $probes;

	/**
	 * Constructor.
	 *
	 * @param string            $id          Tweak id.
	 * @param string            $title       Human-readable title.
	 * @param Category          $category    Category.
	 * @param TweakKind         $kind        Config or data.
	 * @param Risk              $risk        Final risk for this site.
	 * @param bool              $destructive Whether rows are deleted.
	 * @param bool              $reversible  Whether the change can be undone.
	 * @param TweakParams       $params      Chosen parameters.
	 * @param string            $handler     Handler file or class.
	 * @param array<int,string> $requires    Requirements.
	 * @param array<int,string> $conflicts   Conflicting tweak ids.
	 * @param array<int,string> $probes      Probes to run after applying.
	 * @throws ContractViolation When an invariant is violated.
	 */
	public function __construct(
		string $id,
		string $title,
		Category $category,
		TweakKind $kind,
		Risk $risk,
		bool $destructive,
		bool $reversible,
		TweakParams $params,
		string $handler,
		array $requires = array(),
		array $conflicts = array(),
		array $probes = array()
	) {
		if ( 1 !== preg_match( Identifier::TWEAK_ID_PATTERN, $id ) ) {
			throw ContractViolation::range(
				self::class,
				'id',
				sprintf( 'must be a dotted tweak id such as "core.disable_emojis", got "%s"', $id )
			);
		}

		if ( '' === trim( $title ) ) {
			throw ContractViolation::range( self::class, 'title', 'must not be empty' );
		}

		if ( '' === trim( $handler ) ) {
			throw ContractViolation::range( self::class, 'handler', 'must not be empty' );
		}

		if ( $destructive && TweakKind::DATA !== $kind ) {
			throw ContractViolation::range(
				self::class,
				'destructive',
				'only data tweaks may be destructive; a config tweak changes hooks, not rows'
			);
		}

		if ( $destructive && ! $reversible ) {
			throw ContractViolation::range(
				self::class,
				'reversible',
				'a destructive tweak must be reversible; it requires a Level B snapshot to be restorable'
			);
		}

		if ( in_array( $id, $conflicts, true ) ) {
			throw ContractViolation::range( self::class, 'conflicts', 'a tweak must not conflict with itself' );
		}

		if ( in_array( $id, $requires, true ) ) {
			throw ContractViolation::range( self::class, 'requires', 'a tweak must not require itself' );
		}

		$this->id          = $id;
		$this->title       = $title;
		$this->category    = $category;
		$this->kind        = $kind;
		$this->risk        = $risk;
		$this->destructive = $destructive;
		$this->reversible  = $reversible;
		$this->params      = $params;
		$this->handler     = $handler;
		$this->requires    = array_values( $requires );
		$this->conflicts   = array_values( $conflicts );
		$this->probes      = array_values( $probes );
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
				'title',
				'category',
				'kind',
				'risk',
				'destructive',
				'reversible',
				'params',
				'handler',
				'requires',
				'conflicts',
				'probes',
			)
		);

		return new self(
			Assert::string( self::class, $data, 'id' ),
			Assert::string( self::class, $data, 'title' ),
			Assert::enum( self::class, $data, 'category', Category::class ),
			Assert::enum( self::class, $data, 'kind', TweakKind::class ),
			Assert::enum( self::class, $data, 'risk', Risk::class ),
			Assert::bool( self::class, $data, 'destructive' ),
			Assert::bool( self::class, $data, 'reversible' ),
			TweakParams::fromArray( Assert::stringKeyedMap( self::class, $data, 'params' ) ),
			Assert::string( self::class, $data, 'handler' ),
			Assert::stringList( self::class, $data, 'requires' ),
			Assert::stringList( self::class, $data, 'conflicts' ),
			Assert::stringList( self::class, $data, 'probes' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'title'       => $this->title,
			'category'    => $this->category->value,
			'kind'        => $this->kind->value,
			'risk'        => $this->risk->value,
			'destructive' => $this->destructive,
			'reversible'  => $this->reversible,
			'params'      => $this->params->toArray(),
			'handler'     => $this->handler,
			'requires'    => $this->requires,
			'conflicts'   => $this->conflicts,
			'probes'      => $this->probes,
		);
	}

	/**
	 * Whether this tweak is eligible for the "Fix Safe Issues" plan on risk and
	 * destructiveness alone (BUILD-SPEC §7.4).
	 *
	 * This is a necessary condition, never a sufficient one: PreviewPlanner also
	 * checks the finding decision, unresolved requires and conflicts.
	 *
	 * @return bool
	 */
	public function isSafePlanEligible(): bool {
		return $this->risk->isSafePlanEligible() && ! $this->destructive;
	}

	/**
	 * The snapshot level this tweak requires before it may be applied.
	 *
	 * @return SnapshotLevel
	 */
	public function requiredSnapshotLevel(): SnapshotLevel {
		return $this->kind->requiredSnapshotLevel();
	}

	/**
	 * A copy with a different final risk, as set by the RiskEngine.
	 *
	 * @param Risk $risk New risk level.
	 * @return self
	 */
	public function withRisk( Risk $risk ): self {
		return new self(
			$this->id,
			$this->title,
			$this->category,
			$this->kind,
			$risk,
			$this->destructive,
			$this->reversible,
			$this->params,
			$this->handler,
			$this->requires,
			$this->conflicts,
			$this->probes
		);
	}

	/**
	 * A copy with different parameters.
	 *
	 * @param TweakParams $params New parameters.
	 * @return self
	 */
	public function withParams( TweakParams $params ): self {
		return new self(
			$this->id,
			$this->title,
			$this->category,
			$this->kind,
			$this->risk,
			$this->destructive,
			$this->reversible,
			$params,
			$this->handler,
			$this->requires,
			$this->conflicts,
			$this->probes
		);
	}
}
