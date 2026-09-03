<?php
/**
 * Analyzer rule: db.revisions.stored.
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

/**
 * Fires when a site is carrying a large number of post revisions.
 *
 * Distinct from `RevisionsUnlimitedRule`, which is about the *future*: capping
 * how many revisions each post keeps from now on. This one is about the past —
 * the revisions already stored — and the two are separate findings because a
 * user might reasonably want one and not the other.
 *
 * The threshold is deliberately high. Revisions are the safety net for anyone
 * who writes, and a site with a few hundred of them does not have a problem
 * worth deleting anything over.
 */
final class StoredRevisionsRule extends AbstractRule {

	/**
	 * Below this many revisions there is nothing worth proposing.
	 */
	private const NOTEWORTHY_COUNT = 500;

	/**
	 * Above this many, the posts table is carrying real weight.
	 */
	private const SUBSTANTIAL_COUNT = 5000;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.revisions.stored';
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
		return array( 'db.revisions.count' );
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

		$revisions = (int) $facts->value( 'db.revisions.count' );

		if ( $revisions < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => $revisions >= self::SUBSTANTIAL_COUNT ? Severity::MEDIUM : Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'title'    => __( 'A lot of old post revisions are stored', 'debloater' ),
				'summary'  => sprintf(
					/* translators: %s: number of revisions. */
					__( '%s revisions are stored across this site.', 'debloater' ),
					number_format_i18n( $revisions )
				),
				'why'      => __(
					'Every time a post is saved, WordPress keeps the previous version as a revision. They live in the posts table alongside the posts themselves, so they are in every backup, every export and every query that scans that table. Deleting the older ones keeps the recent history and removes the rest — but a revision is somebody\'s earlier draft, so nothing here is deleted without a full copy being taken first.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Revisions stored', 'debloater' ), 'db.revisions.count' )
					->optional( __( 'Database size', 'debloater' ), 'db.size_bytes' )
					->build(),
				'impact'   => $this->measurable( 'db.revisions', (float) $revisions, 'rows' ),
				'tweak_id' => 'db.clean_revisions',
			)
		);
	}
}
