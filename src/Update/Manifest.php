<?php
/**
 * What a registry release says it contains.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Update;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;
use Debloater\Brand;

/**
 * A signed list of every file in a registry release, with its hash
 * (BUILD-SPEC §17 Phase 17).
 *
 * The registry is data the plugin reads and acts on. Anything that can change
 * it can change what Debloater does to a site, which makes "where did this
 * file come from" a security question rather than a packaging one.
 *
 * So a release is a manifest: a tag, a time, and a SHA-256 for every file. The
 * manifest is signed; the files are not. One signature covers everything,
 * because the manifest names everything, and a file whose hash is absent from
 * the manifest is a file that was never released.
 *
 * **The bytes on disk are the thing that gets signed**, not any re-encoding of
 * them. This class used to expose a `canonical()` form for that purpose, on the
 * reasoning that two documents differing only in key order are the same
 * manifest and a signature should survive reformatting.
 *
 * That reasoning was wrong in the direction that matters. Signing a re-encoding
 * means the parser runs before the signature is checked, so untrusted bytes
 * reach `json_decode()` on the strength of nothing; and it means the published
 * file is not the signed artefact, so nobody can verify a release with
 * `openssl`. Reformatting a released manifest is not a thing that happens by
 * accident, and when it does the right answer is to sign it again.
 *
 * Verification is now `sodium_crypto_sign_verify_detached()` over the exact
 * bytes fetched, before this class is constructed at all. See
 * `docs/DECISIONS.md` D-0059.
 */
final class Manifest {

	/**
	 * The manifest format this code understands.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * The product a manifest must name.
	 *
	 * A correctly signed manifest for a *different* product is still not this
	 * product's registry, and swapping one for the other must not be possible
	 * just because the same key signed both.
	 */
	public const PRODUCT = Brand::SLUG;

	/**
	 * Format version.
	 *
	 * @var int
	 */
	public readonly int $schema_version;

	/**
	 * The product this release belongs to.
	 *
	 * @var string
	 */
	public readonly string $product;

	/**
	 * The release tag.
	 *
	 * @var string
	 */
	public readonly string $tag;

	/**
	 * When it was generated, ISO 8601 UTC.
	 *
	 * @var string
	 */
	public readonly string $generated_at;

	/**
	 * Every file in the release: relative path to lowercase SHA-256, sorted.
	 *
	 * @var array<string,string>
	 */
	public readonly array $files;

	/**
	 * Constructor.
	 *
	 * @param int                  $schema_version Format version.
	 * @param string               $product        Product name.
	 * @param string               $tag            Release tag.
	 * @param string               $generated_at   ISO 8601 UTC timestamp.
	 * @param array<string,string> $files          Path to SHA-256.
	 * @throws RuntimeException When the manifest is not one this code can trust.
	 */
	public function __construct(
		int $schema_version,
		string $product,
		string $tag,
		string $generated_at,
		array $files
	) {
		if ( self::SCHEMA_VERSION !== $schema_version ) {
			throw new RuntimeException(
				sprintf(
					'Registry manifest is version %d; this plugin understands version %d. Refusing rather than guessing.',
					$schema_version,
					self::SCHEMA_VERSION
				)
			);
		}

		if ( self::PRODUCT !== $product ) {
			throw new RuntimeException(
				sprintf( 'Registry manifest is for "%s", not for %s.', $product, self::PRODUCT )
			);
		}

		if ( '' === trim( $tag ) ) {
			throw new RuntimeException( 'Registry manifest has no tag, so there is nothing to pin to.' );
		}

		if ( array() === $files ) {
			throw new RuntimeException( 'Registry manifest lists no files.' );
		}

		$sorted = array();

		foreach ( $files as $path => $hash ) {
			$path = (string) $path;
			$hash = strtolower( (string) $hash );

			if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
				throw new RuntimeException(
					sprintf( 'Registry manifest gives "%s" a hash that is not a SHA-256.', $path )
				);
			}

			$this->assertSafePath( $path );

			$sorted[ $path ] = $hash;
		}

		ksort( $sorted, SORT_STRING );

		$this->schema_version = $schema_version;
		$this->product        = $product;
		$this->tag            = $tag;
		$this->generated_at   = $generated_at;
		$this->files          = $sorted;
	}

	/**
	 * Build from a decoded manifest document.
	 *
	 * @param array<string,mixed> $document Decoded JSON.
	 * @return self
	 * @throws RuntimeException When the document is not a manifest.
	 */
	public static function fromArray( array $document ): self {
		$files = $document['files'] ?? array();

		if ( ! is_array( $files ) ) {
			throw new RuntimeException( 'Registry manifest has no file list.' );
		}

		/** @var array<string,string> $files */
		return new self(
			(int) ( $document['schema_version'] ?? 0 ),
			(string) ( $document['product'] ?? '' ),
			(string) ( $document['tag'] ?? '' ),
			(string) ( $document['generated_at'] ?? '' ),
			$files
		);
	}

	/**
	 * The manifest as an array.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'schema_version' => $this->schema_version,
			'product'        => $this->product,
			'tag'            => $this->tag,
			'generated_at'   => $this->generated_at,
			'files'          => $this->files,
		);
	}

	/**
	 * The expected hash of one file, or null when the manifest does not list it.
	 *
	 * @param string $path Relative path.
	 * @return string|null
	 */
	public function hashOf( string $path ): ?string {
		return $this->files[ $path ] ?? null;
	}

	/**
	 * Whether a file's contents match what the manifest says.
	 *
	 * @param string $path     Relative path.
	 * @param string $contents File contents.
	 * @return bool
	 */
	public function matches( string $path, string $contents ): bool {
		$expected = $this->hashOf( $path );

		if ( null === $expected ) {
			// Not in the manifest is not "unchanged" — it is a file nobody
			// signed for, which is the case this exists to catch.
			return false;
		}

		return hash_equals( $expected, hash( 'sha256', $contents ) );
	}

	/**
	 * Refuse a path that could write outside the registry directory.
	 *
	 * The manifest comes from a remote. Even signed, a path is untrusted input
	 * until it has been checked: a signing key that leaks should cost the
	 * registry's integrity, not the whole filesystem.
	 *
	 * @param string $path Relative path.
	 * @return void
	 * @throws RuntimeException When the path is not a plain relative path.
	 */
	private function assertSafePath( string $path ): void {
		$normalised = str_replace( '\\', '/', $path );

		$unsafe = '' === $normalised
			|| str_starts_with( $normalised, '/' )
			|| str_contains( $normalised, '..' )
			|| str_contains( $normalised, "\0" )
			|| 1 === preg_match( '#^[A-Za-z]:#', $normalised )
			|| ! str_ends_with( $normalised, '.json' );

		if ( $unsafe ) {
			throw new RuntimeException(
				sprintf( 'Registry manifest lists "%s", which is not a plain relative path to a JSON file.', $path )
			);
		}
	}
}
