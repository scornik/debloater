<?php
/**
 * Tests for the hand-written JSON Schema subset validator.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use WPDebloat\Registry\SchemaValidator;
use WPDebloat\Registry\UnsupportedSchemaKeyword;

/**
 * The validator stands between user input and generated PHP (BUILD-SPEC §13
 * rule 5), so each supported keyword is tested for both acceptance and
 * rejection, and the unsupported-keyword guard is tested explicitly.
 */
final class SchemaValidatorTest extends TestCase {

	/**
	 * type accepts the right primitive and rejects the others.
	 *
	 * @return void
	 */
	public function test_type_checking(): void {
		$validator = new SchemaValidator( array( 'type' => 'integer' ) );

		$this->assertTrue( $validator->isValid( 5 ) );
		$this->assertFalse( $validator->isValid( '5' ) );
		$this->assertFalse( $validator->isValid( 5.5 ) );
		$this->assertFalse( $validator->isValid( true ) );
	}

	/**
	 * A union type accepts any member.
	 *
	 * @return void
	 */
	public function test_union_types(): void {
		$validator = new SchemaValidator( array( 'type' => array( 'string', 'null' ) ) );

		$this->assertTrue( $validator->isValid( 'x' ) );
		$this->assertTrue( $validator->isValid( null ) );
		$this->assertFalse( $validator->isValid( 1 ) );
	}

	/**
	 * JSON has no separate object and array types in PHP, so lists and maps are
	 * told apart by shape.
	 *
	 * @return void
	 */
	public function test_arrays_and_objects_are_distinguished(): void {
		$array_validator  = new SchemaValidator( array( 'type' => 'array' ) );
		$object_validator = new SchemaValidator( array( 'type' => 'object' ) );

		$this->assertTrue( $array_validator->isValid( array( 1, 2 ) ) );
		$this->assertFalse( $array_validator->isValid( array( 'a' => 1 ) ) );
		$this->assertTrue( $object_validator->isValid( array( 'a' => 1 ) ) );
		$this->assertFalse( $object_validator->isValid( array( 1, 2 ) ) );
	}

	/**
	 * The empty array satisfies both, since JSON cannot tell them apart either.
	 *
	 * @return void
	 */
	public function test_the_empty_array_satisfies_array_and_object(): void {
		$this->assertTrue( ( new SchemaValidator( array( 'type' => 'array' ) ) )->isValid( array() ) );
		$this->assertTrue( ( new SchemaValidator( array( 'type' => 'object' ) ) )->isValid( array() ) );
	}

	/**
	 * enum and const restrict to a fixed set.
	 *
	 * @return void
	 */
	public function test_enum_and_const(): void {
		$enum = new SchemaValidator( array( 'enum' => array( 'safe', 'low' ) ) );

		$this->assertTrue( $enum->isValid( 'safe' ) );
		$this->assertFalse( $enum->isValid( 'high' ) );

		$const = new SchemaValidator( array( 'const' => 1 ) );

		$this->assertTrue( $const->isValid( 1 ) );
		$this->assertFalse( $const->isValid( 2 ) );
	}

	/**
	 * A failing enum names the permitted values, so a registry author can fix it.
	 *
	 * @return void
	 */
	public function test_enum_violation_names_the_allowed_values(): void {
		$violations = ( new SchemaValidator( array( 'enum' => array( 'safe', 'low' ) ) ) )->validate( 'high' );

		$this->assertCount( 1, $violations );
		$this->assertSame( 'enum', $violations[0]->keyword );
		$this->assertStringContainsString( '"safe", "low"', $violations[0]->message );
	}

	/**
	 * required reports the missing property by pointer.
	 *
	 * @return void
	 */
	public function test_required_properties(): void {
		$validator = new SchemaValidator(
			array(
				'type'     => 'object',
				'required' => array( 'id', 'title' ),
			)
		);

		$violations = $validator->validate( array( 'id' => 'x' ) );

		$this->assertCount( 1, $violations );
		$this->assertSame( '/title', $violations[0]->pointer );
		$this->assertSame( 'required', $violations[0]->keyword );
	}

