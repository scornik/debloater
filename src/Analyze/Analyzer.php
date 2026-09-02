<?php
/**
 * Turns facts into findings.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze;

use Throwable;
use WPDebloat\Contracts\AnalyzerRuleInterface;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Registry\Registry;

/**
 * Runs every rule and assembles the result (BUILD-SPEC §6, §17 Phase 3).
 *
 * The analyzer changes nothing. It reads facts, asks each rule what it makes of
 * them, applies the site's confidence penalties and refusal rules, and hands
 * back findings. Nothing downstream is obliged to act on any of them.
 *
 * Three things happen to every finding a rule returns, in this order, and the
 * order matters:
 *
 * 1. **Dependents are counted**, from the compatibility registry.
 * 2. **Confidence is recalculated** from the rule's base and the site's
 *    penalties — including the dependents just counted.
 * 3. **Refusals are applied.** A finding may become `dont_touch`, keeping the
 *    confidence figure, because how sure we are of a reading does not change
 *    when we decide not to act on it.
 *
 * A rule that cannot evaluate the facts is recorded as **not evaluated** rather
 * than passing silently. "We could not look" and "we looked and it was fine"
 * produce the same absence of findings, and only one of them should be
 * presented as reassurance.
 */
final class Analyzer {

	/**
	 * Rules to run.
	 *
	 * @var array<int,AnalyzerRuleInterface>
	 */
	private array $rules;

	/**
	 * The registry, for compatibility rules.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Whether the site has custom mu-plugins.
	 *
	 * @var bool
	 */
	private bool $has_custom_code;

	/**
	 * Constructor.
	 *
	 * @param array<int,AnalyzerRuleInterface> $rules           Rules to run.
	 * @param Registry                         $registry        Registry with compatibility rules.
	 * @param bool                             $has_custom_code Whether custom mu-plugins are present.
	 */
	public function __construct( array $rules, Registry $registry, bool $has_custom_code = false ) {
		$this->rules           = array_values( $rules );
		$this->registry        = $registry;
		$this->has_custom_code = $has_custom_code;
	}

	/**
	 * Analyze a fact set.
	 *
	 * @param FactSet $facts Facts from a scan.
	 * @return AnalysisResult
	 */
	public function analyze( FactSet $facts ): AnalysisResult {
		$confidence = new ConfidenceCalculator( $facts, $this->has_custom_code );
		$refusals   = new DontTouchRules( $this->registry, $facts );

		$findings      = array();
		$not_evaluated = array();
		$failed        = array();

		foreach ( $this->rules as $rule ) {
			$id = $rule->findingId();

			try {
				if ( ! $rule->supports( $facts ) ) {
					$not_evaluated[] = $id;
					continue;
				}

				$finding = $rule->analyze( $facts );
			} catch ( Throwable $error ) {
				$failed[ $id ] = sprintf( '%s: %s', get_class( $error ), $error->getMessage() );
				continue;
			}

			if ( null === $finding ) {
				continue;
			}

			$dependents = $refusals->dependentCount( $finding->id );

			$finding = $finding->withConfidence(
				$confidence->calculate( $rule->baseConfidence(), $dependents ),
				$dependents
			);

			$findings[ $finding->id ] = $refusals->apply( $finding );
		}

		ksort( $findings, SORT_STRING );
		sort( $not_evaluated, SORT_STRING );
		ksort( $failed, SORT_STRING );

		return new AnalysisResult( array_values( $findings ), $not_evaluated, $failed );
	}

	/**
	 * The rules this analyzer will run.
	 *
	 * @return array<int,AnalyzerRuleInterface>
	 */
	public function rules(): array {
		return $this->rules;
	}

	/**
	 * The finding ids this analyzer can produce, sorted.
	 *
	 * @return array<int,string>
	 */
	public function findingIds(): array {
		$ids = array_map(
			static fn ( AnalyzerRuleInterface $rule ): string => $rule->findingId(),
			$this->rules
		);

		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Refuse a rule set where two rules claim the same finding id.
	 *
	 * One rule, one finding id (BUILD-SPEC §17 Phase 3). Two rules producing the
	 * same id would mean one silently replacing the other in the results.
	 *
	 * @return void
	 * @throws \RuntimeException When an id is claimed twice.
	 */
	public function assertRulesAreDistinct(): void {
		$seen = array();

		foreach ( $this->rules as $rule ) {
			$id = $rule->findingId();

			if ( array_key_exists( $id, $seen ) ) {
				throw new \RuntimeException(
					sprintf(
						'Finding id "%s" is produced by both %s and %s. One rule, one finding id.',
						$id,
						$seen[ $id ],
						get_class( $rule )
					)
				);
			}

			$seen[ $id ] = get_class( $rule );
		}
	}
}
