<?php
/**
 * The registry update check, against a real HTTP layer.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use RuntimeException;
use Debloater\Update\Manifest;
use Debloater\Update\RegistryOrigin;
use Debloater\Update\RegistryUpdater;
use Debloater\Update\SignatureVerifier;
use Debloater\Update\UpdateCheck;

/**
 * BUILD-SPEC §13 rule 9, §17 Phase 17.
 *
 * The unit tests check the arithmetic of signatures and hashes. This checks the
 * behaviour that a person actually gets: that nothing is requested unless they
 * asked, and that everything which fails a check is refused whole rather than
 * installed in part.
 *
 * The keypair is generated per test and never written down. The origin is a
 * fixture served through `pre_http_request`; no request leaves the machine.
 */
final class RegistryUpdaterTest extends IntegrationTestCase {

	/**
	 * URLs the updater asked for during this test.
	 *
	 * @var array<int,string>
	 */
	private array $requested = array();

	/**
	 * Files the fixture origin serves, keyed by URL.
	 *
	 * @var array<string,string>
	 */
	private array $served = array();

	/**
	 * This run's keypair.
	 *
	 * @var array{public:string,secret:string}
	 */
	private array $keys = array();

	/**
	 * The fixture origin.
	 */
	private const ORIGIN = 'https://registry.fixture.test';

	/**
	 * The tag the site is pinned to.
	 */
	private const CURRENT_TAG = 'v0.1.0';

	/**
	 * Prepare the keypair and the intercepting transport.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'libsodium is not available in this PHP.' );
		}

		$pair = sodium_crypto_sign_keypair();

		$this->keys = array(
			'public' => bin2hex( sodium_crypto_sign_publickey( $pair ) ),
			'secret' => sodium_crypto_sign_secretkey( $pair ),
		);

		$this->requested = array();
		$this->served    = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $preempt, $args );

				$this->requested[] = (string) $url;

				if ( ! isset( $this->served[ $url ] ) ) {
					return array(
						'headers'  => array(),
						'body'     => 'Not found',
						'response' => array(
							'code'    => 404,
							'message' => 'Not Found',
						),
						'cookies'  => array(),
						'filename' => null,
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
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Stop intercepting.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * With the check off, nothing is requested at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_requested_unless_asked(): void {
		$this->publish( 'v0.2.0' );

		$result = $this->updater( false )->check( 'v0.2.0' );

		$this->assertSame( UpdateCheck::UNAVAILABLE, $result->status );
		$this->assertSame( array(), $this->requested, 'off means no request, not a quiet one' );
		$this->assertStringContainsString( 'unless you ask', $result->message );
	}

	/**
	 * With the check on, a properly signed newer release is offered.
	 *
	 * @return void
	 */
	public function test_a_signed_release_is_offered(): void {
		$this->publish( 'v0.2.0' );

		$result = $this->updater()->check( 'v0.2.0' );

		$this->assertTrue( $result->isAvailable(), $result->message );
		$this->assertSame( self::CURRENT_TAG, $result->current_tag );
		$this->assertSame( 'v0.2.0', $result->offered_tag );

		foreach ( $this->requested as $url ) {
			$this->assertStringStartsWith( self::ORIGIN . '/', $url, 'every request goes to the pinned origin' );
		}
	}

	/**
	 * The same release, already installed, is not an update.
	 *
	 * @return void
	 */
	public function test_the_current_release_is_not_an_update(): void {
		$this->publish( self::CURRENT_TAG );

		$result = $this->updater()->check( self::CURRENT_TAG );

		$this->assertSame( UpdateCheck::CURRENT, $result->status );
		$this->assertFalse( $result->isAvailable() );
	}

	/**
	 * A release signed by somebody else is refused.
	 *
	 * @return void
	 */
	public function test_a_release_signed_by_another_key_is_refused(): void {
		$other = sodium_crypto_sign_keypair();

		$this->publish( 'v0.2.0', sodium_crypto_sign_secretkey( $other ) );

		$result = $this->updater()->check( 'v0.2.0' );

		$this->assertTrue( $result->wasRefused() );
		$this->assertStringContainsString( 'not signed with the key this plugin trusts', $result->message );
	}

	/**
	 * A manifest whose contents were edited after signing is refused.
	 *
	 * @return void
	 */
	public function test_an_edited_manifest_is_refused(): void {
		$this->publish( 'v0.2.0' );

		// Sign one thing, serve another. The signature is still ours; the bytes
		// are not the ones it covers.
		$tampered = $this->manifest( 'v0.2.0', array( 'tweaks/core.remove_rsd.json' => '{"id":"core.remove_rsd","risk":"safe"}' ) );

		$this->served[ ( new RegistryOrigin( self::ORIGIN ) )->manifestUrl( 'v0.2.0' ) ] =
			(string) wp_json_encode( $tampered->toArray() );

		$result = $this->updater()->check( 'v0.2.0' );

		$this->assertTrue( $result->wasRefused() );
	}

