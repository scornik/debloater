<?php
/**
 * Analyzer rule: admin.update_nag.for_everyone.
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
 * The core update notice is shown to people who cannot act on it.
 *
 * This fires only where there is somebody it could be hidden from. On a site
 * with one administrator and nobody else, hiding it from non-administrators
 * changes nothing, and offering the change would be noise.
 *
 * The person who *can* update always sees it. That is not a parameter and it is
 * not negotiable: an out-of-date WordPress is the single most common way a site
 * gets broken into, and a plugin that quietly stopped the update notice
 * reaching the person responsible would have done real harm in exchange for a
 * tidier screen.
 */
final class UpdateNagRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'admin.update_nag.for_everyone';
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
		return array( 'admin.update_nag', 'users.admin_count' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( 'admin.update_nag' ) ) {
			return null;
		}

		$editors = (int) $facts->value( 'users.recent_editors_7d', 0 );
		$admins  = (int) $facts->value( 'users.admin_count', 0 );

		// Nobody to hide it from. A site whose only users are administrators
		// gains nothing here, and saying so would be inventing a problem.
		if ( $editors <= $admins ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::LOW,
				'risk'     => Risk::SAFE,
				'tweak_id' => 'admin.hide_update_nags_non_admins',
				'title'    => __( 'The core update notice is shown to people who cannot update', 'wp-debloat' ),
				'summary'  => sprintf(
					/* translators: 1: number of people who edited content recently, 2: number of administrators. */
					__( '%1$d people edited content here in the last week and %2$d of the accounts are administrators.', 'wp-debloat' ),
					$editors,
					$admins
				),
				'why'      => __(
					'"WordPress x.y is available" goes to everyone who can open the admin, including authors, editors and shop managers, none of whom can act on it. What they can do is worry about it, or interrupt somebody. Hiding it from them leaves it exactly where it needs to be: anyone who can actually run the update still sees it, every time, and that part is not configurable.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Core update notice registered', 'wp-debloat' ), 'admin.update_nag' )
					->fact( __( 'Administrators', 'wp-debloat' ), 'users.admin_count' )
					->optional( __( 'People who edited content in the last week', 'wp-debloat' ), 'users.recent_editors_7d' )
					->build(),
			)
		);
	}
}
