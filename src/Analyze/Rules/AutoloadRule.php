<?php
/**
 * Analyzer rule: db.autoload.heavy.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Severity;

use Debloater\Apply\DataOperations\AutoloadReview;

/**
 * Reports what WordPress loads into memory before it does anything else.
 *
 * Every option flagged `autoload` is read on every request, including requests
 * that will never look at it. A site carrying a few megabytes of it pays for all
 * of it on every page view.
 *
 * This finding names the largest contributors whatever they are, because the
 * user is owed the truth about what is loading. The change it proposes is far
 * narrower: only options whose names match an allowlist Debloater maintains.
 * Deciding automatically that some other plugin\'s option is not needed early is
 * exactly the kind of judgement that breaks sites in ways nobody can trace, so
 * the report is wide and the action is narrow.
 */
final class AutoloadRule extends AbstractRule {

	/**
	 * Below this, autoloaded data is not worth mentioning.
	 */
	private const NOTEWORTHY_BYTES = 512000;

	/**
	 * Above this, it is worth mentioning loudly.
	 */
	private const SUBSTANTIAL_BYTES = 1048576;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.autoload.heavy';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.9;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'db.autoload.bytes' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) ) {
			return null;
		}

		$bytes = (int) $facts->value( 'db.autoload.bytes' );

		if ( $bytes < self::NOTEWORTHY_BYTES ) {
			return null;
		}

		$severity = $bytes >= self::SUBSTANTIAL_BYTES ? Severity::MEDIUM : Severity::LOW;

		$evidence = $this->evidence( $facts )
			->fact( __( 'Loaded on every request', 'debloater' ), 'db.autoload.bytes' )
			->optional( __( 'Largest autoloaded options', 'debloater' ), 'db.autoload.top' )
			->build();

		$summary = sprintf(
			/* translators: %s: amount of autoloaded data, already formatted. */
			__( '%s of options are loaded into memory on every request.', 'debloater' ),
			size_format( $bytes )
		);

		$why = __(
			'WordPress reads every option marked to autoload before it does anything else, on every single request, whether or not that request needs them. Most of it belongs to plugins and is genuinely needed early; some of it is cache timeouts and per-visitor session data that is only read when something asks for it.',
			'debloater'
		);

		// Only propose the change when there is something on the allowlist to
		// change. Otherwise this is worth knowing and nothing more, and saying
		// so is better than offering a button that would do nothing.
		if ( array() === $this->actionable( $facts ) ) {
			return $this->inform(
				array(
					'category' => Category::DATABASE,
					'severity' => Severity::INFO,
					'title'    => __( 'A lot is loaded on every request', 'debloater' ),
					'summary'  => $summary,
					'why'      => $why . ' ' . __(
						'None of it matches the small set of options Debloater knows to be safe to defer, so there is nothing to propose here — only something to be aware of.',
						'debloater'
					),
					'evidence' => $evidence,
					'impact'   => $this->measurable( 'db.autoload_bytes', (float) $bytes, 'bytes' ),
				)
			);
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => $severity,
				'risk'     => Risk::LOW,
				'title'    => __( 'A lot is loaded on every request', 'debloater' ),
				'summary'  => $summary,
				'why'      => $why,
				'evidence' => $evidence,
				'impact'   => $this->measurable( 'db.autoload_bytes', (float) $this->actionableBytes( $facts ), 'bytes' ),
				'tweak_id' => 'db.autoload_off',
			)
		);
	}

	/**
	 * The allowlisted options this scan saw, from the facts.
	 *
	 * Read from `db.autoload.top` rather than queried. A rule that goes to the
	 * database has stopped being a function of the scan: two runs over the same
	 * facts would stop producing the same finding, which is the one thing the
	 * engine's determinism rests on.
	 *
	 * The consequence is that this sees the largest options rather than all of
	 * them, which is the right trade. Anything too small to appear in that list
	 * is too small to be worth proposing a change for.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return array<int,array{name:string,bytes:int}>
	 */
	private function actionable( FactSet $facts ): array {
		$top = $facts->value( 'db.autoload.top', array() );

		if ( ! is_array( $top ) ) {
			return array();
		}

		$actionable = array();

		foreach ( $top as $option ) {
			if ( ! is_array( $option ) || ! is_string( $option['name'] ?? null ) ) {
				continue;
			}

			foreach ( AutoloadReview::ALLOWED_PREFIXES as $prefix ) {
				if ( 0 === strpos( $option['name'], $prefix ) ) {
					$actionable[] = array(
						'name'  => $option['name'],
						'bytes' => (int) ( $option['bytes'] ?? 0 ),
					);

					break;
				}
			}
		}

		return $actionable;
	}

	/**
	 * How many bytes the change would actually stop loading.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return int
	 */
	private function actionableBytes( FactSet $facts ): int {
		$bytes = 0;

		foreach ( $this->actionable( $facts ) as $option ) {
			$bytes += $option['bytes'];
		}

		return $bytes;
	}
}
