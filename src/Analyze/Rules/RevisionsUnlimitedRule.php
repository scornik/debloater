<?php
/**
 * Analyzer rule: db.revisions.unlimited.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze\Rules;

use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;

/**
 * Fires when WordPress keeps every revision and the count has grown.
 *
 * Two conditions, not one. Unlimited revisions on a site with forty of them is
 * a setting, not a problem; the finding needs both the setting and the evidence
 * that it is actually accumulating.
 *
 * The recommendation is careful about what it changes. Capping revisions is a
 * configuration tweak that affects what happens from now on: WordPress prunes
 * the oldest revisions of a post the next time that post is saved. It deletes
 * nothing on its own, which is why it can be low risk and reversible. Removing
 * the revisions that already exist is a different, destructive operation that
 * takes a Level B snapshot first, and it arrives in Phase 10.
 */
final class RevisionsUnlimitedRule extends AbstractRule {

	/**
	 * Revision count above which the setting is worth raising.
	 *
	 * Below this, unlimited revisions are not costing the site anything a user
	 * would notice, and saying otherwise would be manufacturing a problem.
	 */
	private const NOTEWORTHY_COUNT = 200;

	/**
	 * Revision count above which this stops being a tidy-up.
	 */
	private const SUBSTANTIAL_COUNT = 5000;

	/**
	 * Revisions to keep per post.
	 */
	private const KEEP_PER_POST = 5;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.revisions.unlimited';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.93;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'wp.revisions_limit', 'db.revisions.count' );
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

		$limit = (int) $facts->value( 'wp.revisions_limit' );
		$count = (int) $facts->value( 'db.revisions.count' );

		// -1 means unlimited. A site that has already set a cap has made this
		// decision, and a lower cap than ours is still a decision.
		if ( -1 !== $limit || $count < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => $count >= self::SUBSTANTIAL_COUNT ? Severity::MEDIUM : Severity::LOW,
				'risk'     => Risk::LOW,
				'title'    => __( 'Every revision of every post is kept forever', 'wp-debloat' ),
				'summary'  => sprintf(
					/* translators: 1: number of revisions, 2: revisions to keep per post. */
					__( 'WordPress is keeping every revision, and there are now %1$s of them. Keeping the most recent %2$d per post would stop the number growing.', 'wp-debloat' ),
					number_format_i18n( $count ),
					self::KEEP_PER_POST
				),
				'why'      => __(
					'Each revision is a full copy of the post in the posts table, with its own meta. On a site edited regularly they outnumber the real content several times over, which makes every backup larger and every query over the posts table slower. Capping the number changes what happens from now on: nothing is deleted, and WordPress prunes the oldest revisions of a post the next time that post is saved.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->formatted( __( 'Revision limit', 'wp-debloat' ), __( 'Unlimited', 'wp-debloat' ), 'wp.revisions_limit' )
					->fact( __( 'Revisions stored', 'wp-debloat' ), 'db.revisions.count' )
					->optional( __( 'Database size', 'wp-debloat' ), 'db.size_bytes' )
					->build(),
				'impact'   => $this->estimated( 'db.revisions', (float) $count, 'rows' ),
				'tweak_id' => 'core.limit_revisions',
				'params'   => array( 'keep' => self::KEEP_PER_POST ),
			)
		);
	}
}
