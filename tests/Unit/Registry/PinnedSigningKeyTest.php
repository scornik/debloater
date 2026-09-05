<?php
/**
 * The pinned registry signing key, against a real signature.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Registry;

use Debloater\Update\Manifest;
use Debloater\Update\SignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * BUILD-SPEC §13 rule 9 and §17 Phase 17.
 *
 * Every other test of the signing path signs its own fixtures with a key it
 * generated a line earlier. That proves the code round-trips and proves nothing
 * about the key this plugin actually ships, or about the release that key was
 * used on.
 *
 * These fixtures are the real thing: `manifest.json` is the registry Debloater
 * 0.1.1 vendors, byte for byte, and `manifest.sig` is the detached Ed25519
 * signature produced over it with the private half of the pinned key, offline.
 */
final class PinnedSigningKeyTest extends TestCase {

	/**
	 * The key compiled into this build.
	 */
	private const PINNED = 'c0504cbb47724218570330a31cd175d3b40c0bb58d72c4ce640fdebdacaeab06';

	/**
	 * Its SHA-256 fingerprint, over the 32 raw bytes.
	 *
	 * Recorded in docs/DECISIONS.md D-0059. Two places on purpose: a key
	 * swapped in one of them and not the other is a key somebody changed
	 * without saying so.
	 */
	private const FINGERPRINT = 'a2179aba16aa74a34b3d0c80a2a86d2adb622a7fcf2043dd93da6f9c8964caa3';

	/**
	 * The constant is the key this test signs against.
	 *
	 * @return void
	 */
	public function test_the_pinned_key_is_the_one_recorded(): void {
		$this->assertSame( self::PINNED, SignatureVerifier::PUBLIC_KEY_HEX );

		$raw = sodium_hex2bin( SignatureVerifier::PUBLIC_KEY_HEX );

		// 32 bytes is a public key. An Ed25519 *secret* key is 64, so this also
		// says that what shipped is the half that is safe to ship.
		$this->assertSame( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen( $raw ) );
		$this->assertSame( 32, strlen( $raw ) );
		$this->assertSame( self::FINGERPRINT, hash( 'sha256', $raw ) );
	}

	/**
	 * The fixture is the registry this plugin ships, not a copy of it.
	 *
	 * A signature over a manifest nobody vendors would verify perfectly and
	 * mean nothing.
	 *
	 * @return void
	 */
	public function test_the_fixture_manifest_is_the_vendored_one(): void {
		$fixture  = $this->fixture( 'manifest.json' );
		$vendored = (string) file_get_contents( $this->root() . '/registry/manifest.json' );

		$this->assertSame(
			hash( 'sha256', $vendored ),
			hash( 'sha256', $fixture ),
			'The signed fixture must be the registry this build carries, byte for byte.'
		);
		$this->assertSame( $vendored, $fixture );
	}

	/**
	 * The signature is ours, over those bytes.
	 *
	 * @return void
	 */
	public function test_the_signature_is_accepted(): void {
		$this->assertTrue(
			sodium_crypto_sign_verify_detached(
				$this->fixture( 'manifest.sig' ),
				$this->fixture( 'manifest.json' ),
				sodium_hex2bin( self::PINNED )
			),
			'The committed signature must verify against the pinned key.'
		);

		// And through the class that will actually do it on a site, which takes
		// the signature hex-encoded.
		$verifier = new SignatureVerifier();

		$this->assertTrue( $verifier->isAvailable() );
		$this->assertTrue(
			$verifier->verify( $this->fixture( 'manifest.json' ), bin2hex( $this->fixture( 'manifest.sig' ) ) )
		);
	}

	/**
	 * One byte different is a different manifest.
	 *
	 * @return void
	 */
	public function test_a_single_changed_byte_is_refused(): void {
		$manifest = $this->fixture( 'manifest.json' );

		// The last recorded hash digit. A change small enough that nothing
		// reading the file casually would see it, and large enough to point a
		// site at a different file than the one that was signed.
		$position = strrpos( $manifest, '"' );

		$this->assertNotFalse( $position );

		$tampered                  = $manifest;
		$tampered[ $position - 1 ] = 'a' === $manifest[ $position - 1 ] ? 'b' : 'a';

		$this->assertNotSame( $manifest, $tampered );
		$this->assertSame( strlen( $manifest ), strlen( $tampered ) );

		$this->assertFalse(
			sodium_crypto_sign_verify_detached(
				$this->fixture( 'manifest.sig' ),
				$tampered,
				sodium_hex2bin( self::PINNED )
			),
			'A manifest with one byte changed must not verify.'
		);

		$this->assertFalse(
			( new SignatureVerifier() )->verify( $tampered, bin2hex( $this->fixture( 'manifest.sig' ) ) )
		);
	}

