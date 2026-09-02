<?php
/**
 * The Debloat Score.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze;

use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Finding;

/**
 * A configuration score, not a performance benchmark (BUILD-SPEC §12, locked
 * decision #1).
 *
 * This number says how much of what WP Debloat looked at is in a state it would
 * suggest changing. It says nothing about how fast the site is, and it must
 * never be presented as if it did — that is what the Meter is for, and the Meter
 * reports measured deltas in countable units.
 *
 * Three properties are load-bearing:
 *
 * - **It is deterministic.** The same findings always produce the same score.
 *   No time, no randomness, no dependence on the order findings arrive in.
 * - **Refusals cost nothing.** A `dont_touch` finding contributes no penalty.
 *   Penalising a site for a configuration we have decided not to change would
 *   mean showing a number the user cannot improve without doing something we
 *   have told them not to do.
 * - **Each finding id counts once.** A rule that fires twice cannot double its
 *   own weight.
 *
 * Assets is not a sub-score in v1 (BUILD-SPEC §12; Phase 13 adds it, Phase 12
 * adds Admin). Findings in an unscored category are reported separately rather
 * than dropped, so nothing is silently invisible.
 */
final class Score {

	/**
	 * The rubric version. Bump alongside docs/SCORING.md.
	 */
	public const RUBRIC_VERSION = '1.0';

	/**
	 * Categories that make up the headline score in this version.
	 *
	 * @return array<int,Category>
	 */
	public static function scoredCategories(): array {
		return array(
			Category::WORDPRESS,
			Category::CONFIGURATION,
			Category::DATABASE,
			Category::PLUGINS,
			Category::MAINTENANCE,
		);
	}

	/**
	 * Findings the score is computed from.
	 *
	 * @var array<int,Finding>
	 */
	private array $findings;

	/**
	 * Constructor.
	 *
	 * @param array<int,Finding> $findings Findings from a scan.
	 */
	public function __construct( array $findings ) {
		$this->findings = array_values( $findings );
	}

	/**
	 * The headline score, 0..100.
	 *
	 * The unweighted mean of the sub-scores, because v1 has no evidence for
	 * saying one category matters more than another, and inventing weights would
	 * be inventing a claim.
	 *
	 * @return int
	 */
	public function headline(): int {
		$sub_scores = $this->subScores();

		if ( array() === $sub_scores ) {
			return 100;
		}

		return (int) round( array_sum( $sub_scores ) / count( $sub_scores ) );
	}

	/**
	 * Each sub-score, keyed by category value.
	 *
	 * @return array<string,int>
	 */
	public function subScores(): array {
		$scores = array();

		foreach ( self::scoredCategories() as $category ) {
			$scores[ $category->value ] = $this->subScore( $category );
		}

		return $scores;
	}

	/**
	 * One sub-score.
	 *
	 * @param Category $category Category to score.
	 * @return int
	 */
	public function subScore( Category $category ): int {
		$penalty = 0;

		foreach ( $this->penaltiesFor( $category ) as $points ) {
			$penalty += $points;
		}

		return 100 - min( 100, $penalty );
	}

	/**
	 * The penalty each finding contributes in a category, keyed by finding id.
	 *
	 * Keying by id is what caps a finding at one contribution.
	 *
	 * @param Category $category Category to inspect.
	 * @return array<string,int>
	 */
	public function penaltiesFor( Category $category ): array {
		$penalties = array();

		foreach ( $this->findings as $finding ) {
			if ( $finding->category !== $category ) {
				continue;
			}

			if ( Decision::DONT_TOUCH === $finding->decision ) {
				continue;
			}

			$penalties[ $finding->id ] = $finding->scorePenalty();
		}

		ksort( $penalties, SORT_STRING );

		return $penalties;
	}

	/**
	 * Findings in categories this version does not score.
	 *
	 * Reported so the UI can say "3 findings in Assets, not yet part of the
	 * score" rather than leaving them out of sight.
	 *
	 * @return array<string,int> Category value to finding count.
	 */
	public function unscoredCategories(): array {
		$scored = array_map( static fn ( Category $category ): string => $category->value, self::scoredCategories() );
		$counts = array();

		foreach ( $this->findings as $finding ) {
			if ( in_array( $finding->category->value, $scored, true ) ) {
				continue;
			}

			$value            = $finding->category->value;
			$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
		}

		ksort( $counts, SORT_STRING );

		return $counts;
	}

	/**
	 * How many findings carry each decision.
	 *
	 * The dont_touch count is shown on its own in the dashboard — "No action
	 * recommended" — because a refusal is a result, not a gap.
	 *
	 * @return array<string,int>
	 */
	public function countsByDecision(): array {
		$counts = array(
			Decision::RECOMMEND->value  => 0,
			Decision::DONT_TOUCH->value => 0,
			Decision::INFO->value       => 0,
		);

		foreach ( $this->findings as $finding ) {
			++$counts[ $finding->decision->value ];
		}

		return $counts;
	}

	/**
	 * How many actionable findings carry each risk level.
	 *
	 * Only findings that propose something are counted: a risk level attached to
	 * a refusal describes a change nobody is being offered.
	 *
	 * @return array<string,int>
	 */
	public function countsByRisk(): array {
		$counts = array();

		foreach ( \WPDebloat\Contracts\Risk::cases() as $risk ) {
			$counts[ $risk->value ] = 0;
		}

		foreach ( $this->findings as $finding ) {
			if ( Decision::RECOMMEND === $finding->decision ) {
				++$counts[ $finding->risk->value ];
			}
		}

		return $counts;
	}

	/**
	 * The whole score, ready to persist or render.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'rubric_version'      => self::RUBRIC_VERSION,
			'headline'            => $this->headline(),
			'sub_scores'          => $this->subScores(),
			'counts_by_decision'  => $this->countsByDecision(),
			'counts_by_risk'      => $this->countsByRisk(),
			'unscored_categories' => $this->unscoredCategories(),
			'findings_total'      => count( $this->findings ),
		);
	}
}
