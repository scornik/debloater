<?php
/**
 * Does the checkout still work.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

/**
 * GET the checkout page as a guest (BUILD-SPEC §11).
 *
 * The most important probe in the product. Everything Debloater does to a store
 * is worth less than one broken checkout, and this is the assertion that turns
 * that sentence into behaviour: every WooCommerce tweak lists this probe, so a
 * change that breaks it is rolled back rather than committed.
 *
 * A guest checkout is what a customer meets. It must contain a form; a checkout
 * page that renders without one is a page nobody can buy anything on.
 */
final class WooCheckoutProbe extends AbstractWooProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'woo_checkout';
	}

	/**
	 * The page's URL, or an empty string when the store has not got one.
	 *
	 * @return string
	 */
	protected function url(): string {
		return $this->pageUrl( 'woocommerce_checkout_page_id' );
	}

	/**
	 * What the page must contain to be working.
	 *
	 * @return array<int,string>
	 */
	protected function markers(): array {
		return array( 'woocommerce', 'form' );
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The checkout', 'debloater' );
	}
}
