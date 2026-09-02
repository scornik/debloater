<?php
/**
 * Analyzer rule: wp.self_pingbacks.enabled.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze\Rules;

use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Impact;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;

/**
 * Fires when nothing is listening on pre_ping.
 *
 * Core has no setting for this, so the only honest observation is whether
 * anything is filtering the ping list. A site where something already is
 * produces no finding, which is the correct outcome even if that filter
 * belongs to another plugin.
 */
final class SelfPingbackRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.self_pingbacks.enabled';
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
		return 'wp.self_pingbacks';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.disable_self_pingbacks';
	}

	/**
	 * Category this finding belongs to.
	 *
	 * @return Category
	 */
	protected function category(): Category {
		return Category::MAINTENANCE;
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
		return $this->estimated( 'self_pingbacks_per_publish', 1.0, 'requests' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'The site pings itself whenever a post links internally', 'wp-debloat' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'Nothing is filtering the ping list, so linking from one post to another on this site makes WordPress send itself a pingback and create a comment.', 'wp-debloat' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'Each internal link costs an HTTP request from the site to itself at publish time and leaves a pingback comment on the linked post that has to be moderated or deleted. Pingbacks to other sites are a different thing and are unaffected.', 'wp-debloat' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'Self-pingbacks', 'wp-debloat' );
	}
}
