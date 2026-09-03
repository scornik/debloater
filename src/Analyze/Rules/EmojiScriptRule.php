<?php
/**
 * Analyzer rule: wp.emojis.loaded.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- This URL is matched, not fetched.
// The whole point of the change is to stop the browser going there; naming the host
// is how the script that goes there is recognised. Nothing here loads anything.

use Debloater\Contracts\Category;
use Debloater\Contracts\Impact;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Severity;

/**
 * Fires when the emoji detection script is still attached to wp_head.
 *
 * Emoji characters keep working after this change: what stops loading is
 * the polyfill that rewrites them into images, not the emoji themselves.
 * The wording says so, because "disable emojis" reads as something much
 * more alarming than what actually happens.
 */
final class EmojiScriptRule extends CoreFeatureRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.emojis.loaded';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.96;
	}

	/**
	 * The fact that must be true.
	 *
	 * @return string
	 */
	protected function fact(): string {
		return 'wp.emojis_enabled';
	}

	/**
	 * The tweak that turns the feature off.
	 *
	 * @return string
	 */
	protected function tweakId(): string {
		return 'core.disable_emojis';
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
		return $this->measurable( 'frontend.requests', 2.0, 'requests' );
	}

	/**
	 * Short title for the finding.
	 *
	 * @return string
	 */
	protected function title(): string {
		return __( 'A compatibility script for emoji loads on every page', 'debloater' );
	}

	/**
	 * What was observed.
	 *
	 * @return string
	 */
	protected function summary(): string {
		return __( 'The emoji detection script, its inline styles and a DNS prefetch to s.w.org load on every page of the site.', 'debloater' );
	}

	/**
	 * Why it matters.
	 *
	 * @return string
	 */
	protected function why(): string {
		return __( 'The script exists to replace emoji characters with images on browsers that cannot display them. Every browser still receiving updates can display them natively, so on almost every site this is a script, a stylesheet and a third-party DNS lookup spent on a problem that no longer exists.', 'debloater' );
	}

	/**
	 * The label shown next to the fact in the evidence.
	 *
	 * @return string
	 */
	protected function evidenceLabel(): string {
		return __( 'Emoji detection script', 'debloater' );
	}
}
