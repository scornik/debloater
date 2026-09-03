<?php
/**
 * Does the cart still work.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify\Probes;

/**
 * GET the cart page as a guest (BUILD-SPEC §11).
 *
 * An empty cart is a perfectly good cart, so this does not look for line items.
 * It looks for the markup WooCommerce puts around a cart at all — if that is
 * gone, something a change did has stopped the shop rendering, whatever the
 * status code says.
 */
final class WooCartProbe extends AbstractWooProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'woo_cart';
	}

	/**
	 * The page's URL, or an empty string when the store has not got one.
	 *
	 * @return string
	 */
	protected function url(): string {
		return $this->pageUrl( 'woocommerce_cart_page_id' );
	}

	/**
	 * What the page must contain to be working.
	 *
	 * @return array<int,string>
	 */
	protected function markers(): array {
		return array( 'woocommerce', 'cart' );
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The cart', 'wp-debloat' );
	}
}
