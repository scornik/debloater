<?php
/**
 * Analyzer rule: db.meta.orphaned.
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
 * Fires when metadata is left behind by content that no longer exists.
 *
 * What counts as an orphan is defined per type in docs/DECISIONS.md D-0026 and
 * is deliberately conservative — a row is an orphan only when the table
 * WordPress itself joins against has no matching row.
 *
 * The risk is medium rather than low even though the rows are, by that
 * definition, unreachable. "Unreachable through WordPress" is not the same as
 * "unwanted": a plugin storing its own data in postmeta against ids it manages
 * itself would look exactly like this.
 */
final class OrphanMetaRule extends AbstractRule {

	/**
	 * Below this many, there is nothing worth doing.
	 */
	private const NOTEWORTHY_COUNT = 200;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.meta.orphaned';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.88;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'db.orphan_postmeta.count' );
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

		$total = (int) $facts->value( 'db.orphan_postmeta.count' )
			+ (int) $facts->value( 'db.orphan_termmeta.count', 0 )
			+ (int) $facts->value( 'db.orphan_usermeta.count', 0 );

		if ( $total < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'title'    => __( 'Metadata is left over from content that no longer exists', 'wp-debloat' ),
				'summary'  => sprintf(
					/* translators: %s: number of orphaned meta rows. */
					__( '%s metadata rows belong to a post, term or user that has been deleted.', 'wp-debloat' ),
					number_format_i18n( $total )
				),
				'why'      => __(
					'Deleting content does not always delete everything attached to it. Rows left behind are unreachable through WordPress but still in the database, in every backup and every query against those tables. What counts as orphaned here is deliberately narrow: a row is only included when the table WordPress itself looks in has no matching owner.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Orphaned post metadata', 'wp-debloat' ), 'db.orphan_postmeta.count' )
					->optional( __( 'Orphaned term metadata', 'wp-debloat' ), 'db.orphan_termmeta.count' )
					->optional( __( 'Orphaned user metadata', 'wp-debloat' ), 'db.orphan_usermeta.count' )
					->build(),
				'impact'   => $this->estimated( 'rows', (float) $total, 'rows' ),
				'tweak_id' => 'db.clean_orphan_meta',
			)
		);
	}
}
