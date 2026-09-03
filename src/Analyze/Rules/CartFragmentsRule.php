<?php
/**
 * Analyzer rule: woo.cart_fragments.everywhere.
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
 * WooCommerce's cart-fragments script loads on pages that are not the shop.
 *
 * The script makes an admin-ajax request on page load to find out what is in
 * the cart. On a shop page that is what it is for. On the blog, the contact page
 * and the privacy policy it is an uncached request per visitor per page for a
 * cart that nothing on the page displays.
 *
 * **Unless something does display one.** A mini-cart in the header is the whole
 * counter-example, and it is common — most shop themes have one. On such a site
 * the fragments genuinely are needed everywhere, and making them conditional
 * would leave a cart total that never updates. The refusal for that lives in
 * `DontTouchRules`, where it can see the whole site rather than one finding.
 */
final class CartFragmentsRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'woo.cart_fragments.everywhere';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * The observation is exact — the script either was on those pages or was
	 * not — and the uncertainty is the sample: a page nobody fetched was not
	 * measured, and a mini-cart could be on one of them.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.8;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'woo.present', 'woo.fragments_on_other', 'woo.pages_sampled' );
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

		$pages   = $facts->value( 'woo.fragments_on_other', array() );
		$sampled = (int) $facts->value( 'woo.pages_sampled', 0 );

		if ( ! is_array( $pages ) || array() === $pages || $sampled < 1 ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ASSETS,
				'severity' => Severity::MEDIUM,
				'risk'     => Risk::MEDIUM,
				'tweak_id' => 'woo.cart_fragments_conditional',
				'title'    => sprintf(
					/* translators: %d: number of non-shop pages that loaded the script. */
					_n(
						'Cart fragments load on %d page that is not part of the shop',
						'Cart fragments load on %d pages that are not part of the shop',
						count( $pages ),
						'debloater'
					),
					count( $pages )
				),
				'summary'  => sprintf(
					/* translators: 1: comma-separated page paths, 2: number of pages sampled. */
					__( 'Loaded on: %1$s. Of %2$d pages sampled.', 'debloater' ),
					implode( ', ', array_slice( array_map( 'strval', $pages ), 0, 10 ) ),
					$sampled
				),
				'why'      => __(
					'The cart-fragments script asks the server what is in the cart every time a page loads, and that request cannot be served from a cache because the answer is different for every visitor. On a shop page it is doing its job. On a blog post it is a round trip for a cart the page never shows. This change keeps the script on every WooCommerce page and drops it elsewhere, deciding page by page as each one is built. If anything on your site shows a cart away from the shop — a total in the header, a widget in a sidebar — Debloater will decline this change instead of offering it.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Non-shop pages loading cart fragments', 'debloater' ), 'woo.fragments_on_other' )
					->fact( __( 'Pages sampled', 'debloater' ), 'woo.pages_sampled' )
					->optional( __( 'Pages that are part of the shop', 'debloater' ), 'woo.shop_pages' )
					->optional( __( 'Pages showing a cart', 'debloater' ), 'woo.mini_cart_pages' )
					->build(),
			)
		);
	}
}
