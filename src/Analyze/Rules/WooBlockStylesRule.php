<?php
/**
 * Analyzer rule: woo.block_styles.everywhere.
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
 * WooCommerce block stylesheets load on pages with no WooCommerce block on them.
 *
 * Smaller than the cart-fragments problem and the same shape: a stylesheet is
 * one cacheable request rather than an uncached round trip per visitor, so this
 * is `low` severity even though the change is medium risk.
 *
 * The risk is what earns the caution. A stylesheet dropped from a page that
 * needed it is a visibly broken layout, and a WooCommerce block inside a
 * template part or a page builder is not something this can see from the
 * rendered HTML alone.
 */
final class WooBlockStylesRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'woo.block_styles.everywhere';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.78;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'woo.present', 'woo.block_styles_on_other' );
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

		$pages = $facts->value( 'woo.block_styles_on_other', array() );

		if ( ! is_array( $pages ) || array() === $pages ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ASSETS,
				'severity' => Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'tweak_id' => 'woo.block_styles_conditional',
				'title'    => sprintf(
					/* translators: %d: number of non-shop pages loading the stylesheets. */
					_n(
						'WooCommerce block styles load on %d page with no WooCommerce block on it',
						'WooCommerce block styles load on %d pages with no WooCommerce block on them',
						count( $pages ),
						'debloater'
					),
					count( $pages )
				),
				'summary'  => sprintf(
					/* translators: %s: comma-separated page paths. */
					__( 'Loaded on: %s.', 'debloater' ),
					implode( ', ', array_slice( array_map( 'strval', $pages ), 0, 10 ) )
				),
				'why'      => __(
					'This change keeps the block stylesheets on every WooCommerce page and on any page whose content contains a WooCommerce block, and drops them elsewhere. It is a stylesheet rather than a request per visitor, so the saving is modest; what earns the medium risk is the other direction, because a block living in a template part or a page builder is not visible from the page markup and would lose its styling.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Pages loading the stylesheets', 'debloater' ), 'woo.block_styles_on_other' )
					->optional( __( 'Pages that are part of the shop', 'debloater' ), 'woo.shop_pages' )
					->optional( __( 'Pages sampled', 'debloater' ), 'woo.pages_sampled' )
					->build(),
			)
		);
	}
}
