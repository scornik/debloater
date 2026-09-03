<?php
/**
 * JSON Schema validation for registry documents and inbound parameters.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

/**
 * A JSON Schema draft-07 validator covering the subset Debloater uses.
 *
 * Written by hand rather than pulled in as a dependency, because the plugin ships
 * with zero Composer runtime dependencies (BUILD-SPEC §3) and this code sits on
 * the security boundary between user input and generated PHP (§13 rule 5). The
 * supported keyword list and the reasoning are in docs/DECISIONS.md D-0001.
 *
 * Two design choices are load-bearing:
 *
 * - An unsupported keyword throws UnsupportedSchemaKeyword at validation time
 *   rather than being ignored. A schema that silently does not check what its
 *   author thought it checked is worse than no schema.
 * - Validation collects every violation instead of stopping at the first, so a
 *   registry author or a REST caller learns everything that is wrong at once.
 *
 * Values are validated in their PHP-decoded form (json_decode with associative
 * arrays). JSON objects and arrays are therefore both PHP arrays, and the two
 * are told apart by array_is_list(), with the empty array treated as whichever
 * the schema asks for.
 */
final class SchemaValidator {

	/**
	 * Keywords this validator implements.
	 */
	private const SUPPORTED = array(
		'$schema',
		'$id',
		'$ref',
		'$comment',
		'definitions',
		'$defs',
		'title',
		'description',
		'default',
		'examples',
		'type',
		'enum',
		'const',
		'required',
		'properties',
		'patternProperties',
		'additionalProperties',
		'propertyNames',
		'minProperties',
		'maxProperties',
		'items',
		'minItems',
		'maxItems',
		'uniqueItems',
		'contains',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'multipleOf',
		'minLength',
		'maxLength',
		'pattern',
		'format',
		'anyOf',
		'oneOf',
		'allOf',
		'not',
	);

	/**
	 * Formats this validator actively enforces. Anything else is advisory.
	 */
	private const ENFORCED_FORMATS = array( 'date-time', 'uri', 'email' );

	/**
	 * The root schema, used to resolve local $ref pointers.
	 *
	 * @var array<string,mixed>
	 */
	private array $root;

	/**
	 * Violations collected during the current validation pass.
	 *
	 * @var array<int,SchemaViolation>
	 */
	private array $violations = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $schema The schema to validate against.
	 */
	public function __construct( array $schema ) {
		$this->root = $schema;
	}

