<?php
/**
 * Analyzer rule: admin.welcome_panel.visible.
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
 * The dashboard welcome panel is still being printed.
 *
 * WordPress lets each person dismiss this, and stores the dismissal per user.
 * On a site with one administrator that is the end of it. On a site with eight
 * people it is eight dismissals, and every new colleague meets it again on
 * their first day.
 *
 * Removing it changes no stored preference, so unselecting the change puts
 * everyone back exactly where they were — including the people who never
 * dismissed it.
 */
final class WelcomePanelRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'admin.welcome_panel.visible';
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
		return array( 'admin.welcome_panel' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( 'admin.welcome_panel' ) ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::LOW,
				'risk'     => Risk::SAFE,
				'tweak_id' => 'admin.remove_welcome_panel',
				'title'    => __( 'The dashboard welcome panel is still being shown', 'debloater' ),
				'summary'  => __( 'WordPress is still printing the "Welcome to WordPress!" panel on the dashboard.', 'debloater' ),
				'why'      => __(
					'Everyone can dismiss this for themselves, but the dismissal is stored per person, so on a site with several people each of them has to do it — and every new colleague meets it again on their first day. Removing it changes nobody\'s stored preference, so putting it back leaves everyone exactly where they were.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Welcome panel', 'debloater' ), 'admin.welcome_panel' )
					->build(),
			)
		);
	}
}
