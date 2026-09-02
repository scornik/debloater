<?php
/**
 * Works out what on this site depends on what.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Recommend;

use WPDebloat\Contracts\FactSet;
use WPDebloat\Registry\CompatRule;
use WPDebloat\Registry\Registry;

/**
 * Resolves the compatibility registry against one site (BUILD-SPEC §7.2).
 *
 * The registry says what components depend on in general. This says what is
 * true here: which of those components are actually present, and therefore
 * which capabilities are genuinely spoken for.
 *
 * Presence is the whole point. A dependency declared by a plugin nobody has
 * installed is not a reason to refuse anything, and treating it as one would
 * make WP Debloat progressively more timid as the registry grew — the opposite
 * of what a growing registry should do.
 */
final class CompatibilityResolver {

	/**
	 * The registry holding the rules.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Facts from the scan.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Registry with compatibility rules.
	 * @param FactSet  $facts    Facts from the scan.
	 */
	public function __construct( Registry $registry, FactSet $facts ) {
		$this->registry = $registry;
		$this->facts    = $facts;
	}

	/**
	 * The rules whose subject is present on this site.
	 *
	 * @return array<string,CompatRule>
	 */
	public function applicable(): array {
		return $this->registry->compatibilityFor( $this->detected(), $this->theme(), $this->host() );
	}

	/**
	 * Everything present that depends on a capability.
	 *
	 * @param string $capability Capability name.
	 * @return array<int,CompatRule>
	 */
	public function dependentsOn( string $capability ): array {
		return $this->registry->dependentsOn( $capability, $this->detected(), $this->theme(), $this->host() );
	}

	/**
	 * How many present components depend on a capability.
	 *
	 * @param string $capability Capability name.
	 * @return int
	 */
	public function dependentCount( string $capability ): int {
		return count( $this->dependentsOn( $capability ) );
	}

	/**
	 * Whether anything present depends on a capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public function isSpokenFor( string $capability ): bool {
		return array() !== $this->dependentsOn( $capability );
	}

	/**
	 * Every capability something on this site depends on.
	 *
	 * @return array<int,string>
	 */
	public function capabilitiesInUse(): array {
		$capabilities = array();

		foreach ( $this->applicable() as $rule ) {
			foreach ( $rule->requires as $capability ) {
				$capabilities[ $capability ] = true;
			}
		}

		$names = array_keys( $capabilities );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * The slugs depending on a capability, for a user-facing explanation.
	 *
	 * @param string $capability Capability name.
	 * @return array<int,string>
	 */
	public function dependentNames( string $capability ): array {
		$names = array();

		foreach ( $this->dependentsOn( $capability ) as $rule ) {
			$names[] = $rule->subjectSlug();
		}

		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Components present on this site with no compatibility rule at all.
	 *
	 * Reported rather than assumed harmless: a plugin nobody has written a rule
	 * for is a plugin whose dependencies we do not know, which is a reason for
	 * less confidence and not for more.
	 *
	 * @return array<int,string>
	 */
	public function unmappedComponents(): array {
		$known = array();

		foreach ( $this->applicable() as $rule ) {
			$known[ $rule->subjectSlug() ] = true;
		}

		$unmapped = array();

		foreach ( $this->detected() as $slug => $present ) {
			if ( $present && ! isset( $known[ $slug ] ) ) {
				$unmapped[] = $slug;
			}
		}

		sort( $unmapped, SORT_STRING );

		return $unmapped;
	}

	/**
	 * Detector results from the scan.
	 *
	 * @return array<string,bool>
	 */
	private function detected(): array {
		$detected = $this->facts->value( 'plugins.detected', array() );

		if ( ! is_array( $detected ) ) {
			return array();
		}

		$clean = array();

		foreach ( $detected as $slug => $present ) {
			if ( is_string( $slug ) ) {
				$clean[ $slug ] = (bool) $present;
			}
		}

		return $clean;
	}

	/**
	 * The active theme slug.
	 *
	 * @return string
	 */
	private function theme(): string {
		return (string) $this->facts->value( 'theme.active', '' );
	}

	/**
	 * The detected host vendor.
	 *
	 * @return string
	 */
	private function host(): string {
		return (string) $this->facts->value( 'env.host_vendor', '' );
	}
}
