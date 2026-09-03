<?php
/**
 * Analyzer rule: plugins.host_optimizer_detected.
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
 * Reports an optimization layer that offers settings of its own for some of the
 * same things. Info only.
 *
 * Some sites arrive with an optimizer already installed — the host's own plugin,
 * or a cache plugin whose settings screen has switches Debloater also offers.
 * Knowing that is useful: it means there is more than one place a setting can be
 * changed, and one switch is easier to remember than two.
 *
 * This rule says what is there. The individual findings that land on the same
 * ground gain a sentence naming it, added by
 * {@see \Debloater\Analyze\HostOptimizerRules}.
 *
 * What is deliberately *not* claimed: that the other tool has the setting turned
 * on, or that it has already dealt with anything. Debloater cannot read another
 * plugin's settings and will not pretend to — and where a finding fired at all,
 * the scan has just observed that nothing dealt with it (docs/DECISIONS.md
 * D-0028).
 */
final class HostOptimizerRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'plugins.host_optimizer_detected';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * The signal is a constant the host sets or a detector match, so presence is
	 * about as certain as anything here gets.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.97;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'plugins.host_optimizers' );
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

		$optimizers = $facts->value( 'plugins.host_optimizers', array() );

		if ( ! is_array( $optimizers ) || array() === $optimizers ) {
			return null;
		}

		// The fact is one row per optimizer and finding, so an optimizer with
		// two settings appears twice. What the user needs is the tools, once
		// each.
		$names = array();

		foreach ( $optimizers as $optimizer ) {
			if ( ! is_array( $optimizer ) || '' === (string) ( $optimizer['name'] ?? '' ) ) {
				continue;
			}

			$names[ (string) $optimizer['name'] ] = true;
		}

		if ( array() === $names ) {
			return null;
		}

		ksort( $names, SORT_STRING );

		$names = array_keys( $names );

		return $this->inform(
			array(
				'category' => Category::PLUGINS,
				'severity' => Severity::INFO,
				'title'    => sprintf(
					/* translators: %s: comma-separated optimizer names. */
					_n(
						'%s has settings of its own for some of this',
						'These have settings of their own for some of this: %s',
						count( $names ),
						'debloater'
					),
					implode( ', ', $names )
				),
				'summary'  => sprintf(
					/* translators: %s: comma-separated optimizer names. */
					_n(
						'%s is on this site and offers some of the same settings Debloater does. Each finding it overlaps with says so, and says where to find it.',
						'These are on this site and offer some of the same settings Debloater does: %s. Each finding they overlap with says so, and says where to find it.',
						count( $names ),
						'debloater'
					),
					implode( ', ', $names )
				),
				'why'      => __(
					'Where something else on this site offers a setting for the same thing, Debloater says so on the finding itself, so you can choose which one to use rather than ending up with both. This does not mean the other tool has that setting turned on: Debloater cannot read another plugin\'s settings and will not guess, and where you are seeing a finding at all, the scan has just observed that whatever it is about is still happening.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Other optimizers on this site', 'debloater' ), 'plugins.host_optimizers' )
					->optional( __( 'Host', 'debloater' ), 'env.host_vendor' )
					->build(),
			)
		);
	}
}
