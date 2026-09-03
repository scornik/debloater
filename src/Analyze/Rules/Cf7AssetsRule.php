<?php
/**
 * Analyzer rule: assets.cf7.everywhere.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze\Rules;

use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Severity;

/**
 * Contact Form 7 loads its assets on pages that have no form on them. Info
 * only.
 *
 * This is the example BUILD-SPEC uses for the whole assets phase, and it is a
 * good one: CF7 enqueues its script and stylesheet on every page of the site by
 * default, whether or not there is a form to submit. On a site with one contact
 * page that is one useful page and every other page paying for it.
 *
 * It proposes nothing, and Phase 13 adds no unloading tweaks at all. Two
 * reasons, and the second is the real one.
 *
 * First, CF7 already has a supported way to do this — `WPCF7_LOAD_JS` and
 * `WPCF7_LOAD_CSS` — so the useful thing is to say what is happening, not to
 * hook around a plugin that has an answer.
 *
 * Second, and more importantly: this reads a **sample**. A handful of pages were
 * fetched, not the site. "No form on the four pages we looked at" is not "no
 * form anywhere", and a change made on that basis would break the contact page
 * of any site whose contact page was not in the sample. So the finding says how
 * many pages it looked at, in those words, and stops.
 */
final class Cf7AssetsRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'assets.cf7.everywhere';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * Not higher, and the ceiling is the sample rather than the parsing. What
	 * was seen was seen exactly; whether it holds for the pages nobody fetched
	 * is a different question, and that is the uncertainty this number is about.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.75;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'assets.cf7_asset_pages', 'assets.cf7_form_pages', 'assets.pages_sampled' );
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

		$with_assets = (int) $facts->value( 'assets.cf7_asset_pages', 0 );
		$with_forms  = (int) $facts->value( 'assets.cf7_form_pages', 0 );
		$sampled     = (int) $facts->value( 'assets.pages_sampled', 0 );

		if ( $sampled < 1 || $with_assets < 1 || $with_assets <= $with_forms ) {
			return null;
		}

		return $this->inform(
			array(
				'category' => Category::ASSETS,
				'severity' => Severity::INFO,
				'title'    => sprintf(
					/* translators: 1: pages that loaded the assets, 2: pages that had a form. */
					__( 'Contact Form 7 assets loaded on %1$d pages, forms on %2$d', 'wp-debloat' ),
					$with_assets,
					$with_forms
				),
				'summary'  => sprintf(
					/* translators: 1: number of pages sampled, 2: pages that loaded the assets, 3: pages with a form. */
					_n(
						'Of %1$d page sampled, %2$d loaded Contact Form 7\'s script and stylesheet and %3$d actually contained a form.',
						'Of %1$d pages sampled, %2$d loaded Contact Form 7\'s script and stylesheet and %3$d actually contained a form.',
						$sampled,
						'wp-debloat'
					),
					$sampled,
					$with_assets,
					$with_forms
				),
				'why'      => __(
					'Contact Form 7 enqueues its script and stylesheet on every page by default, whether or not the page has a form on it. Contact Form 7 has its own supported setting for this — the WPCF7_LOAD_JS and WPCF7_LOAD_CSS constants — which is a better place to change it than anything WP Debloat could hook around it. Read the numbers as what they are: this looked at a sample of pages, not at your whole site, and a page nobody sampled was not measured.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Pages that loaded the assets', 'wp-debloat' ), 'assets.cf7_asset_pages' )
					->fact( __( 'Pages that contained a form', 'wp-debloat' ), 'assets.cf7_form_pages' )
					->fact( __( 'Pages sampled', 'wp-debloat' ), 'assets.pages_sampled' )
					->optional( __( 'Post types sampled', 'wp-debloat' ), 'assets.post_types' )
					->build(),
			)
		);
	}
}
