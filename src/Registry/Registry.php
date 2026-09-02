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
	 * Cached registry hash.
	 *
	 * @var string
	 */
	private readonly string $hash;

	/**
	 * Constructor.
	 *
	 * @param array<int,TweakDefinition> $tweaks Tweak definitions.
	 * @throws RuntimeException When an id is duplicated or a reference dangles.
	 */
	public function __construct( array $tweaks = array() ) {
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

		$this->tweaks = $indexed;
		$this->hash   = self::computeHash( $indexed );
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
	 * @param array<string,TweakDefinition> $tweaks Definitions in sorted order.
	 * @return string
	 */
	private static function computeHash( array $tweaks ): string {
		$canonical = array();

		foreach ( $tweaks as $id => $definition ) {
			$canonical[ $id ] = $definition->toArray();
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
