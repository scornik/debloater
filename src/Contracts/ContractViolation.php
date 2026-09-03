<?php
/**
 * Thrown when a contract value object is given data it cannot represent.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

use InvalidArgumentException;

/**
 * A contract was handed data that violates its invariants.
 *
 * Contracts validate in their constructor, so an instance that exists is valid
 * by construction. Every failure path throws this exception; none returns null.
 */
final class ContractViolation extends InvalidArgumentException {

	/**
	 * The contract class that rejected the data.
	 *
	 * @var string
	 */
	private string $contract;

	/**
	 * The field that failed, as a dotted path, or an empty string for whole-object failures.
	 *
	 * @var string
	 */
	private string $field;

	/**
	 * Constructor.
	 *
	 * @param string $contract Contract class name.
	 * @param string $field    Field path, or '' when the whole object is invalid.
	 * @param string $reason   Human-readable reason.
	 */
	public function __construct( string $contract, string $field, string $reason ) {
		$this->contract = $contract;
		$this->field    = $field;

		$where = '' === $field ? $contract : $contract . '::' . $field;

		parent::__construct( $where . ': ' . $reason );
	}

	/**
	 * The contract class that rejected the data.
	 *
	 * @return string
	 */
	public function contract(): string {
		return $this->contract;
	}

	/**
	 * The field that failed, or '' for a whole-object failure.
	 *
	 * @return string
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * Build a violation for a field that is missing.
	 *
	 * @param string $contract Contract class name.
	 * @param string $field    Field path.
	 * @return self
	 */
	public static function missing( string $contract, string $field ): self {
		return new self( $contract, $field, 'is required' );
	}

	/**
	 * Build a violation for a field of the wrong type.
	 *
	 * @param string $contract Contract class name.
	 * @param string $field    Field path.
	 * @param string $expected Expected type description.
	 * @param mixed  $actual   The value received.
	 * @return self
	 */
	public static function type( string $contract, string $field, string $expected, mixed $actual ): self {
		return new self(
			$contract,
			$field,
			sprintf( 'expected %s, got %s', $expected, get_debug_type( $actual ) )
		);
	}

	/**
	 * Build a violation for a value outside its allowed range or set.
	 *
	 * @param string $contract Contract class name.
	 * @param string $field    Field path.
	 * @param string $reason   Why the value is out of range.
	 * @return self
	 */
	public static function range( string $contract, string $field, string $reason ): self {
		return new self( $contract, $field, $reason );
	}

	/**
	 * Build a violation for unknown keys in an input array.
	 *
	 * @param string        $contract Contract class name.
	 * @param array<int,string> $keys Unknown key names.
	 * @return self
	 */
	public static function unknownKeys( string $contract, array $keys ): self {
		sort( $keys );

		return new self( $contract, '', 'unknown keys: ' . implode( ', ', $keys ) );
	}
}
