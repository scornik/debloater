<?php
/**
 * What an analysis produced.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze;

use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Finding;

/**
 * The findings, the score, and an account of what could not be looked at.
 *
 * The last of those is the reason this is a class rather than a plain array. A
 * rule that could not run leaves no finding, and so does a rule that ran and
 * found nothing wrong. Presenting both as silence would let an incomplete scan
 * read as a clean one.
 */
final class AnalysisResult {

	/**
	 * Findings, in finding-id order.
	 *
	 * @var array<int,Finding>
	 */
	public readonly array $findings;

	/**
	 * Finding ids whose rule could not evaluate the facts.
	 *
	 * @var array<int,string>
	 */
	public readonly array $not_evaluated;

	/**
	 * Finding ids whose rule threw, mapped to the failure.
	 *
	 * @var array<string,string>
	 */
	public readonly array $failed;

	/**
	 * Constructor.
	 *
	 * @param array<int,Finding>   $findings      Findings produced.
	 * @param array<int,string>    $not_evaluated Rules that could not run.
	 * @param array<string,string> $failed        Rules that threw.
	 */
	public function __construct( array $findings, array $not_evaluated = array(), array $failed = array() ) {
		$this->findings      = array_values( $findings );
		$this->not_evaluated = array_values( $not_evaluated );
		$this->failed        = $failed;
	}

	/**
	 * The score for these findings.
	 *
	 * @return Score
	 */
	public function score(): Score {
		return new Score( $this->findings );
	}

	/**
	 * Findings that propose a change.
	 *
	 * @return array<int,Finding>
	 */
	public function recommended(): array {
		return $this->withDecision( Decision::RECOMMEND );
	}

	/**
	 * Findings WP Debloat has decided to leave alone.
	 *
	 * @return array<int,Finding>
	 */
	public function dontTouch(): array {
		return $this->withDecision( Decision::DONT_TOUCH );
	}

	/**
	 * Findings that are worth knowing but propose nothing.
	 *
	 * @return array<int,Finding>
	 */
	public function informational(): array {
		return $this->withDecision( Decision::INFO );
	}

	/**
	 * A finding by id, or null.
	 *
	 * @param string $finding_id Finding id.
	 * @return Finding|null
	 */
	public function find( string $finding_id ): ?Finding {
		foreach ( $this->findings as $finding ) {
			if ( $finding->id === $finding_id ) {
				return $finding;
			}
		}

		return null;
	}

	/**
	 * Whether a finding was produced.
	 *
	 * @param string $finding_id Finding id.
	 * @return bool
	 */
	public function has( string $finding_id ): bool {
		return null !== $this->find( $finding_id );
	}

	/**
	 * How many findings there are.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->findings );
	}

	/**
	 * Whether every rule ran.
	 *
	 * @return bool
	 */
	public function isComplete(): bool {
		return array() === $this->not_evaluated && array() === $this->failed;
	}

	/**
	 * The findings and the score, ready to persist in a run payload.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'findings'      => array_map(
				static fn ( Finding $finding ): array => $finding->toArray(),
				$this->findings
			),
			'score'         => $this->score()->toArray(),
			'not_evaluated' => $this->not_evaluated,
			'failed'        => $this->failed,
		);
	}

	/**
	 * Findings carrying a given decision.
	 *
	 * @param Decision $decision Decision to filter by.
	 * @return array<int,Finding>
	 */
	private function withDecision( Decision $decision ): array {
		return array_values(
			array_filter( $this->findings, static fn ( Finding $finding ): bool => $finding->decision === $decision )
		);
	}
}
