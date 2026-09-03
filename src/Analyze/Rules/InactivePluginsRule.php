<?php
/**
 * Analyzer rule: plugins.inactive_present.
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
 * Reports deactivated plugins that are still installed. Info only.
 *
 * A deactivated plugin costs nothing at runtime — WordPress does not load it —
 * so there is no performance case here at all, and pretending otherwise would be
 * the kind of claim this product exists not to make.
 *
 * What there is, is a maintenance case: the files are still on disk, still
 * reachable over HTTP in some configurations, and still receiving security
 * advisories that nobody is watching for a plugin they stopped using.
 *
 * So this proposes nothing. Deleting a plugin is a decision only the person who
 * installed it can make, it is not reversible from here, and there is no
 * plausible reading of "safe" under which Debloater should do it. The finding
 * says what is there and stops.
 */
final class InactivePluginsRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'plugins.inactive_present';
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
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'plugins.inactive' );
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

		$inactive = $facts->value( 'plugins.inactive', array() );

		if ( ! is_array( $inactive ) || array() === $inactive ) {
			return null;
		}

		$count = count( $inactive );

		return $this->inform(
			array(
				'category' => Category::PLUGINS,
				'severity' => Severity::INFO,
				'title'    => sprintf(
					/* translators: %d: number of deactivated plugins. */
					_n( '%d deactivated plugin is still installed', '%d deactivated plugins are still installed', $count, 'debloater' ),
					$count
				),
				'summary'  => sprintf(
					/* translators: %s: comma-separated list of plugin files. */
					__( 'Installed but not active: %s.', 'debloater' ),
					implode( ', ', array_slice( $inactive, 0, 10 ) )
				),
				'why'      => __(
					'A deactivated plugin is not loaded, so it costs nothing on any request. Its files are still on the server, though, and still get security advisories nobody is watching for a plugin that stopped being used. Whether to delete it is a decision only you can make, and it is not one this plugin will make for you.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Deactivated plugins', 'debloater' ), 'plugins.inactive' )
					->optional( __( 'Active plugins', 'debloater' ), 'plugins.active' )
					->build(),
			)
		);
	}
}
