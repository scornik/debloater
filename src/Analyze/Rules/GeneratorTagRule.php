<?php
/**
 * Analyzer rule: wp.generator.exposed.
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
 * Fires when core still prints the meta generator tag.
 *
 * This is the one finding in the set with no downside worth stating: nothing
 * legitimate reads the tag, and no feature depends on it.
 */
final class GeneratorTagRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.generator.exposed';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.99;
	}

	/**
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.generator_tag';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.remove_generator';
	}

	/**
	 * Category this finding belongs to.
	 *
	 * @return Category
	 */
	protected function category(): Category {
		return Category::WORDPRESS;
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
		return Risk::SAFE;
	}

	/**
	 * The estimated impact.
	 *
	 * @return Impact
	 */
	protected function impact(): Impact {
		return $this->measurable( 'frontend.head_bytes', 60.0, 'bytes' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'The exact WordPress version is published on every page', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'Every page and every feed carries a meta tag naming the WordPress version this site runs.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Knowing the version does not let anyone in, but it does let an automated scanner skip straight to the exploits that work against it. Removing the tag costs nothing and removes the site from that shortlist.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'Generator tag', 'debloater' );
	}
}