	/**
	 * A file whose bytes do not match the signed manifest rejects the release.
	 *
	 * Not just that file. A registry half from one version and half from
	 * another is a registry nobody tested.
	 *
	 * @return void
	 */
	public function test_one_bad_hash_rejects_the_whole_release(): void {
		$files = array(
			'tweaks/core.remove_rsd.json' => '{"id":"core.remove_rsd"}',
			'profiles/safe.json'          => '{"id":"safe"}',
		);

		$manifest = $this->publish( 'v0.2.0', null, $files );

		$origin = new RegistryOrigin( self::ORIGIN );

		// One file swapped for different bytes after the manifest was signed.
		$this->served[ $origin->fileUrl( 'v0.2.0', 'profiles/safe.json' ) ] = '{"id":"safe","tweaks":["core.disable_emojis"]}';

		$updater = $this->updater();

		$this->assertTrue( $updater->check( 'v0.2.0' )->isAvailable(), 'the manifest itself is still valid' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Rejecting the whole release/' );

		$updater->download( $manifest );
	}

	/**
	 * A file that is not JSON is refused even when its hash is right.
	 *
	 * The hash proves the bytes are the ones that were signed. It says nothing
	 * about whether they are a registry document, and the loader must never be
	 * handed something it would choke on.
	 *
	 * @return void
	 */
	public function test_a_file_that_is_not_json_is_refused(): void {
		$files = array( 'tweaks/core.remove_rsd.json' => '<?php echo "not json";' );

		$manifest = $this->publish( 'v0.2.0', null, $files );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/is not a JSON document/' );

		$this->updater()->download( $manifest );
	}

	/**
	 * A release that verifies downloads exactly what the manifest names.
	 *
	 * @return void
	 */
	public function test_a_verified_release_downloads_intact(): void {
		$files = array(
			'tweaks/core.remove_rsd.json' => '{"id":"core.remove_rsd"}',
			'profiles/safe.json'          => '{"id":"safe"}',
		);

		$manifest = $this->publish( 'v0.2.0', null, $files );

		$downloaded = $this->updater()->download( $manifest );

		// assertEquals rather than assertSame: the download returns files in
		// the manifest's order, which is sorted, and the order they were
		// written here is not the assertion.
		$this->assertEquals( $files, $downloaded );
		$this->assertSame( array_keys( $manifest->files ), array_keys( $downloaded ) );
	}

	/**
	 * Nothing is downloaded when the check is off.
	 *
	 * @return void
	 */
	public function test_downloading_without_opting_in_is_refused(): void {
		$manifest = $this->publish( 'v0.2.0' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/is off/' );

		$this->updater( false )->download( $manifest );
	}

	/**
	 * A release that is not there is a refusal, not a crash.
	 *
	 * @return void
	 */
	public function test_a_missing_release_is_refused_politely(): void {
		$result = $this->updater()->check( 'v9.9.9' );

		$this->assertTrue( $result->wasRefused() );
		$this->assertStringContainsString( 'HTTP 404', $result->message );
	}

	/**
	 * An updater pointed at the fixture origin.
	 *
	 * @param bool $enabled Whether the user opted in.
	 * @return RegistryUpdater
	 */
	private function updater( bool $enabled = true ): RegistryUpdater {
		return new RegistryUpdater(
			self::CURRENT_TAG,
			new RegistryOrigin( self::ORIGIN ),
			new SignatureVerifier( $this->keys['public'] ),
			$enabled
		);
	}

	/**
	 * Build a manifest over the given files.
	 *
	 * @param string               $tag   Release tag.
	 * @param array<string,string> $files Path to contents.
	 * @return Manifest
	 */
	private function manifest( string $tag, array $files ): Manifest {
		$hashes = array();

		foreach ( $files as $path => $contents ) {
			$hashes[ $path ] = hash( 'sha256', $contents );
		}

		return new Manifest( 1, 'debloater', $tag, '2026-01-01T00:00:00Z', $hashes );
	}

	/**
	 * Serve a signed release from the fixture origin.
	 *
	 * @param string               $tag    Release tag.
	 * @param string|null          $secret Key to sign with; this run's key when omitted.
	 * @param array<string,string> $files  Path to contents.
	 * @return Manifest
	 */
	private function publish( string $tag, ?string $secret = null, array $files = array() ): Manifest {
		if ( array() === $files ) {
			$files = array( 'tweaks/core.remove_rsd.json' => '{"id":"core.remove_rsd"}' );
		}

		$manifest = $this->manifest( $tag, $files );
		$origin   = new RegistryOrigin( self::ORIGIN );

		// One encoding, signed and served. What is published is what is
		// verified: the signature covers these exact bytes, and the updater
		// checks them before it parses them (D-0059).
		$bytes = (string) wp_json_encode( $manifest->toArray() );

		$this->served[ $origin->manifestUrl( $tag ) ]  = $bytes;
		$this->served[ $origin->signatureUrl( $tag ) ] = sodium_crypto_sign_detached(
			$bytes,
			$secret ?? $this->keys['secret']
		);

		foreach ( $files as $path => $contents ) {
			$this->served[ $origin->fileUrl( $tag, $path ) ] = $contents;
		}

		return $manifest;
	}
}
