<?php
/**
 * The one place a registry update may come from.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Update;

// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- This URL is matched, not fetched.
// The whole point of the change is to stop the browser going there; naming the host
// is how the script that goes there is recognised. Nothing here loads anything.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;

/**
 * Resolves every registry URL from a single pinned base (BUILD-SPEC §13
 * rules 9 and 14, docs/DECISIONS.md D-0035).
 *
 * One base, one resolver, one place to look. That is not tidiness: a URL built
 * by string concatenation somewhere else is a URL nobody reviews, and the whole
 * point of a signed registry is that the bytes come from where we think they do.
 *
 * A test-only base can be injected, which is how the suite serves fixtures. It
 * cannot replace the production default: the constant is what ships, injection
 * is per instance, and a repository invariant asserts no other file names a
 * cloud host.
 */
final class RegistryOrigin {

	/**
	 * Where releases are published.
	 *
	 * The public registry repository's raw URL. It is the origin because the
	 * registry is public data and a git tag is a better audit trail than a
	 * service — anyone can see what changed between two releases without asking
	 * us. The optional cloud service serves the same signed bytes for sites that
	 * would rather not reach GitHub (BUILD-SPEC §17 Phase 17).
	 */
	public const DEFAULT_BASE = 'https://raw.githubusercontent.com/scornik/debloater-registry';

	/**
	 * The path the manifest lives at, relative to a tag.
	 */
	public const MANIFEST = 'manifest.json';

	/**
	 * The path the detached signature lives at, relative to a tag.
	 */
	public const SIGNATURE = 'manifest.sig';

	/**
	 * The base every URL is built from.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Constructor.
	 *
	 * @param string|null $base Base URL; the pinned one when omitted.
	 * @throws RuntimeException When the base is not an HTTPS URL.
	 */
	public function __construct( ?string $base = null ) {
		$base = rtrim( null === $base ? self::DEFAULT_BASE : $base, '/' );

		// HTTPS is not negotiable. A signed manifest over plain HTTP still tells
		// an eavesdropper exactly which sites are running which registry, and
		// there is no reason to allow it.
		if ( 0 !== strpos( $base, 'https://' ) ) {
			throw new RuntimeException(
				sprintf( 'A registry origin must be HTTPS; got "%s".', $base )
			);
		}

		$this->base = $base;
	}

	/**
	 * The base this instance resolves from.
	 *
	 * @return string
	 */
	public function base(): string {
		return $this->base;
	}

	/**
	 * The URL of the manifest for a tag.
	 *
	 * @param string $tag Release tag.
	 * @return string
	 */
	public function manifestUrl( string $tag ): string {
		return $this->url( $tag, self::MANIFEST );
	}

	/**
	 * The URL of the detached signature for a tag.
	 *
	 * @param string $tag Release tag.
	 * @return string
	 */
	public function signatureUrl( string $tag ): string {
		return $this->url( $tag, self::SIGNATURE );
	}

	/**
	 * The URL of one registry file within a tag.
	 *
	 * @param string $tag  Release tag.
	 * @param string $path Relative path from the manifest.
	 * @return string
	 */
	public function fileUrl( string $tag, string $path ): string {
		return $this->url( $tag, $path );
	}

	/**
	 * Build a URL, refusing anything that would leave the origin.
	 *
	 * @param string $tag  Release tag.
	 * @param string $path Relative path.
	 * @return string
	 * @throws RuntimeException When either part could escape the base.
	 */
	private function url( string $tag, string $path ): string {
		$this->assertSegment( $tag, 'tag' );
		$this->assertSegment( $path, 'path' );

		return $this->base . '/' . $tag . '/' . $path;
	}

	/**
	 * Refuse a segment that could point somewhere else.
	 *
	 * The tag and the path both arrive from data — the vendored manifest, or a
	 * remote one. A `..`, a leading slash or a scheme in either would turn a
	 * pinned origin into any origin, which is the whole thing this class exists
	 * to prevent.
	 *
	 * @param string $value What was given.
	 * @param string $what  What it is, for the message.
	 * @return void
	 * @throws RuntimeException When the value is not a safe URL segment.
	 */
	private function assertSegment( string $value, string $what ): void {
		$unsafe = '' === trim( $value )
			|| str_contains( $value, '..' )
			|| str_contains( $value, '//' )
			|| str_contains( $value, ':' )
			|| str_contains( $value, '?' )
			|| str_contains( $value, '#' )
			|| str_contains( $value, "\0" )
			|| str_contains( $value, '\\' )
			|| str_starts_with( $value, '/' );

		if ( $unsafe ) {
			throw new RuntimeException(
				sprintf( 'Refusing a registry %s that could point outside the pinned origin: "%s".', $what, $value )
			);
		}
	}
}
