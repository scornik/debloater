<?php
/**
 * The pages a scan looked at, fetched once.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan;

use Debloater\Contracts\Context;
use Debloater\Verify\HttpClient;

/**
 * Fetches the page sample once and lends it to whoever needs it
 * (BUILD-SPEC §17 Phases 13 and 15).
 *
 * Phase 13 introduced page fetching for the asset scan. Phase 15 needs the same
 * pages to work out which of them are WooCommerce pages, and scanners are
 * deliberately isolated from one another — each gets a fresh `FactSet` and
 * cannot read another's facts. Without something like this, the second scanner
 * would fetch every page again, doubling a scan's loopback traffic to learn
 * nothing new.
 *
 * So the sample is a shared service rather than a scanner's private business.
 * It fetches at most once per scan, and hands out the same bodies to everybody.
 *
 * The freshness rule is strict on purpose: `forget()` is called at the start of
 * every scan, so no scan is ever answered with a page fetched during a previous
 * one. A cached page from ten minutes ago is not an observation of this site
 * now, and the whole product rests on facts being observations.
 */
final class SampledPages {

	/**
	 * Seconds to wait for one page.
	 */
	public const PAGE_TIMEOUT = 5;

	/**
	 * Milliseconds the whole fetch may take before it stops early.
	 *
	 * The exit criterion for Phase 13 is a scan under ten seconds. This is what
	 * makes that true on a slow site rather than hoped for: when the budget is
	 * gone, what has been fetched is what everybody gets, and the shortfall is
	 * visible because `offered()` and the number of pages disagree.
	 */
	public const BUDGET_MS = 8000;

	/**
	 * The client used for page fetches, when one was supplied.
	 *
	 * @var HttpClient|null
	 */
	private ?HttpClient $http;

	/**
	 * Fetched pages, or null before the first fetch of this scan.
	 *
	 * @var array<int,array{url:string,post_type:string,body:string}>|null
	 */
	private ?array $pages = null;

	/**
	 * Whether the site could be reached at all.
	 *
	 * @var bool
	 */
	private bool $available = false;

	/**
	 * Why it could not, when it could not.
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * How many URLs the sample chose before fetching began.
	 *
	 * @var int
	 */
	private int $offered = 0;

	/**
	 * Milliseconds spent fetching.
	 *
	 * @var int
	 */
	private int $elapsed_ms = 0;

	/**
	 * Constructor.
	 *
	 * @param HttpClient|null $http Client to fetch with; built per scan when omitted.
	 */
	public function __construct( ?HttpClient $http = null ) {
		$this->http = $http;
	}

	/**
	 * Throw away everything, so the next ask fetches again.
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->pages      = null;
		$this->available  = false;
		$this->reason     = '';
		$this->offered    = 0;
		$this->elapsed_ms = 0;
	}

	/**
	 * Whether the pages could be read at all.
	 *
	 * @param Context $context Site context.
	 * @return bool
	 */
	public function available( Context $context ): bool {
		$this->fetch( $context );

		return $this->available;
	}

	/**
	 * Why the pages could not be read.
	 *
	 * @param Context $context Site context.
	 * @return string
	 */
	public function reason( Context $context ): string {
		$this->fetch( $context );

		return $this->reason;
	}

	/**
	 * The pages, each with the post type it represents and its body.
	 *
	 * @param Context $context Site context.
	 * @return array<int,array{url:string,post_type:string,body:string}>
	 */
	public function pages( Context $context ): array {
		$this->fetch( $context );

		return $this->pages ?? array();
	}

	/**
	 * How many URLs the sample chose before fetching began.
	 *
	 * @param Context $context Site context.
	 * @return int
	 */
	public function offered( Context $context ): int {
		$this->fetch( $context );

		return $this->offered;
	}

	/**
	 * Milliseconds spent fetching.
	 *
	 * @param Context $context Site context.
	 * @return int
	 */
	public function elapsedMs( Context $context ): int {
		$this->fetch( $context );

		return $this->elapsed_ms;
	}

	/**
	 * Fetch the sample, at most once.
	 *
	 * @param Context $context Site context.
	 * @return void
	 */
	private function fetch( Context $context ): void {
		if ( null !== $this->pages ) {
			return;
		}

		$this->pages = array();

		$http     = $this->http ?? new HttpClient( $context, null, self::PAGE_TIMEOUT );
		$loopback = $http->loopbackCheck();

		if ( ! $loopback->reachable() ) {
			// Ten timeouts would tell us the same thing as one, five seconds at
			// a time. Say what happened and stop.
			$this->reason = $this->describe( $loopback->error, $loopback->status );

			return;
		}

		$this->available = true;

		$started = microtime( true );
		$sample  = PageSample::urls( $context->home_url );

		$this->offered = count( $sample );

		foreach ( $sample as $page ) {
			if ( ( microtime( true ) - $started ) * 1000 >= self::BUDGET_MS ) {
				break;
			}

			$response = $http->get( $page['url'] );

			if ( ! $response->isSuccess() || $response->isEmpty() ) {
				continue;
			}

			$this->pages[] = array(
				'url'       => $page['url'],
				'post_type' => $page['post_type'],
				'body'      => $response->body,
			);
		}

		$this->elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * A short, honest reason the pages could not be read.
	 *
	 * @param string $error  Transport error, if any.
	 * @param int    $status HTTP status, if any.
	 * @return string
	 */
	private function describe( string $error, int $status ): string {
		if ( '' !== $error ) {
			return $error;
		}

		return $status > 0
			? sprintf( 'The site answered its own request with HTTP %d.', $status )
			: 'The site could not reach itself.';
	}
}