	/**
	 * additionalProperties false rejects anything not declared.
	 *
	 * @return void
	 */
	public function test_additional_properties_false(): void {
		$validator = new SchemaValidator(
			array(
				'type'                 => 'object',
				'properties'           => array( 'id' => array( 'type' => 'string' ) ),
				'additionalProperties' => false,
			)
		);

		$this->assertTrue( $validator->isValid( array( 'id' => 'x' ) ) );

		$violations = $validator->validate(
			array(
				'id'    => 'x',
				'extra' => 1,
			)
		);

		$this->assertCount( 1, $violations );
		$this->assertSame( 'additionalProperties', $violations[0]->keyword );
	}

	/**
	 * additionalProperties as a schema validates the remaining values.
	 *
	 * @return void
	 */
	public function test_additional_properties_schema(): void {
		$validator = new SchemaValidator(
			array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'boolean' ),
			)
		);

		$this->assertTrue( $validator->isValid( array( 'woocommerce' => true ) ) );
		$this->assertFalse( $validator->isValid( array( 'woocommerce' => 'yes' ) ) );
	}

	/**
	 * propertyNames constrains keys, which is how detector fact paths are
	 * validated.
	 *
	 * @return void
	 */
	public function test_property_names(): void {
		$validator = new SchemaValidator(
			array(
				'type'          => 'object',
				'propertyNames' => array( 'pattern' => '^[a-z_]+$' ),
			)
		);

		$this->assertTrue( $validator->isValid( array( 'interval' => 1 ) ) );
		$this->assertFalse( $validator->isValid( array( 'Interval-1' => 1 ) ) );
	}

	/**
	 * patternProperties applies a schema to matching keys.
	 *
	 * @return void
	 */
	public function test_pattern_properties(): void {
		$validator = new SchemaValidator(
			array(
				'type'              => 'object',
				'patternProperties' => array( '^count_' => array( 'type' => 'integer' ) ),
			)
		);

		$this->assertTrue( $validator->isValid( array( 'count_a' => 1 ) ) );
		$this->assertFalse( $validator->isValid( array( 'count_a' => 'one' ) ) );
	}

	/**
	 * Numeric bounds, inclusive and exclusive.
	 *
	 * @return void
	 */
	public function test_numeric_bounds(): void {
		$validator = new SchemaValidator(
			array(
				'minimum' => 15,
				'maximum' => 120,
			)
		);

		$this->assertTrue( $validator->isValid( 15 ) );
		$this->assertTrue( $validator->isValid( 120 ) );
		$this->assertFalse( $validator->isValid( 14 ) );
		$this->assertFalse( $validator->isValid( 121 ) );

		$exclusive = new SchemaValidator(
			array(
				'exclusiveMinimum' => 0,
				'exclusiveMaximum' => 1,
			)
		);

		$this->assertTrue( $exclusive->isValid( 0.5 ) );
		$this->assertFalse( $exclusive->isValid( 0 ) );
		$this->assertFalse( $exclusive->isValid( 1 ) );
	}

	/**
	 * multipleOf, used for step-constrained parameters.
	 *
	 * @return void
	 */
	public function test_multiple_of(): void {
		$validator = new SchemaValidator( array( 'multipleOf' => 15 ) );

		$this->assertTrue( $validator->isValid( 60 ) );
		$this->assertFalse( $validator->isValid( 61 ) );
	}

	/**
	 * String length and pattern.
	 *
	 * @return void
	 */
	public function test_string_constraints(): void {
		$validator = new SchemaValidator(
			array(
				'type'      => 'string',
				'minLength' => 2,
				'maxLength' => 4,
				'pattern'   => '^[a-z]+$',
			)
		);

		$this->assertTrue( $validator->isValid( 'abc' ) );
		$this->assertFalse( $validator->isValid( 'a' ) );
		$this->assertFalse( $validator->isValid( 'abcde' ) );
		$this->assertFalse( $validator->isValid( 'AB' ) );
	}

	/**
	 * A pattern containing a slash must not break the delimiter.
	 *
	 * @return void
	 */
	public function test_pattern_with_a_slash(): void {
		$validator = new SchemaValidator( array( 'pattern' => '^[a-z-]+/[a-z-]+\.php$' ) );

		$this->assertTrue( $validator->isValid( 'woocommerce/woocommerce.php' ) );
		$this->assertFalse( $validator->isValid( 'woocommerce.php' ) );
	}

	/**
	 * Enforced formats are checked; unknown formats are advisory.
	 *
	 * @return void
	 */
	public function test_formats(): void {
		$uri = new SchemaValidator(
			array(
				'type'   => 'string',
				'format' => 'uri',
			)
		);

		$this->assertTrue( $uri->isValid( 'https://example.test/docs' ) );
		$this->assertFalse( $uri->isValid( 'not a uri' ) );

		$date = new SchemaValidator(
			array(
				'type'   => 'string',
				'format' => 'date-time',
			)
		);

		$this->assertTrue( $date->isValid( '2026-09-02T18:34:00Z' ) );
		$this->assertFalse( $date->isValid( 'yesterday' ) );

		$advisory = new SchemaValidator(
			array(
				'type'   => 'string',
				'format' => 'hostname',
			)
		);

		$this->assertTrue( $advisory->isValid( 'anything at all' ) );
	}

	/**
	 * Array item schemas, bounds and uniqueness.
	 *
	 * @return void
	 */
	public function test_array_constraints(): void {
		$validator = new SchemaValidator(
			array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'minItems'    => 1,
				'maxItems'    => 2,
				'uniqueItems' => true,
			)
		);

		$this->assertTrue( $validator->isValid( array( 'a' ) ) );
		$this->assertFalse( $validator->isValid( array() ) );
		$this->assertFalse( $validator->isValid( array( 'a', 'b', 'c' ) ) );
		$this->assertFalse( $validator->isValid( array( 'a', 'a' ) ) );
		$this->assertFalse( $validator->isValid( array( 'a', 1 ) ) );
	}

	/**
	 * Item violations are located by index.
	 *
	 * @return void
	 */
	public function test_item_violations_are_located_by_index(): void {
		$validator = new SchemaValidator(
			array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			)
		);

		$violations = $validator->validate( array( 'a', 1 ) );

		$this->assertCount( 1, $violations );
		$this->assertSame( '/1', $violations[0]->pointer );
	}

	/**
	 * anyOf, oneOf, allOf and not.
	 *
	 * @return void
	 */
	public function test_combinators(): void {
		$any_of = new SchemaValidator(
			array( 'anyOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) )
		);

		$this->assertTrue( $any_of->isValid( 'x' ) );
		$this->assertTrue( $any_of->isValid( 1 ) );
		$this->assertFalse( $any_of->isValid( 1.5 ) );

		$one_of = new SchemaValidator(
			array( 'oneOf' => array( array( 'minimum' => 0 ), array( 'maximum' => 10 ) ) )
		);

		$this->assertFalse( $one_of->isValid( 5 ), '5 matches both branches, so oneOf must fail' );
		$this->assertTrue( $one_of->isValid( 50 ) );

		$all_of = new SchemaValidator(
			array( 'allOf' => array( array( 'type' => 'integer' ), array( 'minimum' => 10 ) ) )
		);

		$this->assertTrue( $all_of->isValid( 10 ) );
		$this->assertFalse( $all_of->isValid( 9 ) );

		$not = new SchemaValidator( array( 'not' => array( 'type' => 'string' ) ) );

		$this->assertTrue( $not->isValid( 1 ) );
		$this->assertFalse( $not->isValid( 'x' ) );
	}

	/**
	 * Local $ref resolves against definitions.
	 *
	 * @return void
	 */
	public function test_local_ref(): void {
		$validator = new SchemaValidator(
			array(
				'type'        => 'object',
				'properties'  => array( 'risk' => array( '$ref' => '#/definitions/risk' ) ),
				'definitions' => array( 'risk' => array( 'enum' => array( 'safe', 'low' ) ) ),
			)
		);

		$this->assertTrue( $validator->isValid( array( 'risk' => 'safe' ) ) );
		$this->assertFalse( $validator->isValid( array( 'risk' => 'high' ) ) );
	}

	/**
	 * A remote $ref would mean a network request, which BUILD-SPEC §13 rule 9
	 * forbids.
	 *
	 * @return void
	 */
	public function test_remote_ref_is_refused(): void {
		$validator = new SchemaValidator( array( '$ref' => 'https://example.test/other.json' ) );

		$this->expectException( UnsupportedSchemaKeyword::class );

		$validator->validate( array() );
	}

	/**
	 * An unresolvable local $ref is an authoring error, not a pass.
	 *
	 * @return void
	 */
	public function test_unresolvable_ref_is_refused(): void {
		$validator = new SchemaValidator( array( '$ref' => '#/definitions/missing' ) );

		$this->expectException( UnsupportedSchemaKeyword::class );

		$validator->validate( array() );
	}

	/**
	 * An unimplemented keyword fails loudly rather than being ignored
	 * (docs/DECISIONS.md D-0001).
	 *
	 * @return void
	 */
	public function test_unsupported_keyword_throws(): void {
		$validator = new SchemaValidator( array( 'dependentRequired' => array( 'a' => array( 'b' ) ) ) );

		$this->expectException( UnsupportedSchemaKeyword::class );
		$this->expectExceptionMessageMatches( '/dependentRequired/' );

		$validator->validate( array( 'a' => 1 ) );
	}

	/**
	 * All violations are collected, not just the first.
	 *
	 * @return void
	 */
	public function test_all_violations_are_reported(): void {
		$validator = new SchemaValidator(
			array(
				'type'       => 'object',
				'required'   => array( 'a', 'b' ),
				'properties' => array( 'c' => array( 'type' => 'integer' ) ),
			)
		);

		$violations = $validator->validate( array( 'c' => 'not an integer' ) );

		$this->assertCount( 3, $violations );
	}

	/**
	 * assertValid throws with every violation in the message.
	 *
	 * @return void
	 */
	public function test_assert_valid_reports_every_violation(): void {
		$validator = new SchemaValidator(
			array(
				'type'     => 'object',
				'required' => array( 'a', 'b' ),
			)
		);

		try {
			$validator->assertValid( array(), 'tweak.json' );
			$this->fail( 'assertValid should have thrown' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'tweak.json failed schema validation', $exception->getMessage() );
			$this->assertStringContainsString( '/a', $exception->getMessage() );
			$this->assertStringContainsString( '/b', $exception->getMessage() );
		}
	}

	/**
	 * Nested violations carry a full JSON pointer.
	 *
	 * @return void
	 */
	public function test_nested_pointers(): void {
		$validator = new SchemaValidator(
			array(
				'type'       => 'object',
				'properties' => array(
					'params' => array(
						'type'       => 'object',
						'properties' => array( 'interval' => array( 'type' => 'integer' ) ),
					),
				),
			)
		);

		$violations = $validator->validate( array( 'params' => array( 'interval' => 'sixty' ) ) );

		$this->assertCount( 1, $violations );
		$this->assertSame( '/params/interval', $violations[0]->pointer );
	}

	/**
	 * A validator can be built from a file on disk.
	 *
	 * @return void
	 */
	public function test_from_file(): void {
		$validator = SchemaValidator::fromFile( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/tweak.schema.json' );

		$this->assertFalse( $validator->isValid( array() ) );
	}

	/**
	 * A missing schema file is an error, not an empty schema that passes
	 * everything.
	 *
	 * @return void
	 */
	public function test_from_file_rejects_a_missing_file(): void {
		$this->expectException( \RuntimeException::class );

		SchemaValidator::fromFile( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/does-not-exist.json' );
	}
}
