<?php
/**
 * Does the account page still work.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify\Probes;

/**
 * GET the account page as a guest (BUILD-SPEC §11).
 *
 * A guest sees the login and registration form rather than an account, which is
 * exactly the part worth checking: it is the way a returning customer gets back
 * to their orders, and it is rendered by WooCommerce rather than by the theme.
 */
final class WooAccountProbe extends AbstractWooProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'woo_account';
	}

	/**
	 * The page's URL, or an empty string when the store has not got one.
	 *
	 * @return string
	 */
	protected function url(): string {
		return $this->pageUrl( 'woocommerce_myaccount_page_id' );
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
		return __( 'The account page', 'wp-debloat' );
	}
}
