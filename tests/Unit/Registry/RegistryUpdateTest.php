<?php
/**
 * What a registry update is allowed to install.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Debloater\Update\Manifest;
use Debloater\Update\RegistryOrigin;
use Debloater\Update\SignatureVerifier;

/**
 * BUILD-SPEC §13 rule 9, §17 Phase 17.
 *
 * The registry decides what Debloater offers to change about a site, so
 * replacing it is a security operation. These tests are mostly about the things
 * that must be refused.
 *
 * The keypair is generated inside the test and never written anywhere. A test
 * key committed to the repository would be a key in the package, and the whole
 * argument for signing is that no key is.
 */
final class RegistryUpdateTest extends TestCase {

	/**
	 * A keypair for this test run only.
	 *
	 * @var array{public:string,secret:string}
	 */
	private array $keys;

	/**
	 * Generate the keypair.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'libsodium is not available in this PHP.' );
		}

		$pair = sodium_crypto_sign_keypair();

		$this->keys = array(
			'public' => bin2hex( sodium_crypto_sign_publickey( $pair ) ),
			'secret' => sodium_crypto_sign_secretkey( $pair ),
		);
	}

	/**
	 * A correctly signed manifest verifies.
	 *
	 * @return void
	 */
	public function test_a_signed_manifest_verifies(): void {
		$manifest = $this->manifest();
		$verifier = new SignatureVerifier( $this->keys['public'] );

		$this->assertTrue( $verifier->isAvailable() );
		$this->assertTrue( $verifier->verify( $manifest->canonical(), $this->sign( $manifest ) ) );
	}

	/**
	 * Changing one byte of the manifest invalidates the signature.
	 *
	 * @return void
	 */
	public function test_an_altered_manifest_is_rejected(): void {
		$manifest  = $this->manifest();
		$signature = $this->sign( $manifest );

		$altered = new Manifest(
			1,
			'debloater',
			$manifest->tag,
			$manifest->generated_at,
			array_merge( $manifest->files, array( 'tweaks/core.remove_rsd.json' => str_repeat( 'a', 64 ) ) )
		);

		$verifier = new SignatureVerifier( $this->keys['public'] );

		$this->assertFalse(
			$verifier->verify( $altered->canonical(), $signature ),
			'a manifest whose file hashes were changed must not verify'
		);
	}

	/**
	 * A manifest signed by somebody else is rejected.
	 *
	 * @return void
	 */
	public function test_a_manifest_signed_by_another_key_is_rejected(): void {
		$manifest = $this->manifest();

		$other  = sodium_crypto_sign_keypair();
		$forged = bin2hex(
			sodium_crypto_sign_detached( $manifest->canonical(), sodium_crypto_sign_secretkey( $other ) )
		);

		$verifier = new SignatureVerifier( $this->keys['public'] );

		$this->assertFalse( $verifier->verify( $manifest->canonical(), $forged ) );
	}

	/**
	 * Nonsense in place of a signature is rejected rather than throwing.
	 *
	 * @return void
	 */
	public function test_a_malformed_signature_is_rejected_quietly(): void {
		$manifest = $this->manifest();
		$verifier = new SignatureVerifier( $this->keys['public'] );

		foreach ( array( '', 'not hex', 'ab', str_repeat( 'f', 200 ), "\0\0" ) as $rubbish ) {
			$this->assertFalse(
				$verifier->verify( $manifest->canonical(), $rubbish ),
				'a malformed signature must be a "no", not an exception'
			);
		}
	}

	/**
	 * With no key pinned, everything is refused.
	 *
	 * "Nothing to check against, so allow it" is how a supply chain gets
	 * compromised, so a build without a key has to refuse rather than skip.
	 *
	 * This used to assert that `PUBLIC_KEY_HEX` was empty, with a message
	 * saying that pinning a key should make somebody state the change
	 * deliberately. A key was pinned on 2026-09-05 and this is that statement:
	 * the property under test was never "no key is pinned", it was "a build
	 * with no key refuses", and that is asserted directly now instead of
	 * through the shipped constant.
	 *
	 * `PinnedSigningKeyTest` covers the pinned key against a real signature.
	 *
	 * @return void
	 */
	public function test_with_no_key_pinned_nothing_verifies(): void {
		$verifier = new SignatureVerifier( '' );

		$this->assertFalse( $verifier->isAvailable() );
		$this->assertNotSame( '', $verifier->unavailableReason() );
		$this->assertFalse( $verifier->verify( $this->manifest()->canonical(), $this->sign( $this->manifest() ) ) );

		// Nor by any other route into an empty key: whitespace, a truncated
		// pin, an odd number of digits, something that is not hex at all.
		foreach ( array( '   ', 'abc', 'zz', str_repeat( 'a', 63 ) ) as $malformed ) {
			$this->assertFalse(
				( new SignatureVerifier( $malformed ) )->isAvailable(),
				sprintf( '"%s" must not read as a usable key.', $malformed )
			);
		}
	}

