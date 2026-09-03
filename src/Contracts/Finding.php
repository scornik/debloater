<?php
/**
 * Something the analyzer concluded from the facts.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * A finding (BUILD-SPEC §6).
 *
 * A finding is a conclusion, never a change. It carries four independent
 * dimensions that the UI must not collapse into one number:
 *
 * - severity: how much this matters,
 * - risk: how dangerous the recommended change would be (locked decision #4),
 * - confidence: how sure we are the finding and its recommendation are right,
 * - decision: recommend, dont_touch, or info (locked decision #6).
 *
 * Evidence is mandatory (locked decision #5): a finding with no evidence cannot
 * be constructed.
 */
final class Finding {

	/**
	 * Finding id, e.g. "wp.heartbeat.aggressive".
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Category, which is also the sub-score bucket.
	 *
	 * @var Category
	 */
	public readonly Category $category;

	/**
	 * How much this finding matters.
	 *
	 * @var Severity
	 */
	public readonly Severity $severity;

	/**
	 * Risk of making the recommended change.
	 *
	 * @var Risk
	 */
	public readonly Risk $risk;

	/**
	 * Confidence in the finding and its recommendation, 0..1.
	 *
	 * @var float
	 */
	public readonly float $confidence;

	/**
	 * Short title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * One-paragraph summary of what was observed.
	 *
	 * @var string
	 */
	public readonly string $summary;

	/**
	 * Why this matters, in the user's terms.
	 *
	 * @var string
	 */
	public readonly string $why;

	/**
	 * Evidence entries, each naming the fact it came from.
	 *
	 * @var array<int,Evidence>
	 */
	public readonly array $evidence;

	/**
	 * Estimated impact, when one can be stated.
	 *
	 * @var Impact|null
	 */
	public readonly ?Impact $impact;

	/**
	 * What the analyzer decided.
	 *
	 * @var Decision
	 */
	public readonly Decision $decision;

	/**
	 * Why nothing should be done. Required when the decision is dont_touch.
	 *
	 * @var string|null
	 */
	public readonly ?string $decision_reason;

	/**
	 * The recommended tweak, when there is one.
	 *
	 * @var Recommendation|null
	 */
	public readonly ?Recommendation $recommendation;

	/**
	 * Whether the recommended change can be undone.
	 *
	 * @var bool
	 */
	public readonly bool $undo;

	/**
	 * Tweak ids or fact predicates this finding's recommendation requires.
	 *
	 * @var array<int,string>
	 */
	public readonly array $requires;

	/**
	 * Tweak ids this finding's recommendation conflicts with.
	 *
	 * @var array<int,string>
	 */
	public readonly array $conflicts;

	/**
	 * How many detected dependents rely on what would be changed.
	 *
	 * @var int
	 */
	public readonly int $dependencies_detected;

