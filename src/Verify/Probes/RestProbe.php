<?php
/**
 * Does the REST API still answer.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Contracts\Context;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;

/**
 * GET `/wp-json/` and `/wp-json/wp/v2/types` (BUILD-SPEC §11).
 *
 * Two requests rather than one, because they fail differently: the index
 * answers even when almost nothing is registered, while `wp/v2/types` needs the
 * core routes to have been registered properly. A change that unhooks
 * `rest_api_init` shows up in the second and not the first.
 *
 * A 401 is a warning, not a failure. Plenty of sites deliberately close the
 * REST API to anonymous callers, and treating their considered security choice
 * as a broken site would be both wrong and insulting.
 */
final class RestProbe extends AbstractHttpProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'rest';
	}

	/**
	 * Fetch the REST index and the types route.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		foreach ( array( rest_url(), rest_url( 'wp/v2/types' ) ) as $url ) {
			$response = $this->http->get( $url );

			if ( ! $response->reachable() ) {
				return $this->unreachable( $response );
			}

			if ( 401 === $response->status || 403 === $response->status ) {
				return new ProbeResult(
					$this->name(),
					ProbeStatus::WARN,
					sprintf(
						/* translators: 1: URL, 2: HTTP status code. */
						__( 'The REST API refused an anonymous request to %1$s with HTTP %2$d. If that is deliberate, nothing is wrong.', 'debloater' ),
						$url,
						$response->status
					),
					$response->evidence()
				);
			}

			if ( ! $response->isSuccess() ) {
				return new ProbeResult(
					$this->name(),
					ProbeStatus::FAIL,
					sprintf(
						/* translators: 1: URL, 2: HTTP status code. */
						__( 'The REST API returned HTTP %2$d for %1$s.', 'debloater' ),
						$url,
						$response->status
					),
					$response->evidence()
				);
			}

			if ( null === $response->json() ) {
				return new ProbeResult(
					$this->name(),
					ProbeStatus::FAIL,
					sprintf(
						/* translators: %s: URL. */
						__( 'The REST API answered %s with something that is not valid JSON, which usually means output from somewhere else got into the response.', 'debloater' ),
						$url
					),
					array_merge(
						$response->evidence(),
						array( 'body_starts_with' => substr( trim( $response->body ), 0, 80 ) )
					)
				);
			}
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			__( 'The REST API answered normally.', 'debloater' ),
			array( 'routes_checked' => 2 )
		);
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The REST API', 'debloater' );
	}
}