	/**
	 * Build a validator from a schema file.
	 *
	 * @param string $path Absolute path to a .json schema file.
	 * @return self
	 * @throws \RuntimeException When the file cannot be read or parsed.
	 */
	public static function fromFile( string $path ): self {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'Schema file is not readable: %s', $path ) );
		}

		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			throw new \RuntimeException( sprintf( 'Schema file could not be read: %s', $path ) );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				sprintf( 'Schema file is not a JSON object: %s (%s)', $path, json_last_error_msg() )
			);
		}

		/** @var array<string,mixed> $decoded */
		return new self( $decoded );
	}

	/**
	 * Validate a decoded value against the schema.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return array<int,SchemaViolation> Empty when the value is valid.
	 * @throws UnsupportedSchemaKeyword When the schema uses an unimplemented keyword.
	 */
	public function validate( mixed $value ): array {
		$this->violations = array();

		$this->check( $value, $this->root, '', '#' );

		return $this->violations;
	}

	/**
	 * Whether a decoded value satisfies the schema.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return bool
	 * @throws UnsupportedSchemaKeyword When the schema uses an unimplemented keyword.
	 */
	public function isValid( mixed $value ): bool {
		return array() === $this->validate( $value );
	}

	/**
	 * Validate and throw on the first failure, reporting all of them.
	 *
	 * @param mixed  $value Decoded JSON value.
	 * @param string $label Label for the error message, e.g. a file path.
	 * @return void
	 * @throws \RuntimeException When the value is invalid.
	 */
	public function assertValid( mixed $value, string $label = 'document' ): void {
		$violations = $this->validate( $value );

		if ( array() === $violations ) {
			return;
		}

		$lines = array_map( static fn ( SchemaViolation $v ): string => '  - ' . (string) $v, $violations );

		throw new \RuntimeException(
			sprintf( "%s failed schema validation:\n%s", $label, implode( "\n", $lines ) )
		);
	}

	/**
	 * Check a value against a subschema.
	 *
	 * @param mixed               $value          Value under test.
	 * @param array<string,mixed> $schema         Subschema.
	 * @param string              $pointer        JSON pointer to the value.
	 * @param string              $schema_pointer JSON pointer to the subschema.
	 * @return int Number of violations recorded by this call.
	 * @throws UnsupportedSchemaKeyword When the subschema uses an unimplemented keyword.
	 */
	private function check( mixed $value, array $schema, string $pointer, string $schema_pointer ): int {
		$before = count( $this->violations );

		foreach ( array_keys( $schema ) as $keyword ) {
			if ( ! in_array( $keyword, self::SUPPORTED, true ) ) {
				throw new UnsupportedSchemaKeyword( (string) $keyword, $schema_pointer );
			}
		}

		if ( array_key_exists( '$ref', $schema ) ) {
			$ref = $schema['$ref'];

			if ( ! is_string( $ref ) ) {
				throw new UnsupportedSchemaKeyword( '$ref (non-string)', $schema_pointer );
			}

			$resolved = $this->resolveRef( $ref, $schema_pointer );

			return $this->check( $value, $resolved, $pointer, $ref );
		}

		$this->checkType( $value, $schema, $pointer );
		$this->checkEnumAndConst( $value, $schema, $pointer );
		$this->checkNumeric( $value, $schema, $pointer );
		$this->checkString( $value, $schema, $pointer );
		$this->checkArray( $value, $schema, $pointer, $schema_pointer );
		$this->checkObject( $value, $schema, $pointer, $schema_pointer );
		$this->checkCombinators( $value, $schema, $pointer, $schema_pointer );

		return count( $this->violations ) - $before;
	}

	/**
	 * Resolve a local $ref against the root schema.
	 *
	 * @param string $ref            The reference, e.g. "#/definitions/risk".
	 * @param string $schema_pointer Where the reference appeared.
	 * @return array<string,mixed>
	 * @throws UnsupportedSchemaKeyword When the reference is remote or unresolvable.
	 */
	private function resolveRef( string $ref, string $schema_pointer ): array {
		if ( '#' === $ref ) {
			return $this->root;
		}

		if ( ! str_starts_with( $ref, '#/' ) ) {
			// Remote references would mean fetching over the network, which
			// BUILD-SPEC §13 rule 9 forbids.
			throw new UnsupportedSchemaKeyword( '$ref to ' . $ref, $schema_pointer );
		}

		$node = $this->root;

		foreach ( explode( '/', substr( $ref, 2 ) ) as $segment ) {
			$segment = str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );

			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				throw new UnsupportedSchemaKeyword( '$ref to ' . $ref . ' (unresolvable)', $schema_pointer );
			}

			$node = $node[ $segment ];
		}

		if ( ! is_array( $node ) ) {
			throw new UnsupportedSchemaKeyword( '$ref to ' . $ref . ' (not a schema)', $schema_pointer );
		}

		/** @var array<string,mixed> $node */
		return $node;
	}

	/**
	 * Check the `type` keyword.
	 *
	 * @param mixed               $value   Value under test.
	 * @param array<string,mixed> $schema  Subschema.
	 * @param string              $pointer JSON pointer.
	 * @return void
	 */
	private function checkType( mixed $value, array $schema, string $pointer ): void {
		if ( ! array_key_exists( 'type', $schema ) ) {
			return;
		}

		$types = is_array( $schema['type'] ) ? $schema['type'] : array( $schema['type'] );

		foreach ( $types as $type ) {
			if ( self::matchesType( $value, (string) $type ) ) {
				return;
			}
		}

		$this->violate(
			$pointer,
			'type',
			sprintf(
				'expected type %s, got %s',
				implode( ' or ', array_map( 'strval', $types ) ),
				self::describe( $value )
			)
		);
	}

	/**
	 * Whether a value matches a JSON Schema primitive type.
	 *
	 * @param mixed  $value Value under test.
	 * @param string $type  Type name.
	 * @return bool
	 */
	private static function matchesType( mixed $value, string $type ): bool {
		return match ( $type ) {
			'null'    => null === $value,
			'boolean' => is_bool( $value ),
			'string'  => is_string( $value ),
			'integer' => is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value && is_finite( $value ) ),
			'number'  => is_int( $value ) || is_float( $value ),
			// json_decode( '[]' ) and json_decode( '{}' ) both produce the empty
			// PHP array, so it has to satisfy either type; nothing downstream can
			// tell them apart either.
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ( ! array_is_list( $value ) || array() === $value ),
			default   => false,
		};
	}

	/**
	 * Check `enum` and `const`.
	 *
	 * @param mixed               $value   Value under test.
	 * @param array<string,mixed> $schema  Subschema.
	 * @param string              $pointer JSON pointer.
	 * @return void
	 */
	private function checkEnumAndConst( mixed $value, array $schema, string $pointer ): void {
		if ( array_key_exists( 'enum', $schema ) && is_array( $schema['enum'] ) ) {
			foreach ( $schema['enum'] as $candidate ) {
				if ( self::sameValue( $value, $candidate ) ) {
					return;
				}
			}

			$this->violate(
				$pointer,
				'enum',
				sprintf(
					'must be one of %s, got %s',
					implode( ', ', array_map( static fn ( $c ): string => self::literal( $c ), $schema['enum'] ) ),
					self::literal( $value )
				)
			);
		}

		if ( array_key_exists( 'const', $schema ) && ! self::sameValue( $value, $schema['const'] ) ) {
			$this->violate(
				$pointer,
				'const',
				sprintf( 'must be %s, got %s', self::literal( $schema['const'] ), self::literal( $value ) )
			);
		}
	}

	/**
	 * Check numeric constraints.
	 *
	 * @param mixed               $value   Value under test.
	 * @param array<string,mixed> $schema  Subschema.
	 * @param string              $pointer JSON pointer.
	 * @return void
	 */
	private function checkNumeric( mixed $value, array $schema, string $pointer ): void {
		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_float( $value ) ) ) {
			return;
		}

		$number = (float) $value;

		if ( array_key_exists( 'minimum', $schema ) && $number < (float) $schema['minimum'] ) {
			$this->violate( $pointer, 'minimum', sprintf( 'must be >= %s, got %s', $schema['minimum'], $value ) );
		}

		if ( array_key_exists( 'maximum', $schema ) && $number > (float) $schema['maximum'] ) {
			$this->violate( $pointer, 'maximum', sprintf( 'must be <= %s, got %s', $schema['maximum'], $value ) );
		}

		if ( array_key_exists( 'exclusiveMinimum', $schema ) && $number <= (float) $schema['exclusiveMinimum'] ) {
			$this->violate(
				$pointer,
				'exclusiveMinimum',
				sprintf( 'must be > %s, got %s', $schema['exclusiveMinimum'], $value )
			);
		}

		if ( array_key_exists( 'exclusiveMaximum', $schema ) && $number >= (float) $schema['exclusiveMaximum'] ) {
			$this->violate(
				$pointer,
				'exclusiveMaximum',
				sprintf( 'must be < %s, got %s', $schema['exclusiveMaximum'], $value )
			);
		}

		if ( array_key_exists( 'multipleOf', $schema ) ) {
			$divisor = (float) $schema['multipleOf'];

			if ( $divisor > 0.0 ) {
				$quotient = $number / $divisor;

				if ( abs( $quotient - round( $quotient ) ) > 1e-9 ) {
					$this->violate(
						$pointer,
						'multipleOf',
						sprintf( 'must be a multiple of %s, got %s', $schema['multipleOf'], $value )
					);
				}
			}
		}
	}

	/**
	 * Check string constraints.
	 *
	 * @param mixed               $value   Value under test.
	 * @param array<string,mixed> $schema  Subschema.
	 * @param string              $pointer JSON pointer.
	 * @return void
	 */
	private function checkString( mixed $value, array $schema, string $pointer ): void {
		if ( ! is_string( $value ) ) {
			return;
		}

		$length = mb_strlen( $value );

		if ( array_key_exists( 'minLength', $schema ) && $length < (int) $schema['minLength'] ) {
			$this->violate(
				$pointer,
				'minLength',
				sprintf( 'must be at least %d characters, got %d', (int) $schema['minLength'], $length )
			);
		}

		if ( array_key_exists( 'maxLength', $schema ) && $length > (int) $schema['maxLength'] ) {
			$this->violate(
				$pointer,
				'maxLength',
				sprintf( 'must be at most %d characters, got %d', (int) $schema['maxLength'], $length )
			);
		}

		if ( array_key_exists( 'pattern', $schema ) ) {
			$pattern = '/' . str_replace( '/', '\/', (string) $schema['pattern'] ) . '/u';

			if ( 1 !== preg_match( $pattern, $value ) ) {
				$this->violate(
					$pointer,
					'pattern',
					sprintf( 'must match %s, got "%s"', (string) $schema['pattern'], $value )
				);
			}
		}

		if ( array_key_exists( 'format', $schema ) ) {
			$format = (string) $schema['format'];

			if ( in_array( $format, self::ENFORCED_FORMATS, true ) && ! self::matchesFormat( $value, $format ) ) {
				$this->violate( $pointer, 'format', sprintf( 'must be a valid %s, got "%s"', $format, $value ) );
			}
		}
	}

	/**
	 * Whether a string satisfies an enforced format.
	 *
	 * @param string $value  String under test.
	 * @param string $format Format name.
	 * @return bool
	 */
	private static function matchesFormat( string $value, string $format ): bool {
		return match ( $format ) {
			'date-time' => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})?$/', $value ),
			'uri'       => 1 === preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $value ),
			'email'     => 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $value ),
			default     => true,
		};
	}

	/**
	 * Check array constraints.
	 *
	 * @param mixed               $value          Value under test.
	 * @param array<string,mixed> $schema         Subschema.
	 * @param string              $pointer        JSON pointer.
	 * @param string              $schema_pointer Schema pointer.
	 * @return void
	 */
	private function checkArray( mixed $value, array $schema, string $pointer, string $schema_pointer ): void {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return;
		}

		$count = count( $value );

		if ( array_key_exists( 'minItems', $schema ) && $count < (int) $schema['minItems'] ) {
			$this->violate(
				$pointer,
				'minItems',
				sprintf( 'must have at least %d items, got %d', (int) $schema['minItems'], $count )
			);
		}

		if ( array_key_exists( 'maxItems', $schema ) && $count > (int) $schema['maxItems'] ) {
			$this->violate(
				$pointer,
				'maxItems',
				sprintf( 'must have at most %d items, got %d', (int) $schema['maxItems'], $count )
			);
		}

		if ( array_key_exists( 'uniqueItems', $schema ) && true === $schema['uniqueItems'] ) {
			$seen = array();

			foreach ( $value as $item ) {
				$key = self::canonical( $item );

				if ( array_key_exists( $key, $seen ) ) {
					$this->violate( $pointer, 'uniqueItems', sprintf( 'contains the duplicate value %s', self::literal( $item ) ) );
					break;
				}

				$seen[ $key ] = true;
			}
		}

		if ( array_key_exists( 'items', $schema ) && is_array( $schema['items'] ) ) {
			/** @var array<string,mixed> $item_schema */
			$item_schema = $schema['items'];

			foreach ( $value as $index => $item ) {
				$this->check( $item, $item_schema, $pointer . '/' . $index, $schema_pointer . '/items' );
			}
		}

		if ( array_key_exists( 'contains', $schema ) && is_array( $schema['contains'] ) ) {
			/** @var array<string,mixed> $contains_schema */
			$contains_schema = $schema['contains'];
			$found           = false;

			foreach ( $value as $item ) {
				if ( $this->passes( $item, $contains_schema, $schema_pointer . '/contains' ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$this->violate( $pointer, 'contains', 'must contain at least one item matching the contains schema' );
			}
		}
	}

	/**
	 * Check object constraints.
	 *
	 * @param mixed               $value          Value under test.
	 * @param array<string,mixed> $schema         Subschema.
	 * @param string              $pointer        JSON pointer.
	 * @param string              $schema_pointer Schema pointer.
	 * @return void
	 */
	private function checkObject( mixed $value, array $schema, string $pointer, string $schema_pointer ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( array_is_list( $value ) && array() !== $value ) {
			return;
		}

		/** @var array<string,mixed> $object */
		$object = $value;

		if ( array_key_exists( 'required', $schema ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $required ) {
				if ( ! array_key_exists( (string) $required, $object ) ) {
					$this->violate(
						$pointer . '/' . (string) $required,
						'required',
						sprintf( 'property "%s" is required', (string) $required )
					);
				}
			}
		}

		$count = count( $object );

		if ( array_key_exists( 'minProperties', $schema ) && $count < (int) $schema['minProperties'] ) {
			$this->violate(
				$pointer,
				'minProperties',
				sprintf( 'must have at least %d properties, got %d', (int) $schema['minProperties'], $count )
			);
		}

		if ( array_key_exists( 'maxProperties', $schema ) && $count > (int) $schema['maxProperties'] ) {
			$this->violate(
				$pointer,
				'maxProperties',
				sprintf( 'must have at most %d properties, got %d', (int) $schema['maxProperties'], $count )
			);
		}

		$properties         = array_key_exists( 'properties', $schema ) && is_array( $schema['properties'] )
			? $schema['properties']
			: array();
		$pattern_properties = array_key_exists( 'patternProperties', $schema ) && is_array( $schema['patternProperties'] )
			? $schema['patternProperties']
			: array();

		foreach ( $object as $key => $item ) {
			$key            = (string) $key;
			$child_pointer  = $pointer . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), $key );
			$matched_schema = false;

			if ( array_key_exists( $key, $properties ) && is_array( $properties[ $key ] ) ) {
				/** @var array<string,mixed> $property_schema */
				$property_schema = $properties[ $key ];
				$matched_schema  = true;

				$this->check( $item, $property_schema, $child_pointer, $schema_pointer . '/properties/' . $key );
			}

			foreach ( $pattern_properties as $pattern => $pattern_schema ) {
				if ( ! is_array( $pattern_schema ) ) {
					continue;
				}

				$regex = '/' . str_replace( '/', '\/', (string) $pattern ) . '/u';

				if ( 1 === preg_match( $regex, $key ) ) {
					$matched_schema = true;

					/** @var array<string,mixed> $pattern_schema */
					$this->check( $item, $pattern_schema, $child_pointer, $schema_pointer . '/patternProperties' );
				}
			}

			if ( array_key_exists( 'propertyNames', $schema ) && is_array( $schema['propertyNames'] ) ) {
				/** @var array<string,mixed> $names_schema */
				$names_schema = $schema['propertyNames'];

				if ( ! $this->passes( $key, $names_schema, $schema_pointer . '/propertyNames' ) ) {
					$this->violate( $child_pointer, 'propertyNames', sprintf( 'property name "%s" is not allowed', $key ) );
				}
			}

			if ( $matched_schema || ! array_key_exists( 'additionalProperties', $schema ) ) {
				continue;
			}

			$additional = $schema['additionalProperties'];

			if ( false === $additional ) {
				$this->violate(
					$child_pointer,
					'additionalProperties',
					sprintf( 'property "%s" is not allowed here', $key )
				);
				continue;
			}

			if ( is_array( $additional ) ) {
				/** @var array<string,mixed> $additional */
				$this->check( $item, $additional, $child_pointer, $schema_pointer . '/additionalProperties' );
			}
		}
	}

	/**
	 * Check anyOf / oneOf / allOf / not.
	 *
	 * @param mixed               $value          Value under test.
	 * @param array<string,mixed> $schema         Subschema.
	 * @param string              $pointer        JSON pointer.
	 * @param string              $schema_pointer Schema pointer.
	 * @return void
	 */
	private function checkCombinators( mixed $value, array $schema, string $pointer, string $schema_pointer ): void {
		if ( array_key_exists( 'allOf', $schema ) && is_array( $schema['allOf'] ) ) {
			foreach ( $schema['allOf'] as $index => $subschema ) {
				if ( is_array( $subschema ) ) {
					/** @var array<string,mixed> $subschema */
					$this->check( $value, $subschema, $pointer, $schema_pointer . '/allOf/' . $index );
				}
			}
		}

		if ( array_key_exists( 'anyOf', $schema ) && is_array( $schema['anyOf'] ) ) {
			$matched = false;

			foreach ( $schema['anyOf'] as $index => $subschema ) {
				if ( is_array( $subschema ) && $this->passes( $value, $subschema, $schema_pointer . '/anyOf/' . $index ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				$this->violate( $pointer, 'anyOf', 'does not match any of the permitted schemas' );
			}
		}

		if ( array_key_exists( 'oneOf', $schema ) && is_array( $schema['oneOf'] ) ) {
			$matches = 0;

			foreach ( $schema['oneOf'] as $index => $subschema ) {
				if ( is_array( $subschema ) && $this->passes( $value, $subschema, $schema_pointer . '/oneOf/' . $index ) ) {
					++$matches;
				}
			}

			if ( 1 !== $matches ) {
				$this->violate(
					$pointer,
					'oneOf',
					sprintf( 'must match exactly one of the permitted schemas, matched %d', $matches )
				);
			}
		}

		if ( array_key_exists( 'not', $schema ) && is_array( $schema['not'] ) ) {
			/** @var array<string,mixed> $not_schema */
			$not_schema = $schema['not'];

			if ( $this->passes( $value, $not_schema, $schema_pointer . '/not' ) ) {
				$this->violate( $pointer, 'not', 'matches a schema it must not match' );
			}
		}
	}

	/**
	 * Whether a value passes a subschema, without recording violations.
	 *
	 * @param mixed               $value          Value under test.
	 * @param array<string,mixed> $subschema      Subschema.
	 * @param string              $schema_pointer Schema pointer.
	 * @return bool
	 */
	private function passes( mixed $value, array $subschema, string $schema_pointer ): bool {
		$saved            = $this->violations;
		$this->violations = array();

		$recorded = $this->check( $value, $subschema, '', $schema_pointer );

		$this->violations = $saved;

		return 0 === $recorded;
	}

	/**
	 * Record a violation.
	 *
	 * @param string $pointer JSON pointer.
	 * @param string $keyword Failing keyword.
	 * @param string $message Explanation.
	 * @return void
	 */
	private function violate( string $pointer, string $keyword, string $message ): void {
		$this->violations[] = new SchemaViolation( $pointer, $keyword, $message );
	}

	/**
	 * JSON-equality, which treats 1 and 1.0 as the same value.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private static function sameValue( mixed $a, mixed $b ): bool {
		if ( is_bool( $a ) || is_bool( $b ) || null === $a || null === $b ) {
			return $a === $b;
		}

		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return (float) $a === (float) $b;
		}

		if ( is_array( $a ) && is_array( $b ) ) {
			return self::canonical( $a ) === self::canonical( $b );
		}

		return $a === $b;
	}

	/**
	 * A canonical string form of a value, for equality and uniqueness checks.
	 *
	 * @param mixed $value Value to canonicalise.
	 * @return string
	 */
	private static function canonical( mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}

			$parts = array();

			foreach ( $value as $key => $item ) {
				$parts[] = $key . ':' . self::canonical( $item );
			}

			return '[' . implode( ',', $parts ) . ']';
		}

		return get_debug_type( $value ) . ':' . var_export( $value, true );
	}

	/**
	 * A short description of a value's type, for error messages.
	 *
	 * @param mixed $value Value to describe.
	 * @return string
	 */
	private static function describe( mixed $value ): string {
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? 'array' : 'object';
		}

		return match ( true ) {
			null === $value    => 'null',
			is_bool( $value )  => 'boolean',
			is_int( $value )   => 'integer',
			is_float( $value ) => 'number',
			is_string( $value ) => 'string',
			default             => get_debug_type( $value ),
		};
	}

	/**
	 * A short literal rendering of a value, for error messages.
	 *
	 * @param mixed $value Value to render.
	 * @return string
	 */
	private static function literal( mixed $value ): string {
		if ( is_string( $value ) ) {
			return '"' . $value . '"';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		if ( is_array( $value ) ) {
			return self::describe( $value );
		}

		return (string) $value;
	}
}
