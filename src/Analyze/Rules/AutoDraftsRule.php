<?php
/**
 * Analyzer rule: db.autodrafts.abandoned.
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
 * Fires when abandoned auto-drafts have accumulated.
 *
 * WordPress creates an auto-draft every time somebody opens the editor, and
 * deletes them after seven days — when `wp_scheduled_delete` runs, which on a
 * site with few visitors may be seldom or never.
 *
 * These are the emptiest rows in the database: a post nobody wrote, with a title
 * nobody typed.
 */
final class AutoDraftsRule extends AbstractRule {

	/**
	 * Below this many, there is nothing worth doing.
	 */
	private const NOTEWORTHY_COUNT = 25;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.autodrafts.abandoned';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.95;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'db.autodrafts.count' );
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

		$drafts = (int) $facts->value( 'db.autodrafts.count' );

		if ( $drafts < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => Severity::LOW,
				'risk'     => Risk::LOW,
				'title'    => __( 'Abandoned auto-drafts are still stored', 'debloater' ),
				'summary'  => sprintf(
					/* translators: %s: number of auto-drafts. */
					__( '%s auto-drafts were created and never written.', 'debloater' ),
					number_format_i18n( $drafts )
				),
				'why'      => __(
					'WordPress creates an auto-draft the moment somebody clicks "Add New", whether or not they type anything. It deletes them itself after a week, but only when its scheduled task runs — on a quiet site that can be a long time. These are rows for posts that were never written.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Auto-drafts stored', 'debloater' ), 'db.autodrafts.count' )
					->build(),
				'impact'   => $this->estimated( 'rows', (float) $drafts, 'rows' ),
				'tweak_id' => 'db.clean_auto_drafts',
			)
		);
	}
}
