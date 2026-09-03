<?php
/**
 * Shared behaviour for the WooCommerce probes.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify\Probes;

use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\ProbeResult;
use WPDebloat\Contracts\ProbeStatus;

/**
 * A probe that fetches one of WooCommerce's own pages as a guest
 * (BUILD-SPEC §11, §17 Phase 15).
 *
 * The three pages a store cannot lose are the cart, the checkout and the
 * account page. Every WooCommerce change in the registry lists all three, so a
 * change that quietly broke one is rolled back rather than committed.
 *
 * **As a guest, deliberately.** A logged-in administrator sees a different page:
 * caching behaves differently, notices appear, and some themes render a shop
 * differently for someone who can edit it. What matters is what a customer gets.
 *
 * A store that has not created these pages yet, or a site without WooCommerce,
 * is `NOT_TESTED` rather than a failure. There is nothing to check, and claiming
 * a pass would be claiming to have checked.
 */
abstract class AbstractWooProbe extends AbstractHttpProbe {

	/**
	 * Whether WooCommerce is present and this page exists.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts from the most recent scan.
	 * @return bool
	 */
	public function applies( Context $context, FactSet $facts ): bool {
		unset( $context );

		if ( true !== $facts->value( 'woo.present' ) && ! $this->wooIsLoaded() ) {
			return false;
		}

		return '' !== $this->url();
	}

	/**
	 * Fetch the page and look for the markers it must contain.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		$url = $this->url();

		if ( '' === $url ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::NOT_TESTED,
				sprintf(
					/* translators: %s: the name of a WooCommerce page. */
					__( 'This store has no %s page, so there was nothing to check.', 'wp-debloat' ),
					$this->describe()
				)
			);
		}

		$response = $this->http->get( $url );

		$failure = $this->judgeHtml( $response );

		if ( null !== $failure ) {
			return $failure;
		}

		$missing = array();

		foreach ( $this->markers() as $marker ) {
			if ( false === stripos( $response->body, $marker ) ) {
				$missing[] = $marker;
			}
		}

		if ( array() !== $missing ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				sprintf(
					/* translators: 1: the name of a WooCommerce page, 2: comma-separated markers. */
					__( '%1$s loaded, but what makes it work is missing from it: %2$s.', 'wp-debloat' ),
					$this->describe(),
					implode( ', ', $missing )
				),
				$response->evidence()
			);
		}

		return $this->judgeRendered( $response );
	}

	/**
	 * The URL of the page this probe checks, or an empty string when the store
	 * has not got one.
	 *
	 * @return string
	 */
	abstract protected function url(): string;

	/**
	 * Strings the page must contain to be working.
	 *
	 * @return array<int,string>
	 */
	abstract protected function markers(): array;

	/**
	 * The permalink of a WooCommerce page by its option, or an empty string.
	 *
	 * @param string $page_id_option The WooCommerce option holding the page id.
	 * @return string
	 */
	protected function pageUrl( string $page_id_option ): string {
		$page_id = (int) get_option( $page_id_option, 0 );

		if ( $page_id < 1 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		$permalink = get_permalink( $page_id );

		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Whether WooCommerce is loaded in this request.
	 *
	 * Used when there are no facts to read — a verification can be asked for
	 * before a scan has ever run.
	 *
	 * @return bool
	 */
	private function wooIsLoaded(): bool {
		return defined( 'WC_VERSION' ) || class_exists( 'WooCommerce', false );
	}
}
