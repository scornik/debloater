<?php
/**
 * Facts about a WooCommerce store.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;
use Debloater\Scan\AssetParser;
use Debloater\Scan\SampledPages;

/**
 * Collects the `woo.*` facts (BUILD-SPEC §5, §11, §17 Phase 15).
 *
 * WooCommerce loads a lot on every page of a site, including the pages that
 * have nothing to do with the shop. The cart-fragments script is the famous
 * one: it makes an admin-ajax request on page load to find out what is in the
 * cart, on the blog, on the contact page, on the privacy policy.
 *
 * Whether that is waste depends entirely on whether anything on those pages
 * shows a cart, which is why this scanner classifies pages rather than counting
 * them. A store with a mini-cart in its header genuinely needs the fragments
 * everywhere, and telling that store to make them conditional would break the
 * one part of the site a customer uses to check out.
 *
 * **Classification is from the rendered page**, using the body classes
 * WooCommerce itself adds, the block and shortcode markers it leaves behind, and
 * its own asset handles. Conditional tags — `is_cart()`, `is_checkout()` — are
 * the obvious alternative and are useless here: they answer for the request the
 * scan is already inside, which is an admin request, not for the page being
 * classified.
 *
 * The pages come from {@see SampledPages}, already fetched for the asset scan.
 */
final class WooCommerceScanner extends AbstractScanner {

	/**
	 * Body classes and markers WooCommerce puts on its own pages.
	 */
	private const WOO_PAGE_MARKERS = array(
		'class="woocommerce',
		'woocommerce-page',
		'woocommerce-cart',
		'woocommerce-checkout',
		'woocommerce-account',
		'woocommerce-shop',
		'post-type-archive-product',
		'single-product',
		'wp-block-woocommerce-',
		'wc-block-',
		'[woocommerce_cart]',
		'[woocommerce_checkout]',
		'[woocommerce_my_account]',
		'[product',
	);

	/**
	 * Markers a mini-cart leaves on a page.
	 *
	 * A mini-cart is the reason cart fragments are needed away from the shop, so
	 * finding one is what turns a recommendation into a refusal.
	 */
	private const MINI_CART_MARKERS = array(
		'widget_shopping_cart',
		'wp-block-woocommerce-mini-cart',
		'wc-block-mini-cart',
		'cart-contents',
		'menu-item-cart',
	);

	/**
	 * The asset handle that fetches the cart on every page load.
	 */
	private const FRAGMENTS_HANDLE = 'wc-cart-fragments';

	/**
	 * Block stylesheets WooCommerce enqueues.
	 */
	private const BLOCK_STYLE_HANDLES = array( 'wc-blocks-style', 'wc-all-blocks-style' );

	/**
	 * The page sample, shared with every other scanner that reads pages.
	 *
	 * @var SampledPages
	 */
	private SampledPages $sample;

	/**
	 * Constructor.
	 *
	 * @param SampledPages|null $sample Page sample to read.
	 */
	public function __construct( ?SampledPages $sample = null ) {
		$this->sample = $sample ?? new SampledPages();
	}

