<?php
/**
 * Analyzer rule: wp.dashicons.frontend.
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
 * Fires when the pages the scan fetched as a logged-out visitor load dashicons.
 *
 * Decided from `assets.styles`: the stylesheets on the sampled pages, fetched
 * over HTTP with no cookie, which is exactly the visitor
 * `core.disable_dashicons_guests` changes things for. When the tweak is in
 * effect those pages stop carrying `dashicons`, and the finding clears on the
 * next scan.
 *
 * Until 0.5.0 it read `wp.dashicons_frontend`, which asked the request the scan
 * ran in whether any registered style depended on dashicons. A scan runs over
 * REST or WP-CLI, where core has registered a dozen such styles — the admin
 * bar, thickbox, the pointer — whatever any visitor downloads. The fact was
 * true on every site, the finding fired on every site, and removing dashicons
 * for visitors could not change it (`D-0079`).
 *
 * A site that cannot fetch its own pages gets no asset facts, and this rule is
 * reported as not evaluated rather than guessing either way.
 */
final class DashiconsFrontendRule extends AbstractRule {

	/**
	 * The handle WordPress gives the icon font's stylesheet.
	 */
	private const HANDLE = 'dashicons';

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.dashicons.frontend';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.80;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'assets.styles', 'assets.pages_sampled' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) ) {
			return null;
		}

		$sampled = (int) $facts->value( 'assets.pages_sampled', 0 );
		$pages   = $this->pagesLoading( $facts );

		if ( $sampled < 1 || $pages < 1 ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::ASSETS,
				'severity' => Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'title'    => __( 'The admin icon font loads for visitors', 'hakeemify-debloater' ),
				'summary'  => sprintf(
					/* translators: 1: pages that loaded dashicons, 2: pages sampled. */
					_n(
						'Dashicons loaded on %1$d of the %2$d page fetched as a logged-out visitor.',
						'Dashicons loaded on %1$d of the %2$d pages fetched as a logged-out visitor.',
						$sampled,
						'hakeemify-debloater'
					),
					$pages,
					$sampled
				),
				'why'      => __( 'Dashicons is WordPress\'s admin icon font. Core loads it on the front end only for the admin bar, which logged-out visitors never see — but themes and plugins often enqueue it for a menu toggle or a search icon, and then every visitor downloads a font for two glyphs. This was measured on a sample of pages, not on every page of the site.', 'hakeemify-debloater' ),
				'evidence' => $this->evidence( $facts )
					->formatted(
						__( 'Sampled pages loading dashicons', 'hakeemify-debloater' ),
						sprintf( '%d / %d', $pages, $sampled ),
						'assets.styles'
					)
					->fact( __( 'Pages sampled', 'hakeemify-debloater' ), 'assets.pages_sampled' )
					->build(),
				'impact'   => $this->measurable( 'frontend.requests', 1.0, 'requests' ),
				'tweak_id' => 'core.disable_dashicons_guests',
			)
		);
	}

	/**
	 * How many sampled pages loaded the dashicons stylesheet.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return int
	 */
	private function pagesLoading( FactSet $facts ): int {
		$styles = $facts->value( 'assets.styles', array() );

		foreach ( is_array( $styles ) ? $styles : array() as $style ) {
			if ( is_array( $style ) && self::HANDLE === ( $style['handle'] ?? null ) ) {
				return (int) ( $style['pages'] ?? 0 );
			}
		}

		return 0;
	}
}
