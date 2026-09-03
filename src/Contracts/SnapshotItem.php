<?php
/**
 * One stored row that a data operation is about to delete.
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
 * A Level B snapshot item (BUILD-SPEC §8, debloater_snapshot_items).
 *
 * The payload holds the original row verbatim, in enough detail to reinsert it
 * with its original identifiers and timestamps. This is what makes a
 * destructive operation reversible; without it the operation is not allowed to
 * run at all (§13 rule 8).
 */
final class SnapshotItem {

	/**
	 * Object types a snapshot item may describe.
	 */
	public const OBJECT_TYPES = array(
		'revision',
		'post',
		'postmeta',
		'termmeta',
		'usermeta',
		'comment',
		'commentmeta',
		'transient',
		'cron',
		'option',
	);

	/**
	 * Kind of object stored.
	 *
	 * @var string
	 */
	public readonly string $object_type;

	/**
	 * Identifier for the object within its type.
	 *
	 * @var string
	 */
	public readonly string $object_key;

	/**
	 * The original row, sufficient to reinsert it.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $payload;

	/**
	 * Whether this item has been restored.
	 *
	 * @var bool
	 */
	public readonly bool $restored;

	/**
	 * Constructor.
	 *
	 * @param string              $object_type Kind of object.
	 * @param string              $object_key  Identifier within the type.
	 * @param array<string,mixed> $payload     Original row.
	 * @param bool                $restored    Whether already restored.
	 * @throws ContractViolation When the type, key or payload is invalid.
	 */
	public function __construct( string $object_type, string $object_key, array $payload, bool $restored = false ) {
		if ( ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			throw ContractViolation::range(
				self::class,
				'object_type',
				sprintf( 'must be one of %s, got "%s"', implode( ', ', self::OBJECT_TYPES ), $object_type )
			);
		}

		if ( '' === $object_key ) {
			throw ContractViolation::range( self::class, 'object_key', 'must not be empty' );
		}

		// The column is VARCHAR(191); refuse silently-truncating keys.
		if ( strlen( $object_key ) > 191 ) {
			throw ContractViolation::range( self::class, 'object_key', 'must be at most 191 bytes' );
		}

		if ( array() === $payload ) {
			throw ContractViolation::range(
				self::class,
				'payload',
				'must contain the original row; an empty payload cannot be restored'
			);
		}

		foreach ( array_keys( $payload ) as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				throw ContractViolation::type( self::class, 'payload key', 'non-empty string', $key );
			}
		}

		$this->object_type = $object_type;
		$this->object_key  = $object_key;
		$this->payload     = $payload;
		$this->restored    = $restored;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'object_type', 'object_key', 'payload', 'restored' ) );

		return new self(
			Assert::string( self::class, $data, 'object_type' ),
			Assert::string( self::class, $data, 'object_key' ),
			Assert::stringKeyedMap( self::class, $data, 'payload' ),
			Assert::boolOr( self::class, $data, 'restored', false )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'object_type' => $this->object_type,
			'object_key'  => $this->object_key,
			'payload'     => $this->payload,
			'restored'    => $this->restored,
		);
	}

	/**
	 * A copy marked restored.
	 *
	 * @return self
	 */
	public function markRestored(): self {
		return new self( $this->object_type, $this->object_key, $this->payload, true );
	}
}