	/**
	 * The signature covers the canonical form, so formatting cannot break it.
	 *
	 * @return void
	 */
	public function test_reordering_keys_does_not_change_the_signature(): void {
		$files = array(
			'tweaks/b.json' => str_repeat( '1', 64 ),
			'tweaks/a.json' => str_repeat( '2', 64 ),
		);

		$one = new Manifest( 1, 'debloater', 'v1.0.0', '2026-01-01T00:00:00Z', $files );
		$two = new Manifest( 1, 'debloater', 'v1.0.0', '2026-01-01T00:00:00Z', array_reverse( $files, true ) );

		$this->assertSame( $one->canonical(), $two->canonical() );
	}

	/**
	 * A manifest for another product is refused.
	 *
	 * @return void
	 */
	public function test_a_manifest_for_another_product_is_refused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/is for "some-other-plugin"/' );

		new Manifest( 1, 'some-other-plugin', 'v1.0.0', '2026-01-01T00:00:00Z', array( 'a.json' => str_repeat( '0', 64 ) ) );
	}

	/**
	 * A manifest in a format this code does not know is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_schema_version_is_refused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Refusing rather than guessing/' );

		new Manifest( 99, 'debloater', 'v1.0.0', '2026-01-01T00:00:00Z', array( 'a.json' => str_repeat( '0', 64 ) ) );
	}

	/**
	 * A path that could write outside the registry is refused.
	 *
	 * Even a signed manifest. A signing key that leaks should cost the
	 * registry's integrity, not the whole filesystem.
	 *
	 * @return void
	 */
	public function test_a_path_that_escapes_the_registry_is_refused(): void {
		foreach ( array(
			'../../wp-config.json',
			'/etc/passwd.json',
			'tweaks/../../evil.json',
			'C:/windows/system.json',
		) as $path ) {
			try {
				new Manifest( 1, 'debloater', 'v1.0.0', '2026-01-01T00:00:00Z', array( $path => str_repeat( '0', 64 ) ) );

				$this->fail( sprintf( 'A manifest listing "%s" should have been refused.', $path ) );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'plain relative path', $error->getMessage() );
			}
		}
	}

	/**
	 * Only JSON. A manifest that names executable code is refused outright.
	 *
	 * @return void
	 */
	public function test_a_manifest_naming_php_is_refused(): void {
		foreach ( array( 'evil.php', 'tweaks/handler.php', 'script.js' ) as $path ) {
			try {
				new Manifest( 1, 'debloater', 'v1.0.0', '2026-01-01T00:00:00Z', array( $path => str_repeat( '0', 64 ) ) );

				$this->fail( sprintf( 'A manifest listing "%s" should have been refused.', $path ) );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'JSON file', $error->getMessage() );
			}
		}
	}

	/**
	 * A file whose contents changed no longer matches.
	 *
	 * @return void
	 */
	public function test_a_changed_file_does_not_match(): void {
		$contents = '{"id":"core.remove_rsd"}';

		$manifest = new Manifest(
			1,
			'debloater',
			'v1.0.0',
			'2026-01-01T00:00:00Z',
			array( 'tweaks/core.remove_rsd.json' => hash( 'sha256', $contents ) )
		);

		$this->assertTrue( $manifest->matches( 'tweaks/core.remove_rsd.json', $contents ) );
		$this->assertFalse( $manifest->matches( 'tweaks/core.remove_rsd.json', $contents . ' ' ) );
	}

	/**
	 * A file the manifest never mentioned does not match either.
	 *
	 * Absence is not "unchanged". A file nobody signed for is the case this
	 * exists to catch.
	 *
	 * @return void
	 */
	public function test_a_file_not_in_the_manifest_never_matches(): void {
		$manifest = $this->manifest();

		$this->assertFalse( $manifest->matches( 'tweaks/something-nobody-released.json', '{}' ) );
	}

	/**
	 * Every URL comes from the pinned origin, over HTTPS.
	 *
	 * @return void
	 */
	public function test_every_url_is_built_from_the_pinned_origin(): void {
		$origin = new RegistryOrigin();

		$this->assertStringStartsWith( 'https://', RegistryOrigin::DEFAULT_BASE );

		foreach ( array(
			$origin->manifestUrl( 'v1.0.0' ),
			$origin->signatureUrl( 'v1.0.0' ),
			$origin->fileUrl( 'v1.0.0', 'tweaks/core.remove_rsd.json' ),
		) as $url ) {
			$this->assertStringStartsWith( RegistryOrigin::DEFAULT_BASE . '/', $url );
		}
	}

	/**
	 * A tag or a path that could leave the origin is refused.
	 *
	 * @return void
	 */
	public function test_a_url_cannot_be_made_to_point_elsewhere(): void {
		$origin = new RegistryOrigin();

		foreach ( array(
			'../../../evil.com',
			'https://evil.test',
			'v1.0.0?x=y',
			'v1.0.0#frag',
			'/absolute',
		) as $tag ) {
			try {
				$origin->manifestUrl( $tag );

				$this->fail( sprintf( 'A tag of "%s" should have been refused.', $tag ) );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'outside the pinned origin', $error->getMessage() );
			}
		}
	}

	/**
	 * A non-HTTPS origin is refused, including for tests.
	 *
	 * @return void
	 */
	public function test_a_plaintext_origin_is_refused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be HTTPS/' );

		new RegistryOrigin( 'http://registry.test' );
	}

	/**
	 * A test origin can be injected without touching the shipped default.
	 *
	 * @return void
	 */
	public function test_a_test_origin_does_not_replace_the_default(): void {
		$injected = new RegistryOrigin( 'https://fixtures.test/registry' );

		$this->assertSame( 'https://fixtures.test/registry', $injected->base() );
		$this->assertSame( RegistryOrigin::DEFAULT_BASE, ( new RegistryOrigin() )->base() );
	}

	/**
	 * The shipped manifest describes the shipped registry.
	 *
	 * The vendored snapshot and its manifest have to agree, or the plugin is
	 * pinned to a tag it is not actually carrying.
	 *
	 * @return void
	 */
	public function test_the_vendored_manifest_matches_the_vendored_registry(): void {
		$path = DEBLOATER_TESTS_ROOT . '/registry/manifest.json';

		$this->assertFileExists( $path );

		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $decoded );

		$manifest = Manifest::fromArray( $decoded );

		$this->assertNotSame( '', $manifest->tag );

		foreach ( $manifest->files as $relative => $hash ) {
			unset( $hash );

			$file = DEBLOATER_TESTS_ROOT . '/registry/' . $relative;

			$this->assertFileExists( $file, $relative . ' is in the manifest but not in the registry' );

			$this->assertTrue(
				$manifest->matches( $relative, (string) file_get_contents( $file ) ),
				$relative . ' does not match the hash the manifest gives for it'
			);
		}

		// And nothing in the registry is missing from the manifest, which is the
		// direction that would let an unsigned file ride along.
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( DEBLOATER_TESTS_ROOT . '/registry', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'json' !== $file->getExtension() ) {
				continue;
			}

			$relative = str_replace(
				'\\',
				'/',
				substr( $file->getPathname(), strlen( DEBLOATER_TESTS_ROOT . '/registry/' ) )
			);

			if ( 'manifest.json' === $relative ) {
				continue;
			}

			$this->assertArrayHasKey(
				$relative,
				$manifest->files,
				$relative . ' is in the registry but not in the manifest'
			);
		}
	}

	/**
	 * A manifest over the shipped registry.
	 *
	 * @return Manifest
	 */
	private function manifest(): Manifest {
		return new Manifest(
			1,
			'debloater',
			'v1.0.0',
			'2026-01-01T00:00:00Z',
			array(
				'tweaks/core.remove_rsd.json' => hash( 'sha256', '{"id":"core.remove_rsd"}' ),
				'profiles/safe.json'          => hash( 'sha256', '{"id":"safe"}' ),
			)
		);
	}

	/**
	 * Sign a manifest with this run's key.
	 *
	 * @param Manifest $manifest Manifest to sign.
	 * @return string
	 */
	private function sign( Manifest $manifest ): string {
		return bin2hex( sodium_crypto_sign_detached( $manifest->canonical(), $this->keys['secret'] ) );
	}
}
