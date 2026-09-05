<?php
/**
 * Whether a registry release was signed by us.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Update;

/**
 * Ed25519 verification against a pinned public key (BUILD-SPEC §13 rule 9,
 * §17 Phase 17).
 *
 * Three properties, and the third is the one that matters most.
 *
 * **Pinned, not discovered.** The public key is compiled into the plugin. A key
 * fetched alongside the thing it verifies verifies nothing — whoever supplied
 * the file supplied the key.
 *
 * **Public only.** The private key exists on the machine that cuts a release
 * and nowhere else. A repository invariant asserts that nothing key-shaped ever
 * enters a distributed package, because a private key in a WordPress plugin is
 * public the moment somebody downloads it and cannot be taken back.
 *
 * **Fail closed.** A build with no key pinned refuses everything. Not "skip the
 * check because there is nothing to check against": an unverifiable update is
 * exactly the update not to install. That was this plugin's state until the
 * signing key existed, and it is still the behaviour of any build whose
 * constant is emptied.
 */
final class SignatureVerifier {

	/**
	 * The public key releases are signed with, hex-encoded.
	 *
	 * Ed25519, 32 bytes, pinned 2026-09-05. Its SHA-256 fingerprint is recorded
	 * in `docs/DECISIONS.md` D-0059 so that a key substituted here can be
	 * noticed by comparing one line rather than by reading sixty-four
	 * characters.
	 *
	 * This is the *public* half. The private half is held offline and has never
	 * been in this repository, which `SecurityRulesTest` asserts for every file
	 * that ships (§13 rule 15).
	 *
	 * Emptying this constant is a supported state, not a broken one: the
	 * verifier then refuses every update rather than accepting an unsigned one.
	 */
	public const PUBLIC_KEY_HEX = 'c0504cbb47724218570330a31cd175d3b40c0bb58d72c4ce640fdebdacaeab06';

	/**
	 * The key this instance verifies against, raw bytes.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $public_key_hex Hex-encoded key; the pinned one when omitted.
	 */
	public function __construct( ?string $public_key_hex = null ) {
		$hex = null === $public_key_hex ? self::PUBLIC_KEY_HEX : $public_key_hex;

		$this->key = $this->decode( $hex );
	}

	/**
	 * Whether this verifier can check anything at all.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return '' !== $this->key && function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * Why it cannot, when it cannot.
	 *
	 * @return string
	 */
	public function unavailableReason(): string {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return __(
				'This server has no libsodium, so a signed registry update cannot be checked. Debloater will not install one it cannot verify.',
				'debloater'
			);
		}

		return __(
			'No registry signing key is pinned in this build, so there is nothing to check a signed update against.',
			'debloater'
		);
	}

	/**
	 * Whether a signature over the given bytes is ours.
	 *
	 * @param string $signed_bytes  The exact bytes that were signed.
	 * @param string $signature_hex Hex-encoded detached signature.
	 * @return bool
	 */
	public function verify( string $signed_bytes, string $signature_hex ): bool {
		if ( ! $this->isAvailable() ) {
			return false;
		}

		$signature = $this->decode( $signature_hex );

		// An Ed25519 signature is exactly 64 bytes. Anything else is not a
		// signature, and handing it to sodium would be an exception rather than
		// an answer.
		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, $signed_bytes, $this->key );
		} catch ( \SodiumException $error ) {
			unset( $error );

			return false;
		}
	}

	/**
	 * Hex to raw bytes, or an empty string when it is not hex.
	 *
	 * @param string $hex Hex-encoded value.
	 * @return string
	 */
	private function decode( string $hex ): string {
		$hex = trim( $hex );

		if ( '' === $hex || 1 !== preg_match( '/^[0-9a-fA-F]+$/', $hex ) || 0 !== strlen( $hex ) % 2 ) {
			return '';
		}

		// The pattern and the even-length check above already guarantee this
		// decodes, so there is nothing left for hex2bin() to warn about — and
		// the result is checked anyway rather than trusted.
		$raw = hex2bin( $hex );

		return false === $raw ? '' : $raw;
	}
}
