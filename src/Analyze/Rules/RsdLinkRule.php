<?php
/**
 * Analyzer rule: wp.rsd.exposed.
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
 * Fires when core still prints the RSD link.
 *
 * Severity is info rather than low: this is worth removing, but it is a
 * tidy-up, and inflating it would make the score say something it does not
 * mean.
 */
final class RsdLinkRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.rsd.exposed';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.98;
	}

	/**
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.rsd_link';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.remove_rsd';
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
		return $this->measurable( 'frontend.head_bytes', 90.0, 'bytes' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'The page head advertises the XML-RPC endpoint', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'Every page carries a Really Simple Discovery link pointing at xmlrpc.php.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'RSD exists so that desktop blogging clients can find the XML-RPC endpoint on their own. Almost nobody uses those clients now, and the link is one more thing pointing at an endpoint that attracts brute-force traffic. Removing the link does not disable XML-RPC; it only stops announcing it.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'RSD discovery link', 'debloater' );
	}
}
