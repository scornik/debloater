<?php
/**
 * Analyzer rule: woo.analytics.enabled.
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
 * WooCommerce Analytics is switched on.
 *
 * Analytics keeps its own lookup tables and fills them in from a scheduled job
 * whenever an order changes. On a busy store that is a continuous background
 * cost; on a quiet one it is nothing much.
 *
 * This is offered rather than urged. Plenty of shops read those numbers every
 * morning, and for them the cost is the price of the feature. What WP Debloat
 * can say is that the feature is on and what it costs — not whether anybody
 * looks at it, which it has no way to know.
 */
final class WooAnalyticsRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'woo.analytics.enabled';
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
		return array( 'woo.present', 'woo.admin_analytics' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( 'woo.present' ) ) {
			return null;
		}

		if ( true !== $facts->value( 'woo.admin_analytics' ) ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'tweak_id' => 'woo.disable_admin_analytics',
				'title'    => __( 'WooCommerce Analytics is running', 'wp-debloat' ),
				'summary'  => __( 'The Analytics section of WooCommerce Admin is enabled, with the scheduled imports that keep its tables up to date.', 'wp-debloat' ),
				'why'      => __(
					'WooCommerce Analytics maintains its own set of lookup tables and schedules a job to update them whenever an order changes. If you read those reports, that is simply what the feature costs and this is not worth doing. If you read your numbers somewhere else, it is a background job and a set of tables working for nobody. Turning it off hides the reports and stops the imports; it deletes nothing, and turning it back on restores the section with its history, though WooCommerce will need to catch up on whatever it missed.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Analytics enabled', 'wp-debloat' ), 'woo.admin_analytics' )
					->optional( __( 'WooCommerce version', 'wp-debloat' ), 'woo.version' )
					->build(),
			)
		);
	}
}