	/**
	 * Constructor.
	 *
	 * @param string              $id                    Finding id.
	 * @param Category            $category              Category.
	 * @param Severity            $severity              Severity.
	 * @param Risk                $risk                  Risk of the change.
	 * @param float               $confidence            Confidence, 0..1.
	 * @param string              $title                 Short title.
	 * @param string              $summary               Summary of the observation.
	 * @param string              $why                   Why it matters.
	 * @param array<int,Evidence> $evidence              Evidence entries, at least one.
	 * @param Impact|null         $impact                Estimated impact.
	 * @param Decision            $decision              Analyzer decision.
	 * @param string|null         $decision_reason       Reason, required for dont_touch.
	 * @param Recommendation|null $recommendation        Recommended tweak.
	 * @param bool                $undo                  Whether the change is reversible.
	 * @param array<int,string>   $requires              Required tweak ids or fact predicates.
	 * @param array<int,string>   $conflicts             Conflicting tweak ids.
	 * @param int                 $dependencies_detected Count of detected dependents.
	 * @throws ContractViolation When any invariant is violated.
	 */
	public function __construct(
		string $id,
		Category $category,
		Severity $severity,
		Risk $risk,
		float $confidence,
		string $title,
		string $summary,
		string $why,
		array $evidence,
		?Impact $impact,
		Decision $decision,
		?string $decision_reason,
		?Recommendation $recommendation,
		bool $undo,
		array $requires = array(),
		array $conflicts = array(),
		int $dependencies_detected = 0
	) {
		if ( 1 !== preg_match( Identifier::FINDING_ID_PATTERN, $id ) ) {
			throw ContractViolation::range(
				self::class,
				'id',
				sprintf( 'must be a dotted finding id such as "wp.heartbeat.aggressive", got "%s"', $id )
			);
		}

		foreach ( array(
			'title'   => $title,
			'summary' => $summary,
			'why'     => $why,
		) as $field => $text ) {
			if ( '' === trim( $text ) ) {
				throw ContractViolation::range( self::class, $field, 'must not be empty' );
			}
		}

		if ( is_nan( $confidence ) || $confidence < 0.0 || $confidence > 1.0 ) {
			throw ContractViolation::range(
				self::class,
				'confidence',
				sprintf( 'must be between 0 and 1 inclusive, got %s', var_export( $confidence, true ) )
			);
		}

		if ( array() === $evidence ) {
			throw ContractViolation::range(
				self::class,
				'evidence',
				'every finding must carry at least one evidence entry'
			);
		}

		foreach ( $evidence as $index => $item ) {
			if ( ! $item instanceof Evidence ) {
				throw ContractViolation::type( self::class, 'evidence[' . $index . ']', Evidence::class, $item );
			}
		}

		if ( $decision->requiresReason() && ( null === $decision_reason || '' === trim( $decision_reason ) ) ) {
			throw ContractViolation::range(
				self::class,
				'decision_reason',
				'is required when the decision is dont_touch'
			);
		}

		if ( ! $decision->requiresReason() && null !== $decision_reason ) {
			throw ContractViolation::range(
				self::class,
				'decision_reason',
				sprintf( 'is only allowed when the decision is dont_touch, but the decision is %s', $decision->value )
			);
		}

		if ( $decision->requiresRecommendation() && null === $recommendation ) {
			throw ContractViolation::range(
				self::class,
				'recommendation',
				'is required when the decision is recommend'
			);
		}

		if ( ! $decision->allowsRecommendation() && null !== $recommendation ) {
			throw ContractViolation::range(
				self::class,
				'recommendation',
				'is not allowed on an info finding; info findings propose no change'
			);
		}

		if ( $dependencies_detected < 0 ) {
			throw ContractViolation::range( self::class, 'dependencies_detected', 'must not be negative' );
		}

		$this->id                    = $id;
		$this->category              = $category;
		$this->severity              = $severity;
		$this->risk                  = $risk;
		$this->confidence            = $confidence;
		$this->title                 = $title;
		$this->summary               = $summary;
		$this->why                   = $why;
		$this->evidence              = array_values( $evidence );
		$this->impact                = $impact;
		$this->decision              = $decision;
		$this->decision_reason       = $decision_reason;
		$this->recommendation        = $recommendation;
		$this->undo                  = $undo;
		$this->requires              = array_values( $requires );
		$this->conflicts             = array_values( $conflicts );
		$this->dependencies_detected = $dependencies_detected;
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
				'category',
				'severity',
				'risk',
				'confidence',
				'title',
				'summary',
				'why',
				'evidence',
				'impact',
				'decision',
				'decision_reason',
				'recommendation',
				'undo',
				'requires',
				'conflicts',
				'dependencies_detected',
			)
		);

		$evidence = array();

		foreach ( Assert::arrayList( self::class, $data, 'evidence' ) as $entry ) {
			$evidence[] = Evidence::fromArray( $entry );
		}

		$impact_data = array_key_exists( 'impact', $data ) ? $data['impact'] : null;

		if ( null !== $impact_data && ! is_array( $impact_data ) ) {
			throw ContractViolation::type( self::class, 'impact', 'array or null', $impact_data );
		}

		$recommendation_data = array_key_exists( 'recommendation', $data ) ? $data['recommendation'] : null;

		if ( null !== $recommendation_data && ! is_array( $recommendation_data ) ) {
			throw ContractViolation::type( self::class, 'recommendation', 'array or null', $recommendation_data );
		}

		return new self(
			Assert::string( self::class, $data, 'id' ),
			Assert::enum( self::class, $data, 'category', Category::class ),
			Assert::enum( self::class, $data, 'severity', Severity::class ),
			Assert::enum( self::class, $data, 'risk', Risk::class ),
			Assert::floatBetween( self::class, $data, 'confidence', 0.0, 1.0 ),
			Assert::string( self::class, $data, 'title' ),
			Assert::string( self::class, $data, 'summary' ),
			Assert::string( self::class, $data, 'why' ),
			$evidence,
			null === $impact_data ? null : Impact::fromArray( $impact_data ),
			Assert::enum( self::class, $data, 'decision', Decision::class ),
			Assert::nullableString( self::class, $data, 'decision_reason' ),
			null === $recommendation_data ? null : Recommendation::fromArray( $recommendation_data ),
			Assert::bool( self::class, $data, 'undo' ),
			Assert::stringList( self::class, $data, 'requires' ),
			Assert::stringList( self::class, $data, 'conflicts' ),
			Assert::intOr( self::class, $data, 'dependencies_detected', 0 )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                    => $this->id,
			'category'              => $this->category->value,
			'severity'              => $this->severity->value,
			'risk'                  => $this->risk->value,
			'confidence'            => $this->confidence,
			'title'                 => $this->title,
			'summary'               => $this->summary,
			'why'                   => $this->why,
			'evidence'              => array_map(
				static fn ( Evidence $entry ): array => $entry->toArray(),
				$this->evidence
			),
			'impact'                => null === $this->impact ? null : $this->impact->toArray(),
			'decision'              => $this->decision->value,
			'decision_reason'       => $this->decision_reason,
			'recommendation'        => null === $this->recommendation ? null : $this->recommendation->toArray(),
			'undo'                  => $this->undo,
			'requires'              => $this->requires,
			'conflicts'             => $this->conflicts,
			'dependencies_detected' => $this->dependencies_detected,
		);
	}

	/**
	 * The recommended tweak id, or null when this finding proposes nothing.
	 *
	 * @return string|null
	 */
	public function recommendedTweakId(): ?string {
		return null === $this->recommendation ? null : $this->recommendation->tweak_id;
	}

	/**
	 * Whether this finding may contribute a tweak to a plan.
	 *
	 * @return bool
	 */
	public function isPlannable(): bool {
		return $this->decision->isPlannable() && null !== $this->recommendation;
	}

	/**
	 * The score penalty this finding contributes (BUILD-SPEC §12).
	 *
	 * Dont-touch findings contribute nothing: we do not penalise a site for a
	 * configuration we have decided not to change.
	 *
	 * @return int
	 */
	public function scorePenalty(): int {
		if ( Decision::DONT_TOUCH === $this->decision ) {
			return 0;
		}

		return $this->severity->penalty();
	}

	/**
	 * A copy with a different decision, reason and recommendation.
	 *
	 * Used by DontTouchRules and the compatibility resolver, which turn a
	 * recommendation into a refusal without rebuilding the finding by hand.
	 *
	 * @param Decision            $decision        New decision.
	 * @param string|null         $decision_reason Reason, required for dont_touch.
	 * @param Recommendation|null $recommendation  Recommendation to keep or drop.
	 * @return self
	 * @throws ContractViolation When the resulting combination is invalid.
	 */
	public function withDecision(
		Decision $decision,
		?string $decision_reason = null,
		?Recommendation $recommendation = null
	): self {
		return new self(
			$this->id,
			$this->category,
			$this->severity,
			$this->risk,
			$this->confidence,
			$this->title,
			$this->summary,
			$this->why,
			$this->evidence,
			$this->impact,
			$decision,
			$decision_reason,
			$decision->allowsRecommendation() ? ( $recommendation ?? $this->recommendation ) : null,
			$this->undo,
			$this->requires,
			$this->conflicts,
			$this->dependencies_detected
		);
	}

	/**
	 * A copy with a different confidence and dependency count.
	 *
	 * @param float $confidence            New confidence, 0..1.
	 * @param int   $dependencies_detected New dependency count.
	 * @return self
	 * @throws ContractViolation When the values are out of range.
	 */
	public function withConfidence( float $confidence, int $dependencies_detected ): self {
		return new self(
			$this->id,
			$this->category,
			$this->severity,
			$this->risk,
			$confidence,
			$this->title,
			$this->summary,
			$this->why,
			$this->evidence,
			$this->impact,
			$this->decision,
			$this->decision_reason,
			$this->recommendation,
			$this->undo,
			$this->requires,
			$this->conflicts,
			$dependencies_detected
		);
	}

	/**
	 * A copy with something added to the reasoning.
	 *
	 * The analyzer already amends a finding after the rule that produced it has
	 * returned — its confidence, its decision, its risk. This is the same kind
	 * of amendment for the one field a user actually reads: a rule states the
	 * general case, and the analyzer knows things about this site that the rule
	 * does not.
	 *
	 * Appending rather than replacing is the point. What the rule said stays
	 * said; the site-specific sentence follows it.
	 *
	 * @param string $sentence Sentence to append. Blank input returns the same finding.
	 * @return self
	 */
	public function withAddedReasoning( string $sentence ): self {
		if ( '' === trim( $sentence ) ) {
			return $this;
		}

		return new self(
			$this->id,
			$this->category,
			$this->severity,
			$this->risk,
			$this->confidence,
			$this->title,
			$this->summary,
			rtrim( $this->why ) . ' ' . trim( $sentence ),
			$this->evidence,
			$this->impact,
			$this->decision,
			$this->decision_reason,
			$this->recommendation,
			$this->undo,
			$this->requires,
			$this->conflicts,
			$this->dependencies_detected
		);
	}

	/**
	 * A copy with a different risk level.
	 *
	 * @param Risk $risk New risk level.
	 * @return self
	 */
	public function withRisk( Risk $risk ): self {
		return new self(
			$this->id,
			$this->category,
			$this->severity,
			$risk,
			$this->confidence,
			$this->title,
			$this->summary,
			$this->why,
			$this->evidence,
			$this->impact,
			$this->decision,
			$this->decision_reason,
			$this->recommendation,
			$this->undo,
			$this->requires,
			$this->conflicts,
			$this->dependencies_detected
		);
	}
}
