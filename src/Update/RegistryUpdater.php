<?php
/**
 * Checking for a newer registry, and refusing most of what it is offered.
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
use Throwable;
use Debloater\Contracts\Json;

/**
 * The opt-in registry update check (BUILD-SPEC §13 rule 9, §17 Phase 17).
 *
 * The registry decides what Debloater offers to change about a site. Anything
 * that can replace it can change what this plugin does to somebody's shop, so
 * the update path is written as a series of refusals with one narrow way
 * through:
 *
 * 1. **Nothing happens unless asked.** No schedule, no background check, no
 *    "while we were there". Off is the default and off means no request.
 * 2. **A manifest and a detached signature**, both fetched, before any file is.
 * 3. **The signature is checked against a pinned key.** No key pinned, or no
 *    libsodium, means refuse — never "skip the check".
 * 4. **The manifest must be for this product and this format version**, so a
 *    correctly signed manifest for something else is still rejected.
 * 5. **Every file is fetched and hashed** against the manifest before any of
 *    them is written anywhere. One bad hash rejects the whole release; there is
 *    no partial update, because a registry half from one version is a registry
 *    nobody tested.
 * 6. **JSON only.** Every path is checked to end in `.json` and to parse as
 *    JSON before it is staged. Handlers stay in the plugin. Nothing from a
 *    remote is ever executed, and nothing here can write a `.php` file.
 *
 * What this class deliberately does **not** do is activate anything. It stages a
 * verified release and reports; swapping the live registry is a separate,
 * explicit act. Fetching and installing in one call would mean a network hiccup
 * halfway through could leave a site with half a registry.
 */
final class RegistryUpdater {

	/**
	 * Seconds to wait for one file.
	 */
	public const TIMEOUT = 10;

	/**
	 * How many files one release may contain.
	 *
	 * The shipped registry has a few dozen. A manifest claiming thousands is
	 * either wrong or hostile, and either way is not something to start
	 * downloading.
	 */
	public const MAX_FILES = 500;

	/**
	 * The largest a single registry file may be.
	 */
	public const MAX_FILE_BYTES = 262144;

	/**
	 * Where to fetch from.
	 *
	 * @var RegistryOrigin
	 */
	private RegistryOrigin $origin;

	/**
	 * What to check signatures with.
	 *
	 * @var SignatureVerifier
	 */
	private SignatureVerifier $verifier;

	/**
	 * The tag currently vendored.
	 *
	 * @var string
	 */
	private string $current_tag;

	/**
	 * Whether the user asked for this check.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param string                 $current_tag Tag currently vendored.
	 * @param RegistryOrigin|null    $origin      Where to fetch from.
	 * @param SignatureVerifier|null $verifier    What to verify with.
	 * @param bool                   $enabled     Whether the user opted in.
	 */
	public function __construct(
		string $current_tag,
		?RegistryOrigin $origin = null,
		?SignatureVerifier $verifier = null,
		bool $enabled = false
	) {
		$this->current_tag = $current_tag;
		$this->origin      = $origin ?? new RegistryOrigin();
		$this->verifier    = $verifier ?? new SignatureVerifier();
		$this->enabled     = $enabled;
	}

	/**
	 * Turn the check on or off for the next call.
	 *
	 * @param bool $enabled Whether the user opted in.
	 * @return void
	 */
	public function setEnabled( bool $enabled ): void {
		$this->enabled = $enabled;
	}

	/**
	 * Whether a check would do anything.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * The base this updater would fetch from.
	 *
	 * Exists so "where does the registry come from on this site" is a question
	 * with an answer, rather than one inferable only by watching the network.
	 * The extension-point test asserts the `debloater_registry_origin` filter
	 * reaches here, and that an origin the plugin would refuse falls back to
	 * the shipped one rather than switching updates off.
	 *
	 * @return string
	 */
	public function originBase(): string {
		return $this->origin->base();
	}

	/**
	 * Ask whether a newer registry release exists, and verify it if so.
	 *
	 * @param string $tag Tag to check for.
	 * @return UpdateCheck
	 */
	public function check( string $tag ): UpdateCheck {
		if ( ! $this->enabled ) {
			return $this->unavailable(
				__(
					'The registry update check is off, so nothing was requested. Nothing leaves this server unless you ask for it.',
					'debloater'
				)
			);
		}

		if ( ! $this->verifier->isAvailable() ) {
			// Refused rather than unavailable: something could have been
			// fetched, and the reason it was not is that it could not have been
			// trusted.
			return $this->refused( $tag, $this->verifier->unavailableReason() );
		}

		try {
			return $this->fetchAndVerify( $tag );
		} catch ( Throwable $error ) {
			return $this->refused( $tag, $error->getMessage() );
		}
	}

