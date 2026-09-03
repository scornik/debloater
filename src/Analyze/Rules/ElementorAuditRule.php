<?php
/**
 * Analyzer rule: elementor.widgets.audit.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Severity;

/**
 * How many Elementor widgets are registered, and how many the site is seen to
 * use. Info only.
 *
 * The number this produces is arresting — a site with six addon packs commonly
 * has a hundred and fifty widgets available and thirty in use — and that is
 * exactly why the wording matters more here than anywhere else in the product.
 *
 * **It says "potentially unused", and that is not hedging.** The count of widgets
 * in use comes from `_elementor_data`, which is where a design records the
 * widgets it places directly. Four things put a widget on a page without
 * appearing there as itself:
 *
 * - a **dynamic tag**, which resolves at render time;
 * - a **shortcode widget**, whose contents are somebody else's plugin;
 * - a **theme-builder template**, which is a document nothing links to;
 * - a **custom code or HTML widget**, which can contain anything.
 *
 * Each of those is recorded as a fact by the scanner, and each one lowers this
 * finding's confidence, because each one means the "in use" figure is a floor
 * rather than a total.
 *
 * **It never proposes disabling anything.** Elementor has no supported way to
 * unregister a third party's widget, and doing it unsupported would break the
 * editor for every page already built with one. §17 Phase 14 says never, and it
 * is right.
 */
final class ElementorAuditRule extends AbstractRule {

	/**
	 * Confidence when nothing hides a widget from the count.
	 */
	public const CONFIDENCE_CLEAN = 0.8;

	/**
	 * How much each hiding signal takes off.
	 */
	public const PENALTY_PER_SIGNAL = 0.15;

	/**
	 * The lowest this will go.
	 *
	 * A floor rather than zero: the counts themselves are exact, and what is
	 * uncertain is only what they leave out.
	 */
	public const CONFIDENCE_FLOOR = 0.3;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'elementor.widgets.audit';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return self::CONFIDENCE_CLEAN;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		// Only presence. A site without Elementor is a site this rule *did*
		// evaluate — it looked, and there was nothing to audit. The other facts
		// are absent as a consequence of that answer, not because nobody looked,
		// and requiring them would report every ordinary site as unexamined.
		return array( 'elementor.present' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( 'elementor.present' ) ) {
			return null;
		}

		// Elementor is here but the catalogue could not be read — a version too
		// old for the API, or an addon that throws when instantiated. Absent is
		// not empty, and an audit built on an unread catalogue would report
		// every widget on the site as unaccounted for.
		if ( null === $facts->value( 'elementor.widgets_available' ) ) {
			return null;
		}

		$available = (int) $facts->value( 'elementor.widgets_available', 0 );
		$in_use    = $facts->value( 'elementor.widgets_in_use', array() );
		$packs     = $facts->value( 'elementor.packs', array() );

		if ( $available < 1 || ! is_array( $in_use ) || ! is_array( $packs ) ) {
			return null;
		}

		$used      = $this->countRegistered( $in_use, $facts );
		$potential = max( 0, $available - $used );

