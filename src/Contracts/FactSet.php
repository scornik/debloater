<?php
/**
 * The complete set of facts produced by one scan run.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

/**
 * An immutable collection of facts, keyed by fact key (BUILD-SPEC §5).
 *
 * One FactSet is produced per scan run and persisted as the run payload. It is
 * immutable: writers receive a new instance from with() / withAll(). Namespace
 * ownership is enforced by withNamespaced(), which a scanner uses to guarantee
 * it can only write into the namespace it declares.
 *
 * @implements \IteratorAggregate<string,Fact>
 */
final class FactSet implements \Countable, \IteratorAggregate {

	/**
	 * Facts, keyed by fact key, always kept in sorted key order.
	 *
	 * @var array<string,Fact>
	 */
	private readonly array $facts;

	/**
	 * Constructor.
	 *
	 * @param array<int|string,Fact> $facts Facts to hold.
	 * @throws ContractViolation When a non-Fact is passed or a key is duplicated.
	 */
	public function __construct( array $facts = array() ) {
		$indexed = array();

		foreach ( $facts as $fact ) {
			if ( ! $fact instanceof Fact ) {
				throw ContractViolation::type( self::class, 'facts[]', Fact::class, $fact );
			}

			if ( array_key_exists( $fact->key, $indexed ) ) {
				throw ContractViolation::range( self::class, 'facts[]', sprintf( 'duplicate fact key "%s"', $fact->key ) );
			}

			$indexed[ $fact->key ] = $fact;
		}

		ksort( $indexed, SORT_STRING );

		$this->facts = $indexed;
	}

	/**
	 * Build from a flat map of key to value, as stored in a run payload.
	 *
	 * @param array<string,mixed> $data Flat key/value map.
	 * @return self
	 * @throws ContractViolation When a key or value is invalid.
	 */
	public static function fromArray( array $data ): self {
		$facts = array();

		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw ContractViolation::type( self::class, 'key', 'string', $key );
			}

			$facts[] = new Fact( $key, $value );
		}

		return new self( $facts );
	}

	/**
	 * Flat map of key to value, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$out = array();

		foreach ( $this->facts as $key => $fact ) {
			$out[ $key ] = $fact->value;
		}

		return $out;
	}

	/**
	 * Whether a fact key is present.
	 *
	 * @param string $key Fact key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->facts );
	}

	/**
	 * The fact for a key.
	 *
	 * @param string $key Fact key.
	 * @return Fact
	 * @throws ContractViolation When the key is absent.
	 */
	public function fact( string $key ): Fact {
		if ( ! array_key_exists( $key, $this->facts ) ) {
			throw ContractViolation::range( self::class, $key, 'is not present in this fact set' );
		}

		return $this->facts[ $key ];
	}

	/**
	 * The value for a key, or a fallback when absent.
	 *
	 * Analyzer rules use this so a missing fact degrades into "not observed"
	 * rather than an exception; use fact() when absence is a programming error.
	 *
	 * @param string $key      Fact key.
	 * @param mixed  $fallback Value returned when the key is absent.
	 * @return mixed
	 */
	public function value( string $key, mixed $fallback = null ): mixed {
		return array_key_exists( $key, $this->facts ) ? $this->facts[ $key ]->value : $fallback;
	}

	/**
	 * All fact keys, sorted.
	 *
	 * @return array<int,string>
	 */
	public function keys(): array {
		return array_keys( $this->facts );
	}

	/**
	 * The facts belonging to one scanner namespace.
	 *
	 * @param string $namespace_name Namespace, i.e. the first key segment.
	 * @return self
	 */
	public function inNamespace( string $namespace_name ): self {
		$subset = array();

		foreach ( $this->facts as $fact ) {
			if ( $fact->namespaceName() === $namespace_name ) {
				$subset[] = $fact;
			}
		}

		return new self( $subset );
	}

	/**
	 * A copy with one fact added or replaced.
	 *
	 * @param Fact $fact Fact to write.
	 * @return self
	 */
	public function with( Fact $fact ): self {
		$facts               = $this->facts;
		$facts[ $fact->key ] = $fact;

		return new self( array_values( $facts ) );
	}

	/**
	 * A copy with several facts added or replaced.
	 *
	 * @param array<int,Fact> $facts Facts to write.
	 * @return self
	 */
	public function withAll( array $facts ): self {
		$merged = $this->facts;

		foreach ( $facts as $fact ) {
			if ( ! $fact instanceof Fact ) {
				throw ContractViolation::type( self::class, 'facts[]', Fact::class, $fact );
			}

			$merged[ $fact->key ] = $fact;
		}

		return new self( array_values( $merged ) );
	}

	/**
	 * A copy with facts written under an enforced namespace.
	 *
	 * This is how a scanner writes: it declares its namespace once, and any
	 * attempt to write outside it throws rather than silently polluting another
	 * scanner's namespace (BUILD-SPEC §17 Phase 2).
	 *
	 * @param string              $namespace_name Namespace the writer owns.
	 * @param array<string,mixed> $values         Fact key to value map.
	 * @return self
	 * @throws ContractViolation When a key falls outside the namespace.
	 */
	public function withNamespaced( string $namespace_name, array $values ): self {
		$facts = array();

		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw ContractViolation::type( self::class, 'key', 'string', $key );
			}

			$fact = new Fact( $key, $value );

			if ( $fact->namespaceName() !== $namespace_name ) {
				throw ContractViolation::range(
					self::class,
					$key,
					sprintf(
						'may only be written by the "%s" namespace owner, but this writer owns "%s"',
						$fact->namespaceName(),
						$namespace_name
					)
				);
			}

			$facts[] = $fact;
		}

		return $this->withAll( $facts );
	}

	/**
	 * Number of facts held.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->facts );
	}

	/**
	 * Iterate facts in sorted key order.
	 *
	 * @return \Traversable<string,Fact>
	 */
	public function getIterator(): \Traversable {
		return new \ArrayIterator( $this->facts );
	}
}
