<?php
/**
 * What a config tweak changes that a request can observe.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use Debloater\Contracts\Assert;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Identifier;

/**
 * A registry declaration of the fact a config tweak changes, and to what.
 *
 * `TweakEffectTest` found three findings that could never clear, then three
 * more, each because nothing tied a tweak to the observation that should show
 * it working. This is that tie, written down where the tweak is (`D-0079`):
 *
 * - `{"fact": "wp.generator_tag", "equals": false}` — after the tweak, a
 *   request reports that fact with that value.
 * - `equals_param`, `min` and `max_param` for values that depend on the
 *   parameters it was applied with, such as a Heartbeat interval or a revision
 *   limit.
 * - `{"observable": false, "reason": "…"}` — nothing a request can read shows
 *   it, and the reason says why. A declaration either way is required of every
 *   config tweak; silence is not one of the options.
 *
 * Verification reads the declared fact in a fresh loopback request, where the
 * handlers have registered, and reports "applied but not observed" when it
 * does not hold. `TweakEffectTest` holds every observable declaration to being
 * true.
 */
final class TweakEffect {

	/**
	 * Whether a request can observe the effect at all.
	 *
	 * @var bool
	 */
	public readonly bool $observable;

	/**
	 * The fact that shows it, when observable.
	 *
	 * @var string|null
	 */
	public readonly ?string $fact;

	/**
	 * Whether `equals` was declared.
	 *
	 * @var bool
	 */
	public readonly bool $has_equals;

	/**
	 * The value the fact must have.
	 *
	 * @var bool|int|string|null
	 */
	public readonly bool|int|string|null $equals;

	/**
	 * The parameter whose value the fact must equal.
	 *
	 * @var string|null
	 */
	public readonly ?string $equals_param;

	/**
	 * The least the fact may be.
	 *
	 * @var int|null
	 */
	public readonly ?int $min;

	/**
	 * The parameter whose value the fact may not exceed.
	 *
	 * @var string|null
	 */
	public readonly ?string $max_param;

	/**
	 * Why nothing observable shows it.
	 *
	 * @var string|null
	 */
	public readonly ?string $reason;

	/**
	 * Constructor.
	 *
	 * @param bool                 $observable   Whether a request can observe it.
	 * @param string|null          $fact         Fact key.
	 * @param bool                 $has_equals   Whether `equals` applies.
	 * @param bool|int|string|null $equals       Expected value.
	 * @param string|null          $equals_param Parameter the fact must equal.
	 * @param int|null             $min          Least value.
	 * @param string|null          $max_param    Parameter the fact may not exceed.
	 * @param string|null          $reason       Why it is not observable.
	 * @throws ContractViolation When the declaration says nothing checkable.
	 */
	public function __construct(
		bool $observable,
		?string $fact = null,
		bool $has_equals = false,
		bool|int|string|null $equals = null,
		?string $equals_param = null,
		?int $min = null,
		?string $max_param = null,
		?string $reason = null
	) {
		if ( ! $observable ) {
			if ( null === $reason || '' === trim( $reason ) ) {
				throw ContractViolation::range( self::class, 'reason', 'an effect nothing can observe must say why' );
			}

			if ( null !== $fact || $has_equals || null !== $equals_param || null !== $min || null !== $max_param ) {
				throw ContractViolation::range( self::class, 'observable', 'an unobservable effect declares no fact or expectation' );
			}
		} else {
			if ( null === $fact || 1 !== preg_match( Identifier::FACT_KEY_PATTERN, $fact ) ) {
				throw ContractViolation::range( self::class, 'fact', 'an observable effect names a fact key' );
			}

			if ( ! $has_equals && null === $equals_param && null === $min && null === $max_param ) {
				throw ContractViolation::range( self::class, 'fact', 'an observable effect says what the fact must be' );
			}

			$named = array(
				'equals_param' => $equals_param,
				'max_param'    => $max_param,
			);

			foreach ( $named as $field => $param ) {
				if ( null !== $param && 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $param ) ) {
					throw ContractViolation::range( self::class, $field, 'must be a parameter name' );
				}
			}
		}

