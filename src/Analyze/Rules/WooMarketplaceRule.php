<?php
/**
 * Analyzer rule: woo.marketplace.suggestions.
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
 * WooCommerce is showing marketplace suggestions in the admin.
 *
 * The panels offering paid extensions on the products, orders and settings
 * screens. WooCommerce provides documented filters for turning them off, they
 * are marketing, and nothing operational travels through them — which is what
 * makes this the one WooCommerce change here that is genuinely safe.
 *
 * Notices about the store itself are a different channel and are untouched: a
 * pending database update or a gateway that needs configuring still reaches the
 * person running the shop. That distinction is the whole reason this is `safe`
 * where hiding a plugin's admin notices wholesale (Phase 12) is `medium`.
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
				'title'    => __( 'WooCommerce is showing extension suggestions in your admin', 'wp-debloat' ),
				'summary'  => __( 'Marketplace suggestions appear on the products, orders and settings screens.', 'wp-debloat' ),
				'why'      => __(
					'These are the panels recommending paid extensions. WooCommerce has its own documented switches for them, which is what this change uses, and nothing operational goes through the same channel — notices about your store itself are untouched, so a pending database update or a gateway that needs configuring still reaches you. That is why this one is safe where hiding a plugin\'s admin notices wholesale is not.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Marketplace suggestions enabled', 'wp-debloat' ), 'woo.marketplace_suggestions' )
					->build(),
			)
		);
	}
}
