<?php
/**
 * Analyzer rule: wp.dashicons.frontend.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\Impact;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Severity;

/**
 * Fires when dashicons is loading on the front end.
 *
 * This rule is often not evaluated at all: the fact it needs can only be
 * observed on a front-end request, and a scan run from the admin does not
 * produce it (see CoreFeatureScanner). supports() returns false in that
 * case, which the analyzer reports as "not evaluated" rather than as
 * "nothing to do".
 */
final class DashiconsFrontendRule extends CoreFeatureRule {

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
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.dashicons_frontend';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.disable_dashicons_guests';
	}

	/**
	 * Category this finding belongs to.
	 *
	 * @return Category
	 */
	protected function category(): Category {
		return Category::ASSETS;
	}

	/**
	 * How much this matters.
	 *
	 * @return Severity
	 */
	protected function severity(): Severity {
		return Severity::LOW;
	}

	/**
	 * How dangerous the change is.
	 *
	 * @return Risk
	 */
	protected function risk(): Risk {
		return Risk::MEDIUM;
	}

	/**
	 * The estimated impact.
	 *
	 * @return Impact
	 */
	protected function impact(): Impact {
		return $this->measurable( 'frontend.requests', 1.0, 'requests' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'The admin icon font loads for visitors', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'Dashicons is being loaded on the front end, where logged-out visitors download the whole icon font.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Dashicons is WordPress\'s admin icon font. Core loads it on the front end only for the admin bar, which logged-out visitors never see — but themes and plugins often enqueue it for a menu toggle or a search icon, and then every visitor downloads a font for two glyphs.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'Dashicons on the front end', 'debloater' );
	}
}
