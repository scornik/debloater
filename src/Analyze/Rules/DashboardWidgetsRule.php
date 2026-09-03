<?php
/**
 * Analyzer rule: admin.dashboard_widgets.crowded.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Severity;

/**
 * The dashboard has a lot on it, and here is who put it there. Info only.
 *
 * Deliberately not a recommendation, though a perfectly good tweak exists for
 * it. `admin.remove_dashboard_widgets` takes a list of widget ids, and the
 * whole question is *which* ones — an answer that depends entirely on what the
 * person uses. Debloater has no way to know whether the WooCommerce status
 * widget is the first thing somebody looks at every morning or something they
 * have never read.
 *
 * A recommendation would have to guess, and guessing here would remove things
 * people rely on. So this reports what is there and who registered it, and the
 * selection screen offers the change with the list ready to choose from. The
 * user picks; nothing is preselected.
 */
final class DashboardWidgetsRule extends AbstractRule {

	/**
	 * How many widgets before this is worth mentioning.
	 *
	 * A default WordPress dashboard has four. Below that there is nothing to
	 * say, and saying it anyway would be manufacturing a problem.
	 */
	public const THRESHOLD = 5;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'admin.dashboard_widgets.crowded';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.97;
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

		if ( ! is_array( $widgets ) || count( $widgets ) < self::THRESHOLD ) {
			return null;
		}

		$by_source = array();

		foreach ( $widgets as $widget ) {
			if ( is_array( $widget ) ) {
				$source               = (string) ( $widget['source'] ?? '' );
				$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;
			}
		}

		arsort( $by_source, SORT_NUMERIC );

		return $this->inform(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::INFO,
				'title'    => sprintf(
					/* translators: %d: number of dashboard widgets. */
					_n(
						'%d widget is registered on the dashboard',
						'%d widgets are registered on the dashboard',
						count( $widgets ),
						'debloater'
					),
					count( $widgets )
				),
				'summary'  => $this->bySource( $by_source ),
				'why'      => __(
					'You can take any of these off the dashboard, and put them back just as easily — nothing is uninstalled and no data is touched. Debloater does not choose for you, and nothing here is preselected: which of these is worth reading is not something a plugin can work out, and the ones that look most removable are sometimes the first thing somebody checks every morning.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Dashboard widgets', 'debloater' ), 'admin.dashboard_widgets' )
					->optional( __( 'Dashboard widget count', 'debloater' ), 'admin.dashboard_widgets.count' )
					->build(),
			)
		);
	}

	/**
	 * Who registered how many.
	 *
	 * @param array<string,int> $by_source Source to count, largest first.
	 * @return string
	 */
	private function bySource( array $by_source ): string {
		$parts = array();

		foreach ( $by_source as $source => $count ) {
			$parts[] = sprintf(
				/* translators: 1: plugin or component name, 2: how many widgets it registers. */
				__( '%1$s (%2$d)', 'debloater' ),
				$source,
				$count
			);
		}

		return implode( ', ', $parts );
	}
}