	/**
	 * Forget the fetched pages.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->sample->forget();
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'woo';
	}

	/**
	 * Collect WooCommerce facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$present = defined( 'WC_VERSION' ) || is_plugin_active( 'woocommerce/woocommerce.php' );

		if ( ! $present ) {
			// The only fact that means anything on a site without a shop.
			return array( 'woo.present' => false );
		}

		return array_merge(
			array(
				'woo.present'                 => true,
				'woo.version'                 => defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : null,
				'woo.admin_analytics'         => $this->analyticsEnabled(),
				'woo.marketplace_suggestions' => $this->marketplaceSuggestionsEnabled(),
			),
			$this->classify( $context )
		);
	}

	/**
	 * Classify the sampled pages and see what the shop loads on each.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	private function classify( Context $context ): array {
		if ( ! $this->sample->available( $context ) ) {
			// Nothing was read, so nothing is claimed. A zero here would read as
			// "the shop loads nothing anywhere", which is the most misleading
			// possible answer.
			return array( 'woo.pages_sampled' => 0 );
		}

		$pages     = $this->sample->pages( $context );
		$shop      = array();
		$other     = array();
		$fragments = array();
		$blocks    = array();
		$mini_cart = array();

		foreach ( $pages as $page ) {
			$body     = $page['body'];
			$is_shop  = $this->isShopPage( $body );
			$handles  = $this->handles( $body );
			$relative = $this->relative( $page['url'], $context->home_url );

			if ( $is_shop ) {
				$shop[] = $relative;
			} else {
				$other[] = $relative;
			}

			if ( in_array( self::FRAGMENTS_HANDLE, $handles, true ) && ! $is_shop ) {
				$fragments[] = $relative;
			}

			if ( array() !== array_intersect( self::BLOCK_STYLE_HANDLES, $handles ) && ! $is_shop ) {
				$blocks[] = $relative;
			}

			if ( $this->hasMiniCart( $body ) ) {
				$mini_cart[] = $relative;
			}
		}

		sort( $shop, SORT_STRING );
		sort( $other, SORT_STRING );
		sort( $fragments, SORT_STRING );
		sort( $blocks, SORT_STRING );
		sort( $mini_cart, SORT_STRING );

		return array(
			'woo.pages_sampled'         => count( $pages ),
			'woo.shop_pages'            => $shop,
			'woo.other_pages'           => $other,
			'woo.fragments_on_other'    => $fragments,
			'woo.block_styles_on_other' => $blocks,
			'woo.mini_cart_pages'       => $mini_cart,
			'woo.mini_cart'             => array() !== $mini_cart,
		);
	}

	/**
	 * Whether a rendered page belongs to the shop.
	 *
	 * @param string $body Rendered HTML.
	 * @return bool
	 */
	private function isShopPage( string $body ): bool {
		foreach ( self::WOO_PAGE_MARKERS as $marker ) {
			if ( false !== strpos( $body, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a rendered page shows a cart away from the shop.
	 *
	 * @param string $body Rendered HTML.
	 * @return bool
	 */
	private function hasMiniCart( string $body ): bool {
		foreach ( self::MINI_CART_MARKERS as $marker ) {
			if ( false !== strpos( $body, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The enqueue handles on a page.
	 *
	 * @param string $body Rendered HTML.
	 * @return array<int,string>
	 */
	private function handles( string $body ): array {
		$handles = array();

		foreach ( AssetParser::parse( $body ) as $asset ) {
			if ( '' !== $asset['handle'] ) {
				$handles[] = $asset['handle'];
			}
		}

		return $handles;
	}

	/**
	 * A URL as a path, so a fact does not carry the site's own address on every
	 * row.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $home_url The site's home URL.
	 * @return string
	 */
	private function relative( string $url, string $home_url ): string {
		$home = untrailingslashit( $home_url );

		if ( '' !== $home && 0 === strpos( $url, $home ) ) {
			$path = substr( $url, strlen( $home ) );

			return '' === $path ? '/' : $path;
		}

		return $url;
	}

	/**
	 * Whether WooCommerce Admin's analytics section is switched on.
	 *
	 * @return bool
	 */
	private function analyticsEnabled(): bool {
		// WooCommerce stores disabled features as a list of names. A feature
		// absent from that list is enabled, which is why this asks the question
		// the way round it does.
		$disabled = get_option( 'woocommerce_admin_disabled_features', array() );

		if ( is_array( $disabled ) && in_array( 'analytics', $disabled, true ) ) {
			return false;
		}

		return 'yes' !== get_option( 'woocommerce_analytics_enabled', 'yes' ) ? false : true;
	}

	/**
	 * Whether the marketplace suggestions are switched on.
	 *
	 * @return bool
	 */
	private function marketplaceSuggestionsEnabled(): bool {
		return 'no' !== get_option( 'woocommerce_show_marketplace_suggestions', 'yes' );
	}
}
