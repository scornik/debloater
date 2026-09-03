<?php
/**
 * Says where else a setting lives.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze;

use Debloater\Contracts\Decision;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Registry\Registry;

/**
 * Names the other tool that offers the same setting (BUILD-SPEC §17 Phase 11).
 *
 * Some sites arrive with an optimizer already installed: the host's own plugin,
 * or a cache plugin whose settings screen has switches Debloater also offers.
 * When a finding lands on that ground, the user is told so and told where.
 *
 * **What this deliberately does not do, and why.** Phase 11 as written asks for
 * these findings to be downgraded to `info` with the reason "already handled by
 * host". Building it revealed that the reason would be false exactly when it was
 * shown. A finding fires because the scanner observed the thing happening —
 * `wp.emojis_enabled` is true because the emoji script is on the page. If the
 * other optimizer had handled it, there would be no finding to downgrade. So
 * "already handled" is a claim the facts contradict at the moment it is made,
 * and marking a real cost as `info` would understate a cost the site is paying.
 *
 * What is true, and worth saying, is narrower: *there is another place on this
 * site where this can be changed*. That does not depend on which way the other
 * tool's switch is currently set, and it is the thing a person needs in order to
 * decide. So the finding keeps its severity, keeps its recommendation, and gains
 * a sentence. See docs/DECISIONS.md D-0028.
 *
 * The reverse case — an optimizer Debloater would actively fight with, where
 * leaving it alone is right regardless of observation — belongs in the
 * compatibility registry as a refusal, which already exists and already carries
 * its reason.
 */
final class HostOptimizerRules {

	/**
	 * Facts from the scan.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Finding id to the optimizer names that offer a setting for it.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $owners;

	/**
	 * Where each optimizer keeps its settings, keyed by display name.
	 *
	 * Read from the registry rather than from the facts. Where a third-party
	 * product keeps a setting is knowledge about that product, not an
	 * observation about this site, and a fact set carrying explanatory prose
	 * would stop being the diffable record of what was seen.
	 *
	 * @var array<string,string>
	 */
	private array $notes;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Registry holding the optimizer table.
	 * @param FactSet  $facts    Facts from the scan.
	 */
	public function __construct( Registry $registry, FactSet $facts ) {
		$this->facts  = $facts;
		$this->owners = array();
		$this->notes  = array();

		$known = $registry->hostOptimizers();

		$optimizers = $facts->value( 'plugins.host_optimizers', array() );

		if ( ! is_array( $optimizers ) ) {
			return;
		}

		foreach ( $optimizers as $optimizer ) {
			if ( ! is_array( $optimizer ) ) {
				continue;
			}

			$id      = (string) ( $optimizer['id'] ?? '' );
			$name    = (string) ( $optimizer['name'] ?? '' );
			$finding = (string) ( $optimizer['finding'] ?? '' );

			if ( '' === $name || '' === $finding ) {
				continue;
			}

			if ( ! in_array( $name, $this->owners[ $finding ] ?? array(), true ) ) {
				$this->owners[ $finding ][] = $name;
			}

			$this->notes[ $name ] = isset( $known[ $id ] ) ? $known[ $id ]->notes : '';
		}

		foreach ( $this->owners as $finding => $names ) {
			sort( $names, SORT_STRING );

			$this->owners[ $finding ] = $names;
		}
	}

	/**
	 * Add the sentence naming the other place this can be changed.
	 *
	 * A finding that proposes nothing is left alone: there is no choice to
	 * inform. So is one nothing covers.
	 *
	 * @param Finding $finding Finding to consider.
	 * @return Finding
	 */
	public function apply( Finding $finding ): Finding {
		if ( Decision::INFO === $finding->decision ) {
			return $finding;
		}

		$owners = $this->owners( $finding->id );

		if ( array() === $owners ) {
			return $finding;
		}

		return $finding->withAddedReasoning( $this->sentence( $owners ) );
	}

	/**
	 * Whether anything present offers its own setting for a finding.
	 *
	 * @param string $finding_id Finding id.
	 * @return bool
	 */
	public function covers( string $finding_id ): bool {
		return array() !== $this->owners( $finding_id );
	}

	/**
	 * The facts this was built from.
	 *
	 * @return FactSet
	 */
	public function facts(): FactSet {
		return $this->facts;
	}

	/**
	 * Optimizer names that offer their own setting for a finding.
	 *
	 * @param string $finding_id Finding id.
	 * @return array<int,string>
	 */
	private function owners( string $finding_id ): array {
		return $this->owners[ $finding_id ] ?? array();
	}

	/**
	 * The sentence appended to the finding's reasoning.
	 *
	 * @param array<int,string> $owners Optimizer names.
	 * @return string
	 */
	private function sentence( array $owners ): string {
		$sentence = sprintf(
			/* translators: %s: comma-separated names of optimization plugins already on the site. */
			_n(
				'%s is also on this site and has its own setting for this, so you can change it there instead — one switch is easier to remember than two.',
				'These are also on this site and have their own settings for this, so you can change it there instead: %s. One switch is easier to remember than two.',
				count( $owners ),
				'debloater'
			),
			implode( ', ', $owners )
		);

		$notes = array();

		foreach ( $owners as $name ) {
			if ( '' !== ( $this->notes[ $name ] ?? '' ) ) {
				$notes[] = $this->notes[ $name ];
			}
		}

		return array() === $notes ? $sentence : $sentence . ' ' . $notes[0];
	}
}
