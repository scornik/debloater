<?php
/**
 * The published v0.1.0 release, through the whole updater.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Update\RegistryOrigin;
use Debloater\Update\RegistryUpdater;
use Debloater\Update\SignatureVerifier;
use Debloater\Update\UpdateCheck;

/**
 * BUILD-SPEC §13 rule 9, and D-0059.
 *
 * `PinnedSigningKeyTest` checks the fixture pair against the pinned key
 * directly. That leaves the question this file answers: does the *updater*
 * accept the release we actually published — the real manifest, the real
 * signature, the real key, through fetching, verifying, parsing and hashing.
 *
 * Nothing leaves the machine. `pre_http_request` serves the committed fixture
 * bytes, which is what the registry serves; the two were asserted identical in
 * `PinnedSigningKeyTest`.
 *
 * The order under test is the one D-0059 settled: signature, then manifest
 * bytes, then verification, then parsing. Parsing untrusted bytes before
 * checking them is what the old contract did, and
 * `test_nothing_is_parsed_until_the_signature_has_been_checked` is the
 * assertion that it no longer happens.
 */
final class RegistryPinnedReleaseTest extends IntegrationTestCase {

	/**
	 * Where the fixture registry is served from.
	 */
	private const ORIGIN = 'https://raw.githubusercontent.com/scornik/debloater-registry';

	/**
	 * The tag the fixture manifest names.
	 */
	private const TAG = 'v0.1.0';

	/**
	 * URL to body, for the intercepting transport.
	 *
	 * @var array<string,string>
	 */
	private array $served = array();

	/**
	 * Bodies handed to `json_decode` during a run.
	 *
	 * @var array<int,string>
	 */
	private array $parsed = array();

	/**
	 * Set up the transport.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->markTestSkipped( 'libsodium is not available in this PHP.' );
		}

		$this->served = array();
		$this->parsed = array();

		$origin = new RegistryOrigin( self::ORIGIN );

		$this->served[ $origin->manifestUrl( self::TAG ) ]  = $this->fixture( 'manifest.json' );
		$this->served[ $origin->signatureUrl( self::TAG ) ] = $this->fixture( 'manifest.sig' );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $preempt, $args );

				$url = (string) $url;

				if ( ! isset( $this->served[ $url ] ) ) {
					return array(
						'headers'  => array(),
						'body'     => 'Not found',
						'response' => array(
							'code'    => 404,
							'message' => 'Not Found',
						),
						'cookies'  => array(),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => $this->served[ $url ],
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * The signature URL is the file the registry actually commits.
	 *
	 * It was `manifest.json.sig` while the committed file was `manifest.sig`,
	 * so the updater asked for something that was never published and every
	 * check ended in a 404 that looked like a missing release.
	 *
	 * @return void
	 */
	public function test_the_signature_is_fetched_from_the_committed_path(): void {
		$this->assertSame( 'manifest.sig', RegistryOrigin::SIGNATURE );

		$origin = new RegistryOrigin( self::ORIGIN );

		$this->assertStringEndsWith( '/' . self::TAG . '/manifest.sig', $origin->signatureUrl( self::TAG ) );
		$this->assertArrayHasKey( $origin->signatureUrl( self::TAG ), $this->served );
	}

	/**
	 * The real release is accepted by the real updater.
	 *
	 * @return void
	 */
	public function test_the_published_release_is_accepted(): void {
		$check = $this->updater( 'v0.0.9' )->check( self::TAG );

		$this->assertSame(
			UpdateCheck::AVAILABLE,
			$check->status,
			'The published release should be offered: ' . $check->message
		);
		$this->assertSame( self::TAG, $check->offered_tag );
	}

	/**
	 * A site already on that tag is told so, having verified it all the same.
	 *
	 * @return void
	 */
	public function test_a_site_on_the_current_tag_is_told_so(): void {
		$check = $this->updater( self::TAG )->check( self::TAG );

		$this->assertSame( UpdateCheck::CURRENT, $check->status );
	}

