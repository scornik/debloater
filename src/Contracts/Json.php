<?php
/**
 * Canonical JSON encoding for hashing and persistence.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

use RuntimeException;

/**
 * One way to encode a structure, so hashes are comparable.
 *
 * Several things in WP Debloat are identified by the hash of their content: the
 * registry hash recorded on every run, the runtime hash in runtime.lock, the
 * snapshot checksum that gates a restore. If two encodings of the same data can
 * differ, those hashes stop meaning "the same content" and start meaning "the
 * same content, encoded by the same code path on the same day".
 *
 * So encoding is centralised: keys sorted recursively, slashes and unicode left
 * unescaped, and a failure to encode is an exception rather than the `false`
 * that json_encode returns and callers forget to check.
 */
final class Json {

	/**
	 * Encoding flags used everywhere a structure is hashed or persisted.
	 */
	public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Encode a value, throwing rather than returning false.
	 *
	 * @param mixed $value Value to encode.
	 * @param int   $flags Additional flags, ORed with FLAGS.
	 * @return string
	 * @throws RuntimeException When the value cannot be encoded.
	 */
	public static function encode( mixed $value, int $flags = 0 ): string {
		$json = json_encode( $value, self::FLAGS | $flags );

		if ( false === $json ) {
			throw new RuntimeException( 'Could not encode value as JSON: ' . json_last_error_msg() );
		}

		return $json;
	}

	/**
	 * Encode a value canonically: object keys sorted recursively.
	 *
	 * Use this whenever the result is hashed or compared, never for output the
	 * user reads, where the authored order is more useful.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 * @throws RuntimeException When the value cannot be encoded.
	 */
	public static function canonical( mixed $value ): string {
		return self::encode( self::sortKeys( $value ) );
	}

	/**
	 * Decode a JSON document into associative arrays.
	 *
	 * @param string $json JSON text.
	 * @return mixed
	 * @throws RuntimeException When the document is malformed.
	 */
	public static function decode( string $json ): mixed {
		$value = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Could not decode JSON: ' . json_last_error_msg() );
		}

		return $value;
	}

	/**
	 * Decode a JSON document that must be an array.
	 *
	 * @param string $json JSON text.
	 * @return array<array-key,mixed>
	 * @throws RuntimeException When the document is malformed or not an array.
	 */
	public static function decodeArray( string $json ): array {
		$value = self::decode( $json );

		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'Expected a JSON object or array, got ' . get_debug_type( $value ) );
		}

		return $value;
	}

	/**
	 * Recursively sort the keys of every map, leaving lists in order.
	 *
	 * @param mixed $value Value to sort.
	 * @return mixed
	 */
	private static function sortKeys( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$sorted = array();

		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::sortKeys( $item );
		}

		if ( ! array_is_list( $sorted ) ) {
			ksort( $sorted, SORT_STRING );
		}

		return $sorted;
	}
}
