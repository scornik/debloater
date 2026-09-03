<?php
/**
 * The outcome of one verification probe.
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
 * Probe result (BUILD-SPEC §11).
 *
 * Each probe reports its own status and the evidence behind it, so a failed
 * verification can name the probe and show what it saw rather than saying
 * "something went wrong".
 */
final class ProbeResult {

	/**
	 * Probe name, e.g. "home", "rest", "runtime_loaded".
	 *
	 * @var string
	 */
	public readonly string $probe;

	/**
	 * Outcome.
	 *
	 * @var ProbeStatus
	 */
	public readonly ProbeStatus $status;

	/**
	 * Human-readable explanation of the outcome.
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Supporting detail, e.g. HTTP status, matched markers, elapsed time.
	 *
	 * @var array<string,scalar|null>
	 */
	public readonly array $evidence;

	/**
	 * Constructor.
	 *
	 * @param string                   $probe    Probe name.
	 * @param ProbeStatus              $status   Outcome.
	 * @param string                   $message  Explanation.
	 * @param array<string,mixed>      $evidence Supporting detail.
	 * @throws ContractViolation When the name or evidence shape is invalid.
	 */
	public function __construct( string $probe, ProbeStatus $status, string $message, array $evidence = array() ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $probe ) ) {
			throw ContractViolation::range(
				self::class,
				'probe',
				sprintf( 'must be a lower_snake_case probe name, got "%s"', $probe )
			);
		}

		if ( '' === trim( $message ) ) {
			throw ContractViolation::range( self::class, 'message', 'must not be empty' );
		}

		$clean = array();

		foreach ( $evidence as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				throw ContractViolation::type( self::class, 'evidence key', 'non-empty string', $key );
			}

			if ( null !== $value && ! is_scalar( $value ) ) {
				throw ContractViolation::type( self::class, 'evidence[' . $key . ']', 'scalar or null', $value );
			}

			$clean[ $key ] = $value;
		}

		ksort( $clean, SORT_STRING );

		$this->probe    = $probe;
		$this->status   = $status;
		$this->message  = $message;
		$this->evidence = $clean;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'probe', 'status', 'message', 'evidence' ) );

		return new self(
			Assert::string( self::class, $data, 'probe' ),
			Assert::enum( self::class, $data, 'status', ProbeStatus::class ),
			Assert::string( self::class, $data, 'message' ),
			Assert::stringKeyedMap( self::class, $data, 'evidence' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'probe'    => $this->probe,
			'status'   => $this->status->value,
			'message'  => $this->message,
			'evidence' => $this->evidence,
		);
	}
}
