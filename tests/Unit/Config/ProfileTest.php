<?php
/**
 * The profile document.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Config;

use Debloater\Config\Profile;
use Debloater\Contracts\ContractViolation;
use Debloater\Recommend\IntentProfile;
use Debloater\Registry\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * BUILD-SPEC §7.3 and §17 Phase 19c.
 *
 * A profile is a file that travels between sites, so the things worth asserting
 * are the ones that survive the journey: that what comes out equals what went
 * in, that the document matches the schema it claims to, and that it carries
 * nothing about the site that produced it.
 */
final class ProfileTest extends TestCase {

	/**
	 * Export then import gives back the same selection.
	 *
	 * @return void
	 */
	public function test_a_profile_survives_a_round_trip(): void {
		$profile = $this->profile();

		$json = $profile->toJson();

		$decoded = json_decode( $json, true );

		$this->assertIsArray( $decoded );

		$back = Profile::fromArray( $decoded );

		$this->assertSame( $profile->name, $back->name );
		$this->assertSame( $profile->created_at, $back->created_at );
		$this->assertSame( $profile->registry_hash, $back->registry_hash );
		$this->assertSame( $profile->selection, $back->selection );
		$this->assertSame( $profile->intent->toArray(), $back->intent->toArray() );

		// And exporting it again produces the same bytes, which is what makes
		// "the CLI and the screen produce identical files" checkable at all.
		$this->assertSame( $json, $back->toJson() );
	}

	/**
	 * The document is what the schema says a profile is.
	 *
	 * @return void
	 */
	public function test_the_document_matches_the_schema(): void {
		$schema = json_decode(
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/schemas/profile-export.schema.json' ),
			true
		);

		$this->assertIsArray( $schema );

		$errors = ( new SchemaValidator( $schema ) )->validate(
			json_decode( $this->profile()->toJson(), true )
		);

		$this->assertSame( array(), $errors, implode( "\n", $errors ) );
	}

	/**
	 * A change selected with no parameters is an object, not an empty list.
	 *
	 * The schema asks for an object there. An empty PHP array encodes as `[]`,
	 * so casting only the outer map produced a document this plugin wrote and
	 * then refused to read — which is the kind of thing that is obvious once
	 * seen and invisible until somebody imports a file.
	 *
	 * @return void
	 */
	public function test_a_change_with_no_parameters_encodes_as_an_object(): void {
		$json = $this->profile()->toJson();

		$this->assertStringContainsString( '"core.remove_rsd": {}', $json );
		$this->assertStringNotContainsString( '"core.remove_rsd": []', $json );
	}

	/**
	 * Nothing in a profile says where it came from.
	 *
	 * @return void
	 */
	public function test_a_profile_carries_no_site_identifier(): void {
		$document = $this->profile()->toArray();

		foreach ( array( 'site_hash', 'site_url', 'home_url', 'plugin_version', 'exported_at' ) as $field ) {
			$this->assertArrayNotHasKey(
				$field,
				$document,
				sprintf( '"%s" would tell the next site where this profile came from.', $field )
			);
		}

		$this->assertSame(
			array( 'schema_version', 'name', 'created_at', 'registry_hash', 'intent_profile', 'selection' ),
			array_keys( $document )
		);
	}

	/**
	 * A name is required, and bounded.
	 *
	 * @return void
	 */
	public function test_a_profile_needs_a_usable_name(): void {
		$this->expectException( ContractViolation::class );

		new Profile( '   ', array(), new IntentProfile() );
	}

	/**
	 * A name longer than the schema allows is refused.
	 *
	 * @return void
	 */
	public function test_an_overlong_name_is_refused(): void {
		$this->expectException( ContractViolation::class );

		new Profile( str_repeat( 'x', Profile::MAX_NAME + 1 ), array(), new IntentProfile() );
	}

	/**
	 * A document from a future version is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function test_a_future_schema_version_is_refused(): void {
		$document = $this->profile()->toArray();

		$document['schema_version'] = Profile::SCHEMA_VERSION + 1;

		$this->expectException( ContractViolation::class );

		Profile::fromArray( $document );
	}

	/**
	 * Renaming changes the name and nothing else.
	 *
	 * @return void
	 */
	public function test_renaming_keeps_everything_else(): void {
		$profile = $this->profile();
		$renamed = $profile->renamed( 'Something else' );

		$this->assertSame( 'Something else', $renamed->name );
		$this->assertSame( $profile->selection, $renamed->selection );
		$this->assertSame( $profile->created_at, $renamed->created_at );
		$this->assertSame( $profile->registry_hash, $renamed->registry_hash );
	}

	/**
	 * A profile to test with.
	 *
	 * @return Profile
	 */
	private function profile(): Profile {
		return new Profile(
			'Client baseline',
			array(
				'core.remove_rsd'      => array(),
				'core.limit_revisions' => array( 'keep' => 5 ),
			),
			new IntentProfile( 'blog', 'balanced' ),
			str_repeat( 'a', 64 ),
			'2026-01-01T00:00:00Z'
		);
	}
}
