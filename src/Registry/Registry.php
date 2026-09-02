<?php
/**
 * The loaded registry.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Registry;

use RuntimeException;
use WPDebloat\Contracts\Json;

/**
 * An indexed, immutable view of the registry (BUILD-SPEC §7).
 *
 * The registry hash is the reason this class exists rather than a bare array.
 * Every run records the hash of the registry it was planned against, so a plan
 * can be shown to have been produced by a known set of definitions. It is
 * computed from the canonical form of the definitions themselves, not from file
 * mtimes or paths, so reformatting a JSON file does not change it and editing a
 * value does.
 */
final class Registry {

	/**
	 * Definitions keyed by tweak id, in sorted id order.
	 *
	 * @var array<string,TweakDefinition>
	 */
	private readonly array $tweaks;

	/**
	 * Detectors keyed by id, in sorted id order.
	 *
	 * @var array<string,Detector>
	 */
	private readonly array $detectors;

	/**
	 * Compatibility rules keyed by subject, in sorted order.
	 *
	 * @var array<string,CompatRule>
	 */
	private readonly array $compatibility;

	/**
	 * Cached registry hash.
	 *
	 * @var string
	 */
	private readonly string $hash;

	/**
	 * Constructor.
	 *
	 * @param array<int,TweakDefinition> $tweaks        Tweak definitions.
	 * @param array<int,Detector>        $detectors     Detectors.
	 * @param array<int,CompatRule>      $compatibility Compatibility rules.
	 * @throws RuntimeException When an id is duplicated or a reference dangles.
	 */
	public function __construct( array $tweaks = array(), array $detectors = array(), array $compatibility = array() ) {
		$indexed = array();

		foreach ( $tweaks as $definition ) {
			if ( ! $definition instanceof TweakDefinition ) {
				throw new RuntimeException( 'Registry accepts TweakDefinition instances only.' );
			}

			if ( array_key_exists( $definition->id, $indexed ) ) {
				throw new RuntimeException( sprintf( 'Duplicate tweak id "%s" in registry.', $definition->id ) );
			}

			$indexed[ $definition->id ] = $definition;
		}

		ksort( $indexed, SORT_STRING );

		self::assertReferencesResolve( $indexed );

		$by_id = array();

		foreach ( $detectors as $detector ) {
			if ( ! $detector instanceof Detector ) {
				throw new RuntimeException( 'Registry accepts Detector instances only.' );
			}

			if ( array_key_exists( $detector->id, $by_id ) ) {
				throw new RuntimeException( sprintf( 'Duplicate detector id "%s" in registry.', $detector->id ) );
			}

			$by_id[ $detector->id ] = $detector;
		}

		ksort( $by_id, SORT_STRING );

		$by_subject = array();

		foreach ( $compatibility as $rule ) {
			if ( ! $rule instanceof CompatRule ) {
				throw new RuntimeException( 'Registry accepts CompatRule instances only.' );
			}

			if ( array_key_exists( $rule->subject, $by_subject ) ) {
				throw new RuntimeException(
					sprintf( 'Duplicate compatibility subject "%s" in registry.', $rule->subject )
				);
			}

			$by_subject[ $rule->subject ] = $rule;
		}

		ksort( $by_subject, SORT_STRING );

		$this->tweaks        = $indexed;
		$this->detectors     = $by_id;
		$this->compatibility = $by_subject;
		$this->hash          = self::computeHash( $indexed, $by_id, $by_subject );
	}

	/**
	 * All compatibility rules, in sorted subject order.
	 *
	 * @return array<string,CompatRule>
	 */
	public function compatibility(): array {
		return $this->compatibility;
	}

	/**
	 * The compatibility rules whose subject is actually present on this site.
	 *
	 * A dependency declared by a plugin nobody has installed is not a reason to
	 * refuse anything, so presence is checked before the rule counts.
	 *
	 * @param array<string,bool> $detected Detector results, slug to present.
	 * @param string             $theme    Active theme slug.
	 * @param string             $host     Detected host vendor.
	 * @return array<string,CompatRule>
	 */
	public function compatibilityFor( array $detected, string $theme = '', string $host = '' ): array {
		$applicable = array();

		foreach ( $this->compatibility as $subject => $rule ) {
			$slug = $rule->subjectSlug();

			$present = match ( $rule->subjectType() ) {
				'plugin' => ! empty( $detected[ $slug ] ),
				'theme'  => '' !== $theme && $slug === $theme,
				'host'   => '' !== $host && $slug === $host,
				default  => false,
			};

			if ( $present ) {
				$applicable[ $subject ] = $rule;
			}
		}

		return $applicable;
	}

	/**
	 * The present subjects that depend on a given capability.
	 *
	 * @param string             $capability Capability name.
	 * @param array<string,bool> $detected   Detector results, slug to present.
	 * @param string             $theme      Active theme slug.
	 * @param string             $host       Detected host vendor.
	 * @return array<int,CompatRule>
	 */
	public function dependentsOn( string $capability, array $detected, string $theme = '', string $host = '' ): array {
		$dependents = array();

		foreach ( $this->compatibilityFor( $detected, $theme, $host ) as $rule ) {
			if ( $rule->requiresCapability( $capability ) ) {
				$dependents[] = $rule;
			}
		}

		return $dependents;
	}

