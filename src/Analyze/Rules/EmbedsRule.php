<?php
/**
 * Analyzer rule: wp.embeds.enabled.
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
 * Fires when oEmbed discovery links are still attached to wp_head.
 *
 * The distinction between providing and consuming embeds is the whole
 * finding: users hear "disable embeds" and reasonably assume their
 * YouTube videos will stop working. They will not.
 */
final class EmbedsRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.embeds.enabled';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.94;
	}

	/**
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.embeds_enabled';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.disable_embeds';
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
		return $this->measurable( 'frontend.requests', 1.0, 'requests' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'The site offers its own posts for embedding on other sites', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'oEmbed discovery tags are in the page head, and the wp-embed.js script loads with them.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'This is the half of oEmbed that lets *other* sites embed *your* posts as a live preview card. Embedding other people\'s content in your own posts is separate and is unaffected. On a site nobody embeds, it is a script and two head tags per page for a feature never used.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'oEmbed discovery', 'debloater' );
	}
}