		$this->observable   = $observable;
		$this->fact         = $fact;
		$this->has_equals   = $has_equals;
		$this->equals       = $equals;
		$this->equals_param = $equals_param;
		$this->min          = $min;
		$this->max_param    = $max_param;
		$this->reason       = $reason;
	}

	/**
	 * Build from a decoded registry document's `effect`.
	 *
	 * @param array<string,mixed> $data Decoded declaration.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		if ( array_key_exists( 'observable', $data ) ) {
			Assert::onlyKeys( self::class, $data, array( 'observable', 'reason' ) );

			if ( false !== $data['observable'] ) {
				throw ContractViolation::range( self::class, 'observable', 'is only ever declared as false' );
			}

			return new self( false, null, false, null, null, null, null, Assert::string( self::class, $data, 'reason' ) );
		}

		Assert::onlyKeys( self::class, $data, array( 'fact', 'equals', 'equals_param', 'min', 'max_param' ) );

		$equals = $data['equals'] ?? null;

		if ( null !== $equals && ! is_bool( $equals ) && ! is_int( $equals ) && ! is_string( $equals ) ) {
			throw ContractViolation::type( self::class, 'equals', 'bool, int, string or null', $equals );
		}

		return new self(
			true,
			Assert::string( self::class, $data, 'fact' ),
			array_key_exists( 'equals', $data ),
			$equals,
			Assert::nullableString( self::class, $data, 'equals_param' ),
			array_key_exists( 'min', $data ) ? Assert::int( self::class, $data, 'min' ) : null,
			Assert::nullableString( self::class, $data, 'max_param' )
		);
	}

	/**
	 * Whether an observed value shows the effect, given the tweak's parameters.
	 *
	 * @param mixed               $actual Observed fact value; null when the fact was not observed.
	 * @param array<string,mixed> $params Parameters the tweak was applied with.
	 * @return bool|null Null when there is nothing to judge: unobservable, or not observed.
	 */
	public function holds( mixed $actual, array $params ): ?bool {
		if ( ! $this->observable || null === $actual ) {
			return null;
		}

		if ( $this->has_equals && $actual !== $this->equals ) {
			return false;
		}

		if ( null !== $this->equals_param && ( ! array_key_exists( $this->equals_param, $params ) || $actual !== $params[ $this->equals_param ] ) ) {
			return false;
		}

		if ( null !== $this->min && ( ! is_int( $actual ) || $actual < $this->min ) ) {
			return false;
		}

		if ( null !== $this->max_param ) {
			$max = $params[ $this->max_param ] ?? null;

			if ( ! is_int( $actual ) || ! is_int( $max ) || $actual > $max ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * What the fact should be, in words for evidence and messages.
	 *
	 * @param array<string,mixed> $params Parameters the tweak was applied with.
	 * @return string
	 */
	public function describeExpected( array $params ): string {
		$parts = array();

		if ( $this->has_equals ) {
			$parts[] = '= ' . self::literal( $this->equals );
		}

		if ( null !== $this->equals_param ) {
			$parts[] = '= ' . self::literal( $params[ $this->equals_param ] ?? null );
		}

		if ( null !== $this->min ) {
			$parts[] = '>= ' . $this->min;
		}

		if ( null !== $this->max_param ) {
			$parts[] = '<= ' . self::literal( $params[ $this->max_param ] ?? null );
		}

		return implode( ' and ', $parts );
	}

	/**
	 * A value as it would be written in the registry, without WordPress.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function literal( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		return is_int( $value ) ? (string) $value : '"' . ( is_scalar( $value ) ? (string) $value : '' ) . '"';
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		if ( ! $this->observable ) {
			return array(
				'observable' => false,
				'reason'     => $this->reason,
			);
		}

		$data = array( 'fact' => $this->fact );

		if ( $this->has_equals ) {
			$data['equals'] = $this->equals;
		}

		foreach ( array( 'equals_param', 'min', 'max_param' ) as $field ) {
			if ( null !== $this->{$field} ) {
				$data[ $field ] = $this->{$field};
			}
		}

		return $data;
	}
}
