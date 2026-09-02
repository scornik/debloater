<?php
/**
 * Analyzer rule: db.transients.expired.
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
 * Fires when expired transients have accumulated in the options table.
 *
 * This is the one data operation in the MVP set, chosen deliberately because the
 * rows it deletes are the least valuable ones in the database: a transient whose
 * expiry has passed is, by its own declaration, no longer useful. WordPress
 * would delete each one itself if anything asked for it again — the ones left
 * behind are exactly the ones nothing will ask for.
 *
 * It still takes a full Level B snapshot before running (BUILD-SPEC §15). The
 * point of proving the recovery path is to prove it on rows where a mistake
 * costs nothing, not to skip it because the rows look unimportant.
 */
final class ExpiredTransientsRule extends AbstractRule {

	/**
	 * Below this many expired rows, there is nothing worth doing.
	 */
	private const NOTEWORTHY_COUNT = 50;

	/**
	 * Above this many, the options table is carrying real weight.
	 */
	private const SUBSTANTIAL_COUNT = 1000;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.transients.expired';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.96;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'db.transients.expired' );
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

		$expired = (int) $facts->value( 'db.transients.expired' );

		if ( $expired < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => $expired >= self::SUBSTANTIAL_COUNT ? Severity::MEDIUM : Severity::LOW,
				'risk'     => Risk::LOW,
				'title'    => __( 'Expired transients are sitting in the options table', 'wp-debloat' ),
				'summary'  => sprintf(
					/* translators: %s: number of expired transients. */
					__( '%s transients have passed their expiry time and are still stored.', 'wp-debloat' ),
					number_format_i18n( $expired )
				),
				'why'      => __(
					'A transient is a cached value with an expiry date. WordPress deletes an expired one the next time something asks for it — which means the ones still here are the ones nothing will ask for again. They stay in the options table until something removes them, taking up space in every backup and every scan of that table.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Expired transients', 'wp-debloat' ), 'db.transients.expired' )
					->optional( __( 'Transients in total', 'wp-debloat' ), 'db.transients.count' )
					->build(),
				'impact'   => $this->measurable( 'db.transients_expired', (float) $expired, 'rows' ),
				'tweak_id' => 'db.clean_expired_transients',
			)
		);
	}
}
