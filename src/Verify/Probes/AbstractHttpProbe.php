<?php
/**
 * Shared behaviour for probes that fetch a page.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Contracts\Context;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\ProbeInterface;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;
use Debloater\Verify\HttpClient;
use Debloater\Verify\Markers;
use Debloater\Verify\Response;

/**
 * The parts every HTTP probe repeats (BUILD-SPEC §11).
 *
 * Judging an HTML page is the same work each time — unreachable, non-2xx, fatal
 * marker, empty body, then the markers particular to that page — and the order
 * matters: a page that returned 500 *and* contains "Fatal error" should be
 * reported as the fatal, because that is the useful half of the news.
 */
abstract class AbstractHttpProbe implements ProbeInterface {

	/**
	 * The HTTP client.
	 *
	 * @var HttpClient
	 */
	protected HttpClient $http;

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http The HTTP client.
	 */
	public function __construct( HttpClient $http ) {
		$this->http = $http;
	}

	/**
	 * Most probes apply everywhere; those that do not override this.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts from the most recent scan.
	 * @return bool
	 */
	public function applies( Context $context, FactSet $facts ): bool {
		unset( $context, $facts );

		return true;
	}

	/**
	 * A result for a request that never completed.
	 *
	 * Never FAIL: a site that cannot reach itself has told us nothing about the
	 * change we just made, and rolling back on that basis would undo good work
	 * because of a firewall rule (§11, docs/DECISIONS.md D-0020).
	 *
	 * @param Response $response The failed response.
	 * @return ProbeResult
	 */
	protected function unreachable( Response $response ): ProbeResult {
		return new ProbeResult(
			$this->name(),
			ProbeStatus::UNKNOWN,
			sprintf(
				/* translators: %s: the underlying connection error. */
				__( 'This site could not reach itself over HTTP, so this check could not run: %s', 'debloater' ),
				$response->error
			),
			array_merge( $response->evidence(), array( 'error' => $response->error ) )
		);
	}

	/**
	 * A result for a page that came back but is not a working page.
	 *
	 * @param Response $response The response.
	 * @return ProbeResult|null Null when nothing is wrong at this level.
	 */
	protected function judgeHtml( Response $response ): ?ProbeResult {
		if ( ! $response->reachable() ) {
			// A redirect loop is the site misbehaving, not the environment
			// refusing to let us ask (§11 lists it as a failure for `admin`).
			if ( false !== stripos( $response->error, 'too many redirects' ) ) {
				return new ProbeResult(
					$this->name(),
					ProbeStatus::FAIL,
					sprintf(
						/* translators: %s: page description. */
						__( '%s redirected in a loop and never arrived anywhere.', 'debloater' ),
						$this->describe()
					),
					array_merge( $response->evidence(), array( 'error' => $response->error ) )
				);
			}

			return $this->unreachable( $response );
		}

		$fatal = Markers::fatalIn( $response->body );

		if ( '' !== $fatal ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				sprintf(
					/* translators: 1: page description, 2: the marker found. */
					__( '%1$s returned an error page containing "%2$s".', 'debloater' ),
					$this->describe(),
					$fatal
				),
				array_merge( $response->evidence(), array( 'fatal_marker' => $fatal ) )
			);
		}

		if ( ! $response->isSuccess() ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				sprintf(
					/* translators: 1: page description, 2: HTTP status code. */
					__( '%1$s returned HTTP %2$d.', 'debloater' ),
					$this->describe(),
					$response->status
				),
				$response->evidence()
			);
		}

		if ( $response->isEmpty() ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::FAIL,
				sprintf(
					/* translators: %s: page description. */
					__( '%s returned an empty page.', 'debloater' ),
					$this->describe()
				),
				$response->evidence()
			);
		}

		return null;
	}

	/**
	 * The usual "it rendered" check: a complete document, ideally with a title.
	 *
	 * @param Response $response The response.
	 * @return ProbeResult
	 */
	protected function judgeRendered( Response $response ): ProbeResult {
		$missing = Markers::missing(
			$response->body,
			array_merge( Markers::DOCUMENT_END, Markers::DOCUMENT_TITLE )
		);

		if ( array() !== $missing ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::WARN,
				sprintf(
					/* translators: 1: page description, 2: comma-separated markers. */
					__( '%1$s loaded, but the page looks incomplete: %2$s not found.', 'debloater' ),
					$this->describe(),
					implode( ', ', $missing )
				),
				array_merge( $response->evidence(), array( 'missing_markers' => implode( ',', $missing ) ) )
			);
		}

		return new ProbeResult(
			$this->name(),
			ProbeStatus::PASS,
			sprintf(
				/* translators: %s: page description. */
				__( '%s loaded normally.', 'debloater' ),
				$this->describe()
			),
			$response->evidence()
		);
	}

	/**
	 * A short description of what this probe fetches, for its messages.
	 *
	 * @return string
	 */
	abstract protected function describe(): string;
}