		return $this->inform(
			array(
				'category'   => Category::PLUGINS,
				'severity'   => Severity::INFO,
				'confidence' => $this->confidence( $facts ),
				'title'      => sprintf(
					/* translators: 1: number of addon packs, 2: widgets available, 3: widgets seen in use, 4: widgets potentially unused. */
					__( '%1$d addon packs, %2$d widgets available, %3$d detected in use, %4$d potentially unused', 'debloater' ),
					count( $packs ),
					$available,
					$used,
					$potential
				),
				'summary'    => $this->summary( $packs ),
				'why'        => $this->why( $facts ),
				'evidence'   => $this->evidence( $facts )
					->fact( __( 'Widgets registered, by plugin', 'debloater' ), 'elementor.packs' )
					->fact( __( 'Widget types found in your designs', 'debloater' ), 'elementor.widgets_in_use' )
					->optional( __( 'Designs read', 'debloater' ), 'elementor.documents' )
					->optional( __( 'Templates', 'debloater' ), 'elementor.templates' )
					->optional( __( 'Dynamic tags present', 'debloater' ), 'elementor.dynamic_tags' )
					->optional( __( 'Shortcode widgets present', 'debloater' ), 'elementor.shortcodes' )
					->optional( __( 'Custom code widgets present', 'debloater' ), 'elementor.custom_code' )
					->build(),
			)
		);
	}

	/**
	 * How many of the widgets in use are ones the catalogue knows about.
	 *
	 * A design can name a widget type whose plugin has since been removed. It
	 * is genuinely in the design and genuinely not registered, so counting it
	 * against the catalogue would make the arithmetic lie in the reassuring
	 * direction.
	 *
	 * @param array<int,string> $in_use Widget types found in stored designs.
	 * @param FactSet           $facts  Facts from the scan.
	 * @return int
	 */
	private function countRegistered( array $in_use, FactSet $facts ): int {
		$registered = array();

		foreach ( $facts->value( 'elementor.widgets', array() ) as $widget ) {
			if ( is_array( $widget ) && isset( $widget['name'] ) ) {
				$registered[ (string) $widget['name'] ] = true;
			}
		}

		if ( array() === $registered ) {
			return count( $in_use );
		}

		$count = 0;

		foreach ( $in_use as $type ) {
			if ( isset( $registered[ (string) $type ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Which plugin registered how many.
	 *
	 * @param array<int,mixed> $packs Pack rows.
	 * @return string
	 */
	private function summary( array $packs ): string {
		$parts = array();

		foreach ( $packs as $pack ) {
			if ( ! is_array( $pack ) ) {
				continue;
			}

			$parts[] = sprintf(
				/* translators: 1: plugin or component name, 2: how many widgets it registers. */
				__( '%1$s (%2$d)', 'debloater' ),
				(string) ( $pack['source'] ?? '' ),
				(int) ( $pack['count'] ?? 0 )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Confidence, lowered once for each thing that hides a widget from the
	 * count.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return float
	 */
	private function confidence( FactSet $facts ): float {
		$signals = 0;

		foreach ( array( 'elementor.dynamic_tags', 'elementor.shortcodes', 'elementor.custom_code' ) as $fact ) {
			if ( true === $facts->value( $fact ) ) {
				++$signals;
			}
		}

		if ( (int) $facts->value( 'elementor.templates', 0 ) > 0 ) {
			++$signals;
		}

		return max(
			self::CONFIDENCE_FLOOR,
			self::CONFIDENCE_CLEAN - ( $signals * self::PENALTY_PER_SIGNAL )
		);
	}

	/**
	 * What the numbers mean, and what they do not.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return string
	 */
	private function why( FactSet $facts ): string {
		$why = __(
			'Every registered widget is code Elementor loads, whether or not anything on your site uses it — which is what makes an addon pack installed for one slider expensive. The word here is "potentially": the widgets counted as in use are the ones your saved designs name directly, and there is no supported way to unregister somebody else\'s widget anyway, so Debloater will not offer to. What this is for is deciding whether a pack is still worth having.',
			'debloater'
		);

		$caveats = array();

		if ( true === $facts->value( 'elementor.dynamic_tags' ) ) {
			$caveats[] = __( 'dynamic tags, which resolve when a page is rendered', 'debloater' );
		}

		if ( true === $facts->value( 'elementor.shortcodes' ) ) {
			$caveats[] = __( 'shortcode widgets, whose contents belong to another plugin', 'debloater' );
		}

		if ( true === $facts->value( 'elementor.custom_code' ) ) {
			$caveats[] = __( 'HTML or custom code widgets, which can contain anything', 'debloater' );
		}

		if ( (int) $facts->value( 'elementor.templates', 0 ) > 0 ) {
			$caveats[] = __( 'theme-builder templates, which are documents nothing links to directly', 'debloater' );
		}

		if ( array() === $caveats ) {
			return $why;
		}

		return $why . ' ' . sprintf(
			/* translators: %s: comma-separated list of things that hide a widget from the count. */
			__( 'This site also has %s, so treat the "in use" figure as a floor rather than a total. That is why this finding is not more confident than it is.', 'debloater' ),
			implode( ', ', $caveats )
		);
	}
}