	/**
	 * All detectors, in sorted id order.
	 *
	 * @return array<string,Detector>
	 */
	public function detectors(): array {
		return $this->detectors;
	}

	/**
	 * A detector by id.
	 *
	 * @param string $detector_id Detector id.
	 * @return Detector
	 * @throws RuntimeException When the id is unknown.
	 */
	public function detector( string $detector_id ): Detector {
		if ( ! array_key_exists( $detector_id, $this->detectors ) ) {
			throw new RuntimeException( sprintf( 'Unknown detector id "%s".', $detector_id ) );
		}

		return $this->detectors[ $detector_id ];
	}

	/**
	 * Whether a tweak id is known.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return bool
	 */
	public function has( string $tweak_id ): bool {
		return array_key_exists( $tweak_id, $this->tweaks );
	}

	/**
	 * A definition by id.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return TweakDefinition
	 * @throws RuntimeException When the id is unknown.
	 */
	public function tweak( string $tweak_id ): TweakDefinition {
		if ( ! array_key_exists( $tweak_id, $this->tweaks ) ) {
			throw new RuntimeException( sprintf( 'Unknown tweak id "%s".', $tweak_id ) );
		}

		return $this->tweaks[ $tweak_id ];
	}

	/**
	 * All definitions, in sorted id order.
	 *
	 * @return array<string,TweakDefinition>
	 */
	public function all(): array {
		return $this->tweaks;
	}

	/**
	 * All known tweak ids, sorted.
	 *
	 * @return array<int,string>
	 */
	public function ids(): array {
		return array_keys( $this->tweaks );
	}

	/**
	 * Number of definitions.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->tweaks );
	}

	/**
	 * A stable hash of the registry contents.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}

	/**
	 * Definitions that conflict with the given tweak, in either direction.
	 *
	 * Conflict is symmetric in meaning but need not be declared on both sides,
	 * so it is resolved in both directions here rather than trusting authors to
	 * keep two files in step.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return array<int,string>
	 */
	public function conflictsFor( string $tweak_id ): array {
		$conflicts = array();

		if ( array_key_exists( $tweak_id, $this->tweaks ) ) {
			foreach ( $this->tweaks[ $tweak_id ]->conflicts as $conflict_id ) {
				$conflicts[ $conflict_id ] = true;
			}
		}

		foreach ( $this->tweaks as $id => $definition ) {
			if ( in_array( $tweak_id, $definition->conflicts, true ) ) {
				$conflicts[ $id ] = true;
			}
		}

		unset( $conflicts[ $tweak_id ] );

		$ids = array_keys( $conflicts );
		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Compute the registry hash from the definitions.
	 *
	 * @param array<string,TweakDefinition> $tweaks        Definitions in sorted order.
	 * @param array<string,Detector>        $detectors     Detectors in sorted order.
	 * @param array<string,CompatRule>      $compatibility Rules in sorted order.
	 * @return string
	 */
	private static function computeHash( array $tweaks, array $detectors, array $compatibility ): string {
		$canonical = array(
			'tweaks'        => array(),
			'detectors'     => array(),
			'compatibility' => array(),
		);

		foreach ( $tweaks as $id => $definition ) {
			$canonical['tweaks'][ $id ] = $definition->toArray();
		}

		foreach ( $detectors as $id => $detector ) {
			$canonical['detectors'][ $id ] = $detector->toArray();
		}

		foreach ( $compatibility as $subject => $rule ) {
			$canonical['compatibility'][ $subject ] = $rule->toArray();
		}

		return hash( 'sha256', Json::canonical( $canonical ) );
	}

	/**
	 * Refuse a registry whose declared relationships point at nothing.
	 *
	 * A conflict or requirement naming a tweak that does not exist is an
	 * authoring error that would otherwise be silently ignored by the resolver,
	 * turning a safety rule into a no-op.
	 *
	 * @param array<string,TweakDefinition> $tweaks Definitions keyed by id.
	 * @return void
	 * @throws RuntimeException When a reference cannot be resolved.
	 */
	private static function assertReferencesResolve( array $tweaks ): void {
		foreach ( $tweaks as $id => $definition ) {
			foreach ( $definition->conflicts as $conflict_id ) {
				if ( ! array_key_exists( $conflict_id, $tweaks ) ) {
					throw new RuntimeException(
						sprintf( 'Tweak "%s" declares a conflict with unknown tweak "%s".', $id, $conflict_id )
					);
				}
			}

			foreach ( $definition->requiredTweakIds() as $required_id ) {
				if ( ! array_key_exists( $required_id, $tweaks ) ) {
					throw new RuntimeException(
						sprintf( 'Tweak "%s" requires unknown tweak "%s".', $id, $required_id )
					);
				}
			}
		}
	}
}
