<?php
/**
 * Analyzer rule: admin.news_widget.present.
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
 * The WordPress Events and News dashboard widget is registered.
 *
 * Of everything on a default dashboard, this is the one that reaches the
 * network while an admin page is loading — it fetches release news and nearby
 * events from wordpress.org. That is why it is worth naming on its own rather
 * than leaving it to the general "remove some widgets" change: the reason to
 * remove it is not only the space it takes up, and a person deciding should
 * know which reason they are acting on.
 */
final class NewsWidgetRule extends AbstractRule {

	/**
	 * The dashboard widget this rule is about.
	 */
	public const WIDGET_ID = 'dashboard_primary';

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'admin.news_widget.present';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.98;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'admin.dashboard_widgets' );
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

		$widgets = $facts->value( 'admin.dashboard_widgets', array() );

		if ( ! is_array( $widgets ) ) {
			return null;
		}

		$present = false;

		foreach ( $widgets as $widget ) {
			if ( is_array( $widget ) && self::WIDGET_ID === ( $widget['id'] ?? '' ) ) {
				$present = true;
			}
		}

		if ( ! $present ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::LOW,
				'risk'     => Risk::SAFE,
				'tweak_id' => 'admin.remove_wp_news_widget',
				'title'    => __( 'The Events and News widget is on the dashboard', 'wp-debloat' ),
				'summary'  => __( 'The WordPress Events and News widget is registered on the dashboard.', 'wp-debloat' ),
				'why'      => __(
					'This is the one widget on a default dashboard that fetches something over the network while the page is loading — release news and nearby events, from wordpress.org. If you read it, keep it. If you have never read it, it is doing that on every dashboard load for nobody.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Dashboard widgets', 'wp-debloat' ), 'admin.dashboard_widgets' )
					->optional( __( 'Dashboard widget count', 'wp-debloat' ), 'admin.dashboard_widgets.count' )
					->build(),
			)
		);
	}
}