	/**
	 * A signature from somebody else is refused, however well-formed.
	 *
	 * The property that makes pinning worth anything: a correctly signed
	 * manifest signed with the wrong key is exactly what an attacker who
	 * controls the download can produce.
	 *
	 * @return void
	 */
	public function test_a_signature_from_another_key_is_refused(): void {
		$manifest = $this->fixture( 'manifest.json' );
		$other    = sodium_crypto_sign_keypair();

		$forged = sodium_crypto_sign_detached( $manifest, sodium_crypto_sign_secretkey( $other ) );

		// It is a real signature over the real manifest — just not ours.
		$this->assertSame( SODIUM_CRYPTO_SIGN_BYTES, strlen( $forged ) );
		$this->assertTrue(
			sodium_crypto_sign_verify_detached( $forged, $manifest, sodium_crypto_sign_publickey( $other ) )
		);

		$this->assertFalse(
			sodium_crypto_sign_verify_detached( $forged, $manifest, sodium_hex2bin( self::PINNED ) ),
			'A signature from a key we do not trust must be refused.'
		);

		$this->assertFalse( ( new SignatureVerifier() )->verify( $manifest, bin2hex( $forged ) ) );
	}

	/**
	 * An empty pin still refuses everything.
	 *
	 * Pinning a key must not have turned the fail-closed default into a
	 * fail-open one for a build that has no key.
	 *
	 * @return void
	 */
	public function test_an_unpinned_build_still_refuses(): void {
		$unpinned = new SignatureVerifier( '' );

		$this->assertFalse( $unpinned->isAvailable() );
		$this->assertFalse(
			$unpinned->verify( $this->fixture( 'manifest.json' ), bin2hex( $this->fixture( 'manifest.sig' ) ) )
		);
	}

	/**
	 * What was signed is the file, and the updater checks something else.
	 *
	 * Recorded as a test rather than a comment because it is a fact about two
	 * byte strings and it decides whether a release installs.
	 *
	 * `manifest.sig` was produced over `manifest.json` as it sits on disk —
	 * which is what `openssl pkeyutl -sign -rawin` signs, what the registry's
	 * CI checks, and what anybody auditing the release would check.
	 * `RegistryUpdater` verifies `Manifest::canonical()`, a re-encoding that is
	 * six hundred bytes shorter. They are not the same bytes and no signature
	 * can satisfy both.
	 *
	 * See docs/DECISIONS.md D-0059: the pin is correct and this release cannot
	 * be installed by the update path until one of the two is changed.
	 *
	 * @return void
	 */
	public function test_the_signed_bytes_are_the_file_not_the_canonical_form(): void {
		$file = $this->fixture( 'manifest.json' );

		$decoded = json_decode( $file, true );

		$this->assertIsArray( $decoded );

		$canonical = Manifest::fromArray( $decoded )->canonical();

		$this->assertNotSame(
			$file,
			$canonical,
			'If these ever become equal, the mismatch D-0059 records has gone and that decision should be revisited.'
		);

		$this->assertTrue(
			sodium_crypto_sign_verify_detached(
				$this->fixture( 'manifest.sig' ),
				$file,
				sodium_hex2bin( self::PINNED )
			)
		);

		$this->assertFalse(
			sodium_crypto_sign_verify_detached(
				$this->fixture( 'manifest.sig' ),
				$canonical,
				sodium_hex2bin( self::PINNED )
			),
			'The signature is over the file, so it cannot also be over the canonical form.'
		);
	}

	/**
	 * One fixture's bytes.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function fixture( string $name ): string {
		$path = __DIR__ . '/../../Fixtures/registry-signature/' . $name;

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}
}
