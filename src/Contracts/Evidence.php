<?php
/**
 * One piece of evidence behind a finding.
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
 * Evidence entry (BUILD-SPEC §6, locked decision #5).
 *
 * Every finding carries evidence, and every evidence entry names the fact key it
 * came from. That provenance is mandatory: it is what lets the UI show the user
 * exactly what was observed rather than asking them to trust a verdict.
 */
final class Evidence {

	/**
	 * Human-readable label, e.g. "Current interval".
	 *
	 * @var string
	 */
	public readonly string $label;

	/**
	 * Displayed value, e.g. "15 s" or 4 or true.
	 *
	 * @var scalar|array<array-key,mixed>|null
	 */
	public readonly mixed $value;

	/**
	 * Fact key this evidence was derived from.
	 *
	 * @var string
	 */
	public readonly string $fact;

	/**
	 * Constructor.
	 *
	 * @param string                             $label Human-readable label.
	 * @param scalar|array<array-key,mixed>|null $value Displayed value.
	 * @param string                             $fact  Originating fact key.
	 * @throws ContractViolation When the label, value or fact key is invalid.
	 */
	public function __construct( string $label, mixed $value, string $fact ) {
		if ( '' === $label ) {
			throw ContractViolation::range( self::class, 'label', 'must not be empty' );
		}

		if ( ! is_scalar( $value ) && null !== $value && ! is_array( $value ) ) {
			throw ContractViolation::type( self::class, 'value', 'scalar, array or null', $value );
		}

		if ( '' === $fact ) {
			throw ContractViolation::range(
				self::class,
				'fact',
				'must name the fact key this evidence came from; evidence without provenance is not allowed'
			);
		}

		$this->label = $label;
		$this->value = $value;
		$this->fact  = $fact;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'label', 'value', 'fact' ) );

		return new self(
			Assert::string( self::class, $data, 'label' ),
			Assert::present( self::class, $data, 'value' ),
			Assert::string( self::class, $data, 'fact' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array{label:string,value:mixed,fact:string}
	 */
	public function toArray(): array {
		return array(
			'label' => $this->label,
			'value' => $this->value,
			'fact'  => $this->fact,
		);
	}
}