	/**
	 * The verified files of a release, keyed by path.
	 *
	 * Only reached once `check()` has said the release is available. Every file
	 * is fetched, size-checked, hashed against the manifest and parsed as JSON
	 * before any of them is returned, so a caller never sees a partial release.
	 *
	 * @param Manifest $manifest The verified manifest.
	 * @return array<string,string>
	 * @throws RuntimeException When any file fails any check.
	 */
	public function download( Manifest $manifest ): array {
		if ( ! $this->enabled ) {
			throw new RuntimeException( 'The registry update check is off.' );
		}

		if ( count( $manifest->files ) > self::MAX_FILES ) {
			throw new RuntimeException(
				sprintf(
					'The manifest lists %d files, more than the %d this will download.',
					count( $manifest->files ),
					self::MAX_FILES
				)
			);
		}

		$files = array();

		foreach ( $manifest->files as $path => $hash ) {
			unset( $hash );

			$contents = $this->fetch( $this->origin->fileUrl( $manifest->tag, $path ) );

			if ( strlen( $contents ) > self::MAX_FILE_BYTES ) {
				throw new RuntimeException(
					sprintf( 'Registry file "%s" is larger than anything this expects to see.', $path )
				);
			}

			if ( ! $manifest->matches( $path, $contents ) ) {
				throw new RuntimeException(
					sprintf(
						'Registry file "%s" does not match the hash the signed manifest gives for it. Rejecting the whole release.',
						$path
					)
				);
			}

			// Belt and braces after the hash: a signed manifest could still name
			// a file whose contents are not JSON, and the registry loader must
			// never be handed something it would choke on. The decoder's own
			// error says "Syntax error" and not which file, so it is caught and
			// restated — a refusal a person cannot act on is barely a refusal.
			try {
				$decoded = Json::decode( $contents );
			} catch ( Throwable $error ) {
				unset( $error );

				$decoded = null;
			}

			if ( ! is_array( $decoded ) ) {
				throw new RuntimeException(
					sprintf( 'Registry file "%s" is not a JSON document.', $path )
				);
			}

			$files[ $path ] = $contents;
		}

		return $files;
	}

	/**
	 * Fetch the manifest and its signature, and check both.
	 *
	 * @param string $tag Tag to check for.
	 * @return UpdateCheck
	 * @throws RuntimeException When anything does not check out.
	 */
	private function fetchAndVerify( string $tag ): UpdateCheck {
		$raw       = $this->fetch( $this->origin->manifestUrl( $tag ) );
		$signature = trim( $this->fetch( $this->origin->signatureUrl( $tag ) ) );

		$decoded = Json::decode( $raw );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'The registry manifest is not a JSON document.' );
		}

		/** @var array<string,mixed> $decoded */
		$manifest = Manifest::fromArray( $decoded );

		// The canonical form rather than the bytes that arrived: whitespace and
		// key order must not be able to make or break a signature.
		if ( ! $this->verifier->verify( $manifest->canonical(), $signature ) ) {
			throw new RuntimeException(
				__(
					'The registry release is not signed with the key this plugin trusts. Nothing was installed.',
					'debloater'
				)
			);
		}

		if ( $manifest->tag === $this->current_tag ) {
			return new UpdateCheck(
				UpdateCheck::CURRENT,
				$this->current_tag,
				$manifest->tag,
				__( 'The registry is already the newest release.', 'debloater' )
			);
		}

		return new UpdateCheck(
			UpdateCheck::AVAILABLE,
			$this->current_tag,
			$manifest->tag,
			sprintf(
				/* translators: 1: offered tag, 2: current tag. */
				__( 'Registry release %1$s is available and its signature checks out. This site has %2$s.', 'debloater' ),
				$manifest->tag,
				$this->current_tag
			)
		);
	}

	/**
	 * Fetch one URL over HTTPS.
	 *
	 * @param string $url URL to fetch.
	 * @return string
	 * @throws RuntimeException When the request fails or answers with anything but 200.
	 */
	private function fetch( string $url ): string {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() exists only on VIP, and Debloater ships with zero runtime dependencies. What it buys — a bounded timeout and a graceful failure — this call already has.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'sslverify'  => true,
				'user-agent' => 'Debloater; registry update check',
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			throw new RuntimeException(
				sprintf( 'The registry answered HTTP %d for %s.', $status, $url )
			);
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * A refusal.
	 *
	 * @param string $tag     Tag that was offered.
	 * @param string $message Why.
	 * @return UpdateCheck
	 */
	private function refused( string $tag, string $message ): UpdateCheck {
		return new UpdateCheck( UpdateCheck::REFUSED, $this->current_tag, $tag, $message );
	}

	/**
	 * Nothing was asked.
	 *
	 * @param string $message Why.
	 * @return UpdateCheck
	 */
	private function unavailable( string $message ): UpdateCheck {
		return new UpdateCheck( UpdateCheck::UNAVAILABLE, $this->current_tag, '', $message );
	}
}
