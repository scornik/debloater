<?php
/**
 * Analyzer rule: wp.shortlink.exposed.
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
 * Fires when core still emits the shortlink tag and header.
 *
 * Both go together. Removing only the head tag would leave the header
 * advertising the same URL, which is the thing worth not advertising.
 */
final class ShortlinkRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.shortlink.exposed';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.97;
	}

	/**
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.shortlink';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.remove_shortlink';
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
		return Severity::INFO;
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
		return $this->measurable( 'frontend.head_bytes', 120.0, 'bytes' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'Every page publishes its numeric URL as well as its real one', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'WordPress emits a rel="shortlink" tag in the head and a matching Link: HTTP header, both giving the ?p=<id> form of the page.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'The shortlink is a leftover from an era of character limits. It exposes the internal post id of every page, adds a head tag and an HTTP header to every request, and almost nothing consumes it.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'Shortlink tag and header', 'debloater' );
	}
}
