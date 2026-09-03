<?php
/**
 * Validated parameter values for a tweak.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Parameters passed to a tweak (BUILD-SPEC §7.1).
 *
 * These values end up inside generated code, emitted with var_export into
 * runtime.php. That is why the shape is deliberately narrow: only scalars and
 * flat lists of scalars, never objects, closures or nested structures, and
 * always in sorted key order so identical selections compile byte-identically.
 *
 * Type validation against the tweak's declared `params` schema happens in
 * Registry\SchemaValidator before a TweakParams instance is trusted for code
 * generation (BUILD-SPEC §13 rule 5); this class enforces the shape floor that
 * makes that validation meaningful.
 */
final class TweakParams implements \Countable {

	/**
	 * Parameter values keyed by name, in sorted key order.
	 *
	 * @var array<string,scalar|array<int,scalar>>
	 */
	private readonly array $values;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $values Parameter values keyed by name.
	 * @throws ContractViolation When a name or value shape is invalid.
	 */
	public function __construct( array $values = array() ) {
		$clean = array();

		foreach ( $values as $name => $value ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
				throw ContractViolation::range(
					self::class,
					'name',
					sprintf( 'parameter names must be lower_snake_case, got "%s"', is_string( $name ) ? $name : get_debug_type( $name ) )
				);
			}

			$clean[ $name ] = self::assertValue( $name, $value );
		}

		ksort( $clean, SORT_STRING );

		$this->values = $clean;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,scalar|array<int,scalar>>
	 */
	public function toArray(): array {
		return $this->values;
	}

	/**
	 * Whether a parameter is present.
	 *
	 * @param string $name Parameter name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return array_key_exists( $name, $this->values );
	}

	/**
	 * A parameter value, or a fallback when absent.
	 *
	 * @param string $name     Parameter name.
	 * @param mixed  $fallback Value returned when absent.
	 * @return mixed
	 */
	public function get( string $name, mixed $fallback = null ): mixed {
		return array_key_exists( $name, $this->values ) ? $this->values[ $name ] : $fallback;
	}

	/**
	 * An integer parameter.
	 *
	 * @param string $name Parameter name.
	 * @return int
	 * @throws ContractViolation When absent or not an int.
	 */
	public function int( string $name ): int {
		$value = $this->get( $name );

		if ( ! is_int( $value ) ) {
			throw ContractViolation::type( self::class, $name, 'int', $value );
		}

		return $value;
	}

	/**
	 * Whether any parameter is set.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->values;
	}

	/**
	 * Number of parameters.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->values );
	}

	/**
	 * Validate one parameter value.
	 *
	 * @param string $name  Parameter name, for error reporting.
	 * @param mixed  $value Value to validate.
	 * @return scalar|array<int,scalar>
	 * @throws ContractViolation When the value shape is not permitted.
	 */
	private static function assertValue( string $name, mixed $value ) {
		if ( is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ) {
			throw ContractViolation::range( self::class, $name, 'must be a finite number' );
		}

		if ( is_scalar( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			throw ContractViolation::type( self::class, $name, 'scalar or list of scalars', $value );
		}

		if ( ! array_is_list( $value ) ) {
			throw ContractViolation::type( self::class, $name, 'list', $value );
		}

		foreach ( $value as $index => $item ) {
			if ( ! is_scalar( $item ) ) {
				throw ContractViolation::type( self::class, $name . '[' . $index . ']', 'scalar', $item );
			}

			if ( is_float( $item ) && ( is_nan( $item ) || is_infinite( $item ) ) ) {
				throw ContractViolation::range( self::class, $name . '[' . $index . ']', 'must be a finite number' );
			}
		}

		/** @var array<int,scalar> $value */
		return $value;
	}
}
