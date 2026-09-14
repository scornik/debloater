<?php
/**
 * Analyzer rule: woo.marketplace.suggestions.
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
 * WooCommerce is showing marketplace suggestions in the admin.
 *
 * The panels offering paid extensions on the products, orders and settings
 * screens. `woo.marketplace_suggestions` is read the way WooCommerce reads it —
 * the store setting, then `woocommerce_allow_marketplace_suggestions` — so the
 * tweak this recommends, which answers through that filter, clears the finding.
 *
 * The finding does not claim more than the tweak does. Checked against
 * WooCommerce 11.1.0, the effect is partial: the store setting, not the filter,
 * still decides the extension link on the Shipping settings tab and the
 * recommendations WooCommerce's newer admin screens read through its REST
 * options endpoint. Only the store setting switches those off.
 *
 * Until 0.5.0 the tweak also silenced WooCommerce's note on Dashboard → Updates
 * that extension updates are waiting. It no longer does (`D-0079`).
 */
final class WooMarketplaceRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'woo.marketplace.suggestions';
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
		return array( 'woo.present', 'woo.marketplace_suggestions' );
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

		if ( true !== $facts->value( 'woo.marketplace_suggestions' ) ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::LOW,
				'risk'     => Risk::SAFE,
				'tweak_id' => 'woo.suppress_marketplace_suggestions',
				'title'    => __( 'WooCommerce is showing extension suggestions in your admin', 'hakeemify-debloater' ),
				'summary'  => __( 'Marketplace suggestions appear on the products, orders and settings screens.', 'hakeemify-debloater' ),
				'why'      => __(
					'These are the panels recommending paid extensions. The change uses WooCommerce\'s own filter for them, which removes most of them but not all: WooCommerce\'s own "Show Suggestions" setting, under Settings → Advanced → WooCommerce.com, still decides the extension link on the Shipping settings tab and the recommendations its newer admin screens load, and only that setting turns those off. Notices about your store, and the note that extension updates are waiting, are untouched.',
					'hakeemify-debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Marketplace suggestions enabled', 'hakeemify-debloater' ), 'woo.marketplace_suggestions' )
					->build(),
			)
		);
	}
}