	/**
	 * One byte changed in the served manifest, and the release is refused.
	 *
	 * @return void
	 */
	public function test_a_tampered_manifest_is_refused(): void {
		$origin = new RegistryOrigin( self::ORIGIN );
		$url    = $origin->manifestUrl( self::TAG );

		$manifest  = $this->served[ $url ];
		$position  = strrpos( $manifest, '"' );
		$tampered  = $manifest;
		$character = $manifest[ $position - 1 ];

		$tampered[ $position - 1 ] = 'a' === $character ? 'b' : 'a';

		$this->served[ $url ] = $tampered;

		$check = $this->updater( 'v0.0.9' )->check( self::TAG );

		$this->assertSame( UpdateCheck::REFUSED, $check->status );
		$this->assertStringContainsString( 'not signed with the key this plugin trusts', $check->message );
	}

	/**
	 * A signature of the wrong length is refused, and says so precisely.
	 *
	 * @return void
	 */
	public function test_a_signature_of_the_wrong_length_is_refused(): void {
		$origin = new RegistryOrigin( self::ORIGIN );
		$url    = $origin->signatureUrl( self::TAG );

		foreach ( array( 63, 65 ) as $length ) {
			$signature = $this->fixture( 'manifest.sig' );

			$this->served[ $url ] = 63 === $length ? substr( $signature, 0, 63 ) : $signature . "\n";

			$check = $this->updater( 'v0.0.9' )->check( self::TAG );

			$this->assertSame( UpdateCheck::REFUSED, $check->status );
			$this->assertStringContainsString(
				sprintf( '%d bytes', $length ),
				$check->message,
				'The refusal should name the length it got.'
			);
		}
	}

	/**
	 * Nothing is parsed until the signature has been checked.
	 *
	 * The property the whole change exists for, and the one that cannot be
	 * observed from the outcome: a refusal looks the same whether the parser
	 * ran first or not.
	 *
	 * So the manifest served here is signed correctly and is not JSON. If
	 * verification happens first, it passes and the failure is at the parse
	 * step, with the message that names it. If parsing happened first — the old
	 * order — the failure would be a parse error reached with unverified bytes,
	 * and the signature would never have been consulted.
	 *
	 * @return void
	 */
	public function test_nothing_is_parsed_until_the_signature_has_been_checked(): void {
		// Signed with the pinned key's own private half? No — that is offline
		// and not in this repository. So this asserts the ordering the other
		// way round: bytes the pinned key does *not* vouch for are refused with
		// the signature message, never with a parse message, however malformed
		// they are.
		$origin = new RegistryOrigin( self::ORIGIN );

		$this->served[ $origin->manifestUrl( self::TAG ) ] = 'this is not JSON at all {{{';

		$check = $this->updater( 'v0.0.9' )->check( self::TAG );

		$this->assertSame( UpdateCheck::REFUSED, $check->status );
		$this->assertStringContainsString(
			'not signed with the key this plugin trusts',
			$check->message,
			'Unverified bytes must be refused for their signature, not for their syntax — '
				. 'a parse message here would mean json_decode() ran on them first.'
		);
		$this->assertStringNotContainsString( 'not a JSON document', $check->message );
	}

	/**
	 * An empty or oversized body is refused before verification.
	 *
	 * @return void
	 */
	public function test_an_empty_or_oversized_body_is_refused(): void {
		$origin = new RegistryOrigin( self::ORIGIN );
		$url    = $origin->manifestUrl( self::TAG );

		$this->served[ $url ] = '';

		$empty = $this->updater( 'v0.0.9' )->check( self::TAG );

		$this->assertSame( UpdateCheck::REFUSED, $empty->status );
		$this->assertStringContainsString( 'empty body', $empty->message );

		$this->served[ $url ] = str_repeat( 'x', RegistryUpdater::MAX_MANIFEST_BYTES + 1 );

		$huge = $this->updater( 'v0.0.9' )->check( self::TAG );

		$this->assertSame( UpdateCheck::REFUSED, $huge->status );
		$this->assertStringContainsString( 'the limit is', $huge->message );
	}

	/**
	 * An updater pinned to the real key, enabled for the length of a check.
	 *
	 * @param string $current_tag What this site is carrying.
	 * @return RegistryUpdater
	 */
	private function updater( string $current_tag ): RegistryUpdater {
		$updater = new RegistryUpdater(
			$current_tag,
			new RegistryOrigin( self::ORIGIN ),
			new SignatureVerifier()
		);

		$updater->setEnabled( true );

		return $updater;
	}

	/**
	 * One fixture's bytes.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function fixture( string $name ): string {
		$path = dirname( __DIR__ ) . '/Fixtures/registry-signature/' . $name;

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}
}
