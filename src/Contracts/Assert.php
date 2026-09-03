<?php
/**
 * Shared strict extraction helpers used by contract fromArray() implementations.
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
 * Strict array-shape assertions.
 *
 * Every contract's fromArray() maps input through these helpers so the type and
 * presence rules are expressed exactly once (docs/DECISIONS.md D-0002). Nothing
 * here coerces silently: the only widening allowed is int to float, because JSON
 * has no way to spell "1.0".
 */
final class Assert {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Reject any key that is not in the allowed list.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param array<int,string>   $allowed  Allowed key names.
	 * @return void
	 * @throws ContractViolation When an unknown key is present.
	 */
	public static function onlyKeys( string $contract, array $data, array $allowed ): void {
		$unknown = array_values( array_diff( array_keys( $data ), $allowed ) );

		if ( array() !== $unknown ) {
			throw ContractViolation::unknownKeys( $contract, $unknown );
		}
	}

	/**
	 * Require a key to be present.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return mixed
	 * @throws ContractViolation When the key is absent.
	 */
	public static function present( string $contract, array $data, string $key ): mixed {
		if ( ! array_key_exists( $key, $data ) ) {
			throw ContractViolation::missing( $contract, $key );
		}

		return $data[ $key ];
	}

	/**
	 * Require a non-empty string.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return string
	 * @throws ContractViolation When absent, not a string, or empty.
	 */
	public static function string( string $contract, array $data, string $key ): string {
		$value = self::present( $contract, $data, $key );

		if ( ! is_string( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'string', $value );
		}

		if ( '' === $value ) {
			throw ContractViolation::range( $contract, $key, 'must not be empty' );
		}

		return $value;
	}

	/**
	 * A string that may be null, but must be a non-empty string when present.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return string|null
	 * @throws ContractViolation When present but not a non-empty string.
	 */
	public static function nullableString( string $contract, array $data, string $key ): ?string {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return null;
		}

