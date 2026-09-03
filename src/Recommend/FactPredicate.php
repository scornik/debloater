<?php
/**
 * A requirement expressed as a condition on the facts.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Recommend;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Identifier;

/**
 * `fact:<key>=<value>` from a tweak's `requires` list (BUILD-SPEC §7.1).
 *
 * The grammar is deliberately tiny: one fact key, one expected value, equality
 * only. A richer expression language would be more expressive and much harder
 * to be sure about, and these predicates gate whether a change is applied to
 * someone's live site.
 *
 * Evaluation has three outcomes, not two, and the third is the important one:
 *
 * - **satisfied** — the fact was observed and matches;
 * - **unsatisfied** — the fact was observed and does not match;
 * - **unknown** — the fact was not observed at all.
 *
 * Unknown is not treated as satisfied. A requirement nobody could check is an
 * unresolved requirement, and BUILD-SPEC §7.4 keeps a tweak with one of those
 * out of any plan.
 */
final class FactPredicate {

	/**
	 * The prefix that marks a requirement as a fact predicate.
	 */
	public const PREFIX = 'fact:';

	/**
	 * The fact key to read.
	 *
	 * @var string
	 */
	public readonly string $fact;

	/**
	 * The value it must equal, as written.
	 *
	 * @var string
	 */
	public readonly string $expected;

	/**
	 * Constructor.
	 *
	 * @param string $fact     Fact key.
	 * @param string $expected Expected value, as written in the registry.
	 * @throws ContractViolation When the fact key is malformed.
	 */
	public function __construct( string $fact, string $expected ) {
		if ( 1 !== preg_match( Identifier::FACT_KEY_PATTERN, $fact ) ) {
			throw ContractViolation::range(
				self::class,
				'fact',
				sprintf( 'must be a dot-namespaced fact key, got "%s"', $fact )
			);
		}

		$this->fact     = $fact;
		$this->expected = $expected;
	}

	/**
	 * Whether a requirement string is a fact predicate.
	 *
	 * @param string $requirement Requirement from a tweak's `requires` list.
	 * @return bool
	 */
	public static function looksLikeOne( string $requirement ): bool {
		return str_starts_with( $requirement, self::PREFIX );
	}

	/**
	 * Parse a requirement string.
	 *
	 * @param string $requirement Requirement, e.g. "fact:plugins.detected.woocommerce=true".
	 * @return self
	 * @throws ContractViolation When the syntax is wrong.
	 */
	public static function parse( string $requirement ): self {
		if ( ! self::looksLikeOne( $requirement ) ) {
			throw ContractViolation::range(
				self::class,
				'requirement',
				sprintf( 'must start with "%s", got "%s"', self::PREFIX, $requirement )
			);
		}

		$body     = substr( $requirement, strlen( self::PREFIX ) );
		$position = strpos( $body, '=' );

		if ( false === $position ) {
			throw ContractViolation::range(
				self::class,
				'requirement',
				sprintf( 'must be of the form fact:<key>=<value>, got "%s"', $requirement )
			);
		}

		return new self( substr( $body, 0, $position ), substr( $body, $position + 1 ) );
	}

	/**
	 * Whether the facts satisfy this predicate.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return bool False when the fact is absent, because unknown is not satisfied.
	 */
	public function isSatisfiedBy( FactSet $facts ): bool {
		if ( ! $this->isObservableIn( $facts ) ) {
			return false;
		}

		return $this->matches( $this->read( $facts ) );
	}

	/**
	 * Whether the fact this predicate reads was observed at all.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return bool
	 */
	public function isObservableIn( FactSet $facts ): bool {
		if ( $facts->has( $this->fact ) ) {
			return true;
		}

		// A predicate may address a key inside a map-valued fact, which is how
		// `plugins.detected.woocommerce` reaches into `plugins.detected`.
		$parent = $this->parentKey();

		if ( null === $parent ) {
			return false;
		}

		$map = $facts->value( $parent );

		return is_array( $map ) && array_key_exists( $this->leafKey(), $map );
	}

	/**
	 * A description of what was required, for a rejection message.
	 *
	 * @return string
	 */
	public function describe(): string {
		return sprintf( '%s = %s', $this->fact, $this->expected );
	}

	/**
	 * The requirement string this predicate came from.
	 *
	 * @return string
	 */
	public function toString(): string {
		return self::PREFIX . $this->fact . '=' . $this->expected;
	}

	/**
	 * Read the value, following into a map-valued fact when needed.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return mixed
	 */
	private function read( FactSet $facts ): mixed {
		if ( $facts->has( $this->fact ) ) {
			return $facts->value( $this->fact );
		}

		$parent = $this->parentKey();

		if ( null === $parent ) {
			return null;
		}

		$map = $facts->value( $parent );

		return is_array( $map ) ? ( $map[ $this->leafKey() ] ?? null ) : null;
	}

	/**
	 * Whether an observed value matches the expected one.
	 *
	 * Comparison is on the written form, so `true`, `false`, numbers and strings
	 * all work without the registry needing to declare a type. That is enough
	 * for equality and keeps the grammar to one rule.
	 *
	 * @param mixed $value Observed value.
	 * @return bool
	 */
	private function matches( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			$written = $value ? 'true' : 'false';

			return $written === $this->expected;
		}

		if ( null === $value ) {
			return 'null' === $this->expected;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value === $this->expected;
		}

		return false;
	}

	/**
	 * The fact key one level up, or null when there is none.
	 *
	 * @return string|null
	 */
	private function parentKey(): ?string {
		$position = strrpos( $this->fact, '.' );

		if ( false === $position ) {
			return null;
		}

		$parent = substr( $this->fact, 0, $position );

		// A single segment is a namespace, not a fact.
		return str_contains( $parent, '.' ) ? $parent : null;
	}

	/**
	 * The last segment of the fact key.
	 *
	 * @return string
	 */
	private function leafKey(): string {
		$position = strrpos( $this->fact, '.' );

		return false === $position ? $this->fact : substr( $this->fact, $position + 1 );
	}
}
