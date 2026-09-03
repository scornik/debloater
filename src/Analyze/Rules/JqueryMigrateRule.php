<?php
/**
 * Analyzer rule: wp.jquery_migrate.loaded.
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
 * Fires when the jQuery bundle still depends on jquery-migrate.
 *
 * Rated medium risk, so it never reaches "Fix Safe Issues" (BUILD-SPEC
 * §7.4). The failure mode is the reason: nothing errors, the page simply
 * stops doing something, and the person who applied the change is not
 * necessarily the person who notices.
 */
final class JqueryMigrateRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.jquery_migrate.loaded';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.82;
	}

	/**
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.jquery_migrate';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.remove_jquery_migrate';
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
		return __( 'jQuery Migrate loads alongside jQuery on every page', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'The registered jQuery bundle still includes jquery-migrate, so both scripts load wherever jQuery does.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Migrate exists to keep code written for jQuery 1.x working on modern jQuery. A site whose theme and plugins are current does not need it. A site where something still uses the old APIs breaks quietly without it: no error page, just JavaScript that stops running.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'jQuery Migrate in the jQuery bundle', 'debloater' );
	}
}