		return self::string( $contract, $data, $key );
	}

	/**
	 * Require an integer. Floats and numeric strings are rejected.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return int
	 * @throws ContractViolation When absent or not an int.
	 */
	public static function int( string $contract, array $data, string $key ): int {
		$value = self::present( $contract, $data, $key );

		if ( ! is_int( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'int', $value );
		}

		return $value;
	}

	/**
	 * Require a float. An int is widened, because JSON cannot express 1.0.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return float
	 * @throws ContractViolation When absent, not numeric, or not finite.
	 */
	public static function float( string $contract, array $data, string $key ): float {
		$value = self::present( $contract, $data, $key );

		if ( is_int( $value ) ) {
			return (float) $value;
		}

		if ( ! is_float( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'float', $value );
		}

		if ( is_nan( $value ) || is_infinite( $value ) ) {
			throw ContractViolation::range( $contract, $key, 'must be a finite number' );
		}

		return $value;
	}

	/**
	 * Require a boolean. 0/1 and the strings "true"/"false" are rejected.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return bool
	 * @throws ContractViolation When absent or not a bool.
	 */
	public static function bool( string $contract, array $data, string $key ): bool {
		$value = self::present( $contract, $data, $key );

		if ( ! is_bool( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'bool', $value );
		}

		return $value;
	}

	/**
	 * A boolean with a fallback used when the key is absent.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @param bool                $fallback Value used when the key is absent.
	 * @return bool
	 * @throws ContractViolation When present but not a bool.
	 */
	public static function boolOr( string $contract, array $data, string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $data ) ) {
			return $fallback;
		}

		return self::bool( $contract, $data, $key );
	}

	/**
	 * An integer with a fallback used when the key is absent.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @param int                 $fallback Value used when the key is absent.
	 * @return int
	 * @throws ContractViolation When present but not an int.
	 */
	public static function intOr( string $contract, array $data, string $key, int $fallback ): int {
		if ( ! array_key_exists( $key, $data ) ) {
			return $fallback;
		}

		return self::int( $contract, $data, $key );
	}

	/**
	 * A string with a fallback used when the key is absent.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @param string              $fallback Value used when the key is absent.
	 * @return string
	 * @throws ContractViolation When present but not a non-empty string.
	 */
	public static function stringOr( string $contract, array $data, string $key, string $fallback ): string {
		if ( ! array_key_exists( $key, $data ) ) {
			return $fallback;
		}

		return self::string( $contract, $data, $key );
	}

	/**
	 * Require an array value.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return array<array-key,mixed>
	 * @throws ContractViolation When absent or not an array.
	 */
	public static function arrayValue( string $contract, array $data, string $key ): array {
		$value = self::present( $contract, $data, $key );

		if ( ! is_array( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'array', $value );
		}

		return $value;
	}

	/**
	 * An array defaulting to the empty array when the key is absent or null.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return array<array-key,mixed>
	 * @throws ContractViolation When present but not an array.
	 */
	public static function arrayOrEmpty( string $contract, array $data, string $key ): array {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return array();
		}

		return self::arrayValue( $contract, $data, $key );
	}

	/**
	 * Require a list (sequential integer keys from zero) of non-empty strings.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return array<int,string>
	 * @throws ContractViolation When not a list of non-empty strings.
	 */
	public static function stringList( string $contract, array $data, string $key ): array {
		$value = self::arrayOrEmpty( $contract, $data, $key );

		if ( ! array_is_list( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'list', $value );
		}

		$out = array();

		foreach ( $value as $index => $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				throw ContractViolation::type( $contract, $key . '[' . $index . ']', 'non-empty string', $item );
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Require a list of arrays, for nested contracts.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return array<int,array<string,mixed>>
	 * @throws ContractViolation When not a list of arrays.
	 */
	public static function arrayList( string $contract, array $data, string $key ): array {
		$value = self::arrayOrEmpty( $contract, $data, $key );

		if ( ! array_is_list( $value ) ) {
			throw ContractViolation::type( $contract, $key, 'list', $value );
		}

		$out = array();

		foreach ( $value as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw ContractViolation::type( $contract, $key . '[' . $index . ']', 'array', $item );
			}

			/** @var array<string,mixed> $item */
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Require a map keyed by non-empty strings.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @return array<string,mixed>
	 * @throws ContractViolation When a key is not a non-empty string.
	 */
	public static function stringKeyedMap( string $contract, array $data, string $key ): array {
		$value = self::arrayOrEmpty( $contract, $data, $key );
		$out   = array();

		foreach ( $value as $map_key => $map_value ) {
			if ( ! is_string( $map_key ) || '' === $map_key ) {
				throw ContractViolation::type( $contract, $key . ' key', 'non-empty string', $map_key );
			}

			$out[ $map_key ] = $map_value;
		}

		return $out;
	}

	/**
	 * Require a float inside an inclusive range.
	 *
	 * @param string              $contract Contract class name.
	 * @param array<string,mixed> $data     Input data.
	 * @param string              $key      Key name.
	 * @param float               $min      Inclusive minimum.
	 * @param float               $max      Inclusive maximum.
	 * @return float
	 * @throws ContractViolation When outside the range.
	 */
	public static function floatBetween( string $contract, array $data, string $key, float $min, float $max ): float {
		$value = self::float( $contract, $data, $key );

		if ( $value < $min || $value > $max ) {
			throw ContractViolation::range(
				$contract,
				$key,
				sprintf( 'must be between %s and %s inclusive, got %s', $min, $max, $value )
			);
		}

		return $value;
	}

	/**
	 * Resolve a backed string enum case, reporting the allowed values on failure.
	 *
	 * @template T of \BackedEnum
	 * @param string              $contract   Contract class name.
	 * @param array<string,mixed> $data       Input data.
	 * @param string              $key        Key name.
	 * @param class-string<T>     $enum_class Backed enum class name.
	 * @return T
	 * @throws ContractViolation When absent or not a valid case.
	 */
	public static function enum( string $contract, array $data, string $key, string $enum_class ) {
		$value     = self::string( $contract, $data, $key );
		$enum_case = $enum_class::tryFrom( $value );

		if ( null === $enum_case ) {
			$allowed = array_map(
				static fn ( \BackedEnum $candidate ): string => (string) $candidate->value,
				$enum_class::cases()
			);

			throw ContractViolation::range(
				$contract,
				$key,
				sprintf( 'must be one of %s, got "%s"', implode( ', ', $allowed ), $value )
			);
		}

		return $enum_case;
	}
}
