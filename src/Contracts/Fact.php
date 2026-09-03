<?php
/**
 * A single observed fact about the site.
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
 * One dot-namespaced observation produced by a scanner (BUILD-SPEC §5).
 *
 * Facts carry no opinions. A fact value is a scalar, a list of scalars, or a
 * flat map of scalars — never an adjective, a recommendation, or a tweak id.
 * The first segment of the key is the owning scanner's namespace; a scanner may
 * only write keys inside its own namespace.
 */
final class Fact {

	/**
	 * Pattern a fact key must match: dot-separated lowercase segments.
	 */
	private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9][a-z0-9_-]*)+$/';

	/**
	 * Dot-namespaced key.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * Observed value: scalar, list of scalars, or flat map of scalars.
	 *
	 * @var scalar|array<array-key,mixed>|null
	 */
	public readonly mixed $value;

	/**
	 * Constructor.
	 *
	 * @param string                             $key   Dot-namespaced key.
	 * @param scalar|array<array-key,mixed>|null $value Observed value.
	 * @throws ContractViolation When the key or value shape is invalid.
	 */
	public function __construct( string $key, mixed $value ) {
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			throw ContractViolation::range(
				self::class,
				'key',
				sprintf( 'must be a dot-namespaced key such as "wp.heartbeat_interval", got "%s"', $key )
			);
		}

		self::assertValueShape( $key, $value, 0 );

		$this->key   = $key;
		$this->value = $value;
	}

	/**
	 * The owning scanner namespace, i.e. the first key segment.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		$dot = strpos( $this->key, '.' );

		return false === $dot ? $this->key : substr( $this->key, 0, $dot );
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'key', 'value' ) );

		return new self(
			Assert::string( self::class, $data, 'key' ),
			Assert::present( self::class, $data, 'value' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array{key:string,value:mixed}
	 */
	public function toArray(): array {
		return array(
			'key'   => $this->key,
			'value' => $this->value,
		);
	}

	/**
	 * Reject values that are not scalars, lists of scalars, or flat maps.
	 *
	 * Nesting is allowed to exactly one level, which covers documented shapes
	 * such as db.autoload.top (a list of {name, bytes} maps) while keeping fact
	 * values trivially serialisable and diffable.
	 *
	 * @param string $key   Fact key, for error reporting.
	 * @param mixed  $value Value to inspect.
	 * @param int    $depth Current nesting depth.
	 * @return void
	 * @throws ContractViolation When the value shape is invalid.
	 */
	private static function assertValueShape( string $key, mixed $value, int $depth ): void {
		if ( null === $value || is_scalar( $value ) ) {
			if ( is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ) {
				throw ContractViolation::range( self::class, $key, 'must be a finite number' );
			}

			return;
		}

		if ( ! is_array( $value ) ) {
			throw ContractViolation::type( self::class, $key, 'scalar, list or flat map', $value );
		}

		if ( $depth >= 2 ) {
			throw ContractViolation::range(
				self::class,
				$key,
				'must not nest more than one level below the fact value'
			);
		}

		foreach ( $value as $inner ) {
			self::assertValueShape( $key, $inner, $depth + 1 );
		}
	}
}
