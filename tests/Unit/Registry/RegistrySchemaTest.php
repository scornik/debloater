<?php
/**
 * The registry schemas must accept the specification's own examples and reject
 * documents that break its rules.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Identifier;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Registry\SchemaValidator;

/**
 * Two things are checked here: that the shipped schemas behave, and that they
 * agree with the PHP contracts. A schema and a contract that disagree would let
 * a document pass one gate and fail the other, which is exactly the drift the
 * enums exist to prevent.
 */
final class RegistrySchemaTest extends TestCase {

	/**
	 * Every shipped schema must itself be loadable and usable.
	 *
	 * @dataProvider schemaProvider
	 * @param string $schema Schema file name.
	 * @return void
	 */
	public function test_schema_loads( string $schema ): void {
		$validator = SchemaValidator::fromFile( self::schemaPath( $schema ) );

		// Validating an empty object must not throw: it exercises every keyword
		// in the schema, which is how an unsupported keyword is caught.
		$validator->validate( array() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The FactSet example from BUILD-SPEC §5 validates.
	 *
	 * @return void
	 */
	public function test_full_stack_facts_validate(): void {
		$this->assertValidates( 'fact.schema.json', 'facts/full-stack.json' );
	}

	/**
	 * BUILD-SPEC §5: unknown fact keys fail validation, so a scanner cannot
	 * quietly invent a namespace.
	 *
	 * @return void
	 */
	public function test_unknown_fact_keys_are_rejected(): void {
		$violations = $this->violationsFor( 'fact.schema.json', 'invalid/fact-unknown-key.json' );

		$this->assertNotEmpty( $violations );
		$this->assertSame( 'additionalProperties', $violations[0]->keyword );
	}

	/**
	 * A fact with the wrong type is rejected.
	 *
	 * @return void
	 */
	public function test_fact_types_are_enforced(): void {
		$this->assertNotEmpty( $this->violationsFor( 'fact.schema.json', 'invalid/fact-wrong-type.json' ) );
	}

	/**
	 * The Finding example from BUILD-SPEC §6 validates field for field.
	 *
	 * @return void
	 */
	public function test_heartbeat_finding_validates(): void {
		$this->assertValidates( 'finding.schema.json', 'findings/heartbeat-aggressive.json' );
	}

	/**
	 * Locked decision #5: a finding without evidence is invalid.
	 *
	 * @return void
	 */
	public function test_a_finding_without_evidence_is_rejected(): void {
		$violations = $this->violationsFor( 'finding.schema.json', 'invalid/finding-no-evidence.json' );

		$this->assertNotEmpty( $violations );
		$this->assertSame( 'minItems', $violations[0]->keyword );
	}

	/**
	 * Risk is a closed vocabulary.
	 *
	 * @return void
	 */
	public function test_an_unknown_risk_is_rejected(): void {
		$this->assertNotEmpty( $this->violationsFor( 'finding.schema.json', 'invalid/finding-bad-risk.json' ) );
	}

	/**
	 * Confidence is a probability.
	 *
	 * @return void
	 */
	public function test_confidence_above_one_is_rejected(): void {
		$this->assertNotEmpty(
			$this->violationsFor( 'finding.schema.json', 'invalid/finding-confidence-out-of-range.json' )
		);
	}

	/**
	 * The Tweak example from BUILD-SPEC §7.1 validates.
	 *
	 * @return void
	 */
	public function test_heartbeat_tweak_validates(): void {
		$this->assertValidates( 'tweak.schema.json', 'tweaks/core.heartbeat_interval.json' );
	}

	/**
	 * A tweak with no handler cannot be applied, so it cannot be valid.
	 *
	 * @return void
	 */
	public function test_a_tweak_without_a_handler_is_rejected(): void {
		$this->assertNotEmpty( $this->violationsFor( 'tweak.schema.json', 'invalid/tweak-missing-handler.json' ) );
	}

	/**
	 * Tweak ids are dot-namespaced and lowercase.
	 *
	 * @return void
	 */
	public function test_a_malformed_tweak_id_is_rejected(): void {
		$violations = $this->violationsFor( 'tweak.schema.json', 'invalid/tweak-bad-id.json' );

		$this->assertNotEmpty( $violations );
		$this->assertSame( '/id', $violations[0]->pointer );
	}

	/**
	 * The compatibility example from BUILD-SPEC §7.2 validates.
	 *
	 * @return void
	 */
	public function test_compatibility_rule_validates(): void {
		$this->assertValidates( 'compat.schema.json', 'compatibility/contact-form-7.json' );
	}

	/**
	 * BUILD-SPEC §7.2 fixes the `requires` vocabulary, so the resolver can
	 * reason about it exhaustively.
	 *
	 * @return void
	 */
	public function test_an_unknown_requirement_is_rejected(): void {
		$this->assertNotEmpty(
			$this->violationsFor( 'compat.schema.json', 'invalid/compat-unknown-requirement.json' )
		);
	}

	/**
	 * The whole documented requirement vocabulary is accepted.
	 *
	 * @return void
	 */
	public function test_the_full_requirement_vocabulary_is_accepted(): void {
		$validator = SchemaValidator::fromFile( self::schemaPath( 'compat.schema.json' ) );

		$document = array(
			'subject'  => 'plugin:example',
			'requires' => array(
				'rest:public',
				'rest:auth',
				'jquery',
				'jquery-migrate',
				'heartbeat',
				'xmlrpc',
				'embeds',
				'dashicons:frontend',
				'cron:wp',
			),
		);

		$this->assertSame( array(), $validator->validate( $document ) );
	}

	/**
	 * The profile example from BUILD-SPEC §7.3 validates.
	 *
	 * @return void
	 */
	public function test_safe_profile_validates(): void {
		$this->assertValidates( 'profile.schema.json', 'profiles/safe.json' );
	}

	/**
	 * A profile that includes no risk level would select nothing; it is an
	 * authoring error, not an empty profile.
	 *
	 * @return void
	 */
	public function test_a_profile_with_no_risk_levels_is_rejected(): void {
		$this->assertNotEmpty( $this->violationsFor( 'profile.schema.json', 'invalid/profile-empty-risk.json' ) );
	}

	/**
	 * The detector example from BUILD-SPEC §7.5 validates.
	 *
	 * @return void
	 */
	public function test_detector_validates(): void {
		$this->assertValidates( 'detector.schema.json', 'detectors/woocommerce.json' );
	}

	/**
	 * A detector with nothing to match on would match everything.
	 *
	 * @return void
	 */
	public function test_a_detector_without_signals_is_rejected(): void {
		$this->assertNotEmpty( $this->violationsFor( 'detector.schema.json', 'invalid/detector-no-match.json' ) );
	}

	/**
	 * The severity vocabulary in the schema matches the Severity enum.
	 *
	 * @return void
	 */
	public function test_severity_vocabulary_matches_the_enum(): void {
		$this->assertSame(
			array_map( static fn ( Severity $enum_case ): string => $enum_case->value, Severity::cases() ),
			$this->definitionEnum( 'finding.schema.json', 'severity' )
		);
	}

	/**
	 * The risk vocabulary in the schema matches the Risk enum.
	 *
	 * @return void
	 */
	public function test_risk_vocabulary_matches_the_enum(): void {
		$this->assertSame(
			array_map( static fn ( Risk $enum_case ): string => $enum_case->value, Risk::cases() ),
			$this->definitionEnum( 'finding.schema.json', 'risk' )
		);
	}

	/**
	 * The decision vocabulary in the schema matches the Decision enum.
	 *
	 * @return void
	 */
	public function test_decision_vocabulary_matches_the_enum(): void {
		$this->assertSame(
			array_map( static fn ( Decision $enum_case ): string => $enum_case->value, Decision::cases() ),
			$this->definitionEnum( 'finding.schema.json', 'decision' )
		);
	}

	/**
	 * The category vocabulary in the schema matches the Category enum.
	 *
	 * @return void
	 */
	public function test_category_vocabulary_matches_the_enum(): void {
		$this->assertSame(
			array_map( static fn ( Category $enum_case ): string => $enum_case->value, Category::cases() ),
			$this->definitionEnum( 'finding.schema.json', 'category' )
		);
	}

	/**
	 * The tweak schema's own enums match the contracts too.
	 *
	 * @return void
	 */
	public function test_tweak_schema_vocabularies_match_the_enums(): void {
		$schema = $this->decode( self::schemaPath( 'tweak.schema.json' ) );

		$this->assertSame(
			array_map( static fn ( TweakKind $enum_case ): string => $enum_case->value, TweakKind::cases() ),
			$schema['properties']['kind']['enum']
		);
		$this->assertSame(
			array_map( static fn ( Risk $enum_case ): string => $enum_case->value, Risk::cases() ),
			$schema['properties']['risk']['enum']
		);
		$this->assertSame(
			array_map( static fn ( Category $enum_case ): string => $enum_case->value, Category::cases() ),
			$schema['properties']['category']['enum']
		);
	}

	/**
	 * The id patterns in the schemas are the same grammar the contracts enforce.
	 *
	 * A string is used rather than comparing the raw patterns, because the PHP
	 * constants carry delimiters the JSON ones do not.
	 *
	 * @return void
	 */
	public function test_id_patterns_agree_with_the_contracts(): void {
		$tweak_pattern   = $this->decode( self::schemaPath( 'tweak.schema.json' ) )['properties']['id']['pattern'];
		$finding_pattern = $this->decode( self::schemaPath( 'finding.schema.json' ) )['properties']['id']['pattern'];

		$valid_tweaks   = array( 'core.disable_emojis', 'db.clean_expired_transients', 'woo.cart_fragments_conditional' );
		$invalid_tweaks = array( 'Core.DisableEmojis', 'core', 'core..emojis', 'core.Disable' );

		foreach ( $valid_tweaks as $id ) {
			$this->assertSame(
				1 === preg_match( Identifier::TWEAK_ID_PATTERN, $id ),
				1 === preg_match( '/' . $tweak_pattern . '/', $id ),
				$id
			);
			$this->assertSame( 1, preg_match( Identifier::TWEAK_ID_PATTERN, $id ), $id );
		}

		foreach ( $invalid_tweaks as $id ) {
			$this->assertSame( 0, preg_match( Identifier::TWEAK_ID_PATTERN, $id ), $id );
			$this->assertSame( 0, preg_match( '/' . $tweak_pattern . '/', $id ), $id );
		}

		$this->assertSame( 1, preg_match( '/' . $finding_pattern . '/', 'wp.heartbeat.aggressive' ) );
		$this->assertSame( 1, preg_match( Identifier::FINDING_ID_PATTERN, 'wp.heartbeat.aggressive' ) );
	}

	/**
	 * The fact-key pattern used inside evidence agrees with the contract.
	 *
	 * @return void
	 */
	public function test_fact_key_pattern_agrees_with_the_contract(): void {
		$schema  = $this->decode( self::schemaPath( 'finding.schema.json' ) );
		$pattern = $schema['properties']['evidence']['items']['properties']['fact']['pattern'];

		foreach ( array( 'wp.heartbeat_interval', 'db.autoload.bytes', 'plugins.detected.contact-form-7' ) as $key ) {
			$this->assertSame( 1, preg_match( '/' . $pattern . '/', $key ), $key );
			$this->assertSame( 1, preg_match( Identifier::FACT_KEY_PATTERN, $key ), $key );
		}

		foreach ( array( 'heartbeat', 'WP.Heartbeat' ) as $key ) {
			$this->assertSame( 0, preg_match( '/' . $pattern . '/', $key ), $key );
			$this->assertSame( 0, preg_match( Identifier::FACT_KEY_PATTERN, $key ), $key );
		}
	}

	/**
	 * Schema files shipped in registry/schemas.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function schemaProvider(): array {
		return array(
			'fact'     => array( 'fact.schema.json' ),
			'finding'  => array( 'finding.schema.json' ),
			'tweak'    => array( 'tweak.schema.json' ),
			'compat'   => array( 'compat.schema.json' ),
			'profile'  => array( 'profile.schema.json' ),
			'detector' => array( 'detector.schema.json' ),
		);
	}

	/**
	 * Assert a fixture validates cleanly, reporting every violation on failure.
	 *
	 * @param string $schema  Schema file name.
	 * @param string $fixture Fixture path relative to tests/Fixtures.
	 * @return void
	 */
	private function assertValidates( string $schema, string $fixture ): void {
		$violations = $this->violationsFor( $schema, $fixture );

		$this->assertSame(
			array(),
			$violations,
			$fixture . ' should validate: ' . implode( '; ', array_map( 'strval', $violations ) )
		);
	}

	/**
	 * Validate a fixture and return the violations.
	 *
	 * @param string $schema  Schema file name.
	 * @param string $fixture Fixture path relative to tests/Fixtures.
	 * @return array<int,\WPDebloat\Registry\SchemaViolation>
	 */
	private function violationsFor( string $schema, string $fixture ): array {
		$validator = SchemaValidator::fromFile( self::schemaPath( $schema ) );

		return $validator->validate( $this->decode( WPDEBLOAT_TESTS_ROOT . '/tests/Fixtures/' . $fixture ) );
	}

	/**
	 * The enum list of a named definition in a schema.
	 *
	 * @param string $schema     Schema file name.
	 * @param string $definition Definition name.
	 * @return array<int,string>
	 */
	private function definitionEnum( string $schema, string $definition ): array {
		$decoded = $this->decode( self::schemaPath( $schema ) );

		$this->assertArrayHasKey( 'definitions', $decoded );
		$this->assertArrayHasKey( $definition, $decoded['definitions'] );

		return $decoded['definitions'][ $definition ]['enum'];
	}

	/**
	 * Decode a JSON file, failing the test when it is malformed.
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 */
	private function decode( string $path ): array {
		$raw = file_get_contents( $path );

		$this->assertIsString( $raw, 'could not read ' . $path );

		$decoded = json_decode( $raw, true );

		$this->assertIsArray( $decoded, $path . ' is not valid JSON: ' . json_last_error_msg() );

		return $decoded;
	}

	/**
	 * Absolute path of a shipped schema.
	 *
	 * @param string $schema Schema file name.
	 * @return string
	 */
	private static function schemaPath( string $schema ): string {
		return WPDEBLOAT_TESTS_ROOT . '/registry/schemas/' . $schema;
	}
}
