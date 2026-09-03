<?php
/**
 * Analyzer rule: db.trash.pending.
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
 * Fires when content has been sitting in the trash.
 *
 * The trash is a decision already made: somebody deleted this content and
 * WordPress kept it in case they changed their mind. After thirty days
 * WordPress removes it itself, when its scheduled task runs.
 */
final class TrashRule extends AbstractRule {

	/**
	 * Below this many, there is nothing worth doing.
	 */
	private const NOTEWORTHY_COUNT = 20;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.trash.pending';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.92;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'db.trash.count' );
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

		$trashed = (int) $facts->value( 'db.trash.count' );

		if ( $trashed < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'title'    => __( 'Content is waiting in the trash', 'debloater' ),
				'summary'  => sprintf(
					/* translators: %s: number of trashed items. */
					__( '%s items are in the trash.', 'debloater' ),
					number_format_i18n( $trashed )
				),
				'why'      => __(
					'Trashed content is still in the posts table, with its metadata and its terms, until something empties the trash. WordPress does that itself after thirty days when its scheduled task runs. Emptying it deletes the content permanently, so only items that have been there a while are touched — something trashed this morning is very often something about to be untrashed.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Items in the trash', 'debloater' ), 'db.trash.count' )
					->build(),
				'impact'   => $this->estimated( 'rows', (float) $trashed, 'rows' ),
				'tweak_id' => 'db.empty_trash',
			)
		);
	}
}
