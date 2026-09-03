<?php
/**
 * The one place verification talks to the site.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify;

use WP_Error;
use WPDebloat\Contracts\Context;

/**
 * Loopback requests for the probes (BUILD-SPEC §11).
 *
 * Every probe goes through here so that the rules apply once: a fifteen-second
 * timeout, `sslverify` following the site's own setting rather than being
 * disabled for convenience, and an `X-WPDebloat-Verify: 1` header.
 *
 * That header is for the site owner reading their access log, and for nothing
 * else. Nothing in this plugin reads it, and no handler may behave differently
 * when it is present — a verification that the site passes only because it knew
 * it was being verified has verified nothing.
 *
 * Requests are never followed off-site: a redirect to another host is reported
 * as a redirect rather than chased, because a probe that follows one is no
 * longer measuring this site.
 */
final class HttpClient {

	/**
	 * Request timeout in seconds (§11).
	 */
	public const TIMEOUT = 15;

	/**
	 * The header added to every verification request.
	 */
	public const HEADER = 'X-WPDebloat-Verify';

	/**
	 * Redirects followed before giving up.
	 */
	private const MAX_REDIRECTS = 3;

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Authentication for requests made as the acting user.
	 *
	 * @var ActorSession
	 */
	private ActorSession $session;

	/**
	 * Seconds to wait for one request.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param Context           $context Site context.
	 * @param ActorSession|null $session Actor authentication.
	 * @param int|null          $timeout Seconds to wait, defaulting to TIMEOUT.
	 *                                   The asset scan asks for less: it makes
	 *                                   ten requests where verification makes
	 *                                   one, and a page that has not answered in
	 *                                   five seconds is not going to make the
	 *                                   sample better by answering in fifteen.
	 */
	public function __construct( Context $context, ?ActorSession $session = null, ?int $timeout = null ) {
		$this->context = $context;
		$this->session = $session ?? new ActorSession( $context );
		$this->timeout = null === $timeout ? self::TIMEOUT : max( 1, $timeout );
	}

	/**
	 * How long one request may take.
	 *
	 * @return int
	 */
	public function timeout(): int {
		return $this->timeout;
	}

	/**
	 * Fetch a URL as an anonymous visitor.
	 *
	 * @param string $url URL to fetch.
	 * @return Response
	 */
	public function get( string $url ): Response {
		return $this->request( $url, array() );
	}

	/**
	 * Fetch a URL as the user who asked for the change.
	 *
	 * Used for `/wp-admin/` and for the plugin's own REST endpoint, neither of
	 * which an anonymous request can see. When there is no usable session the
	 * request still goes out unauthenticated, and the probe reports what it
	 * could not check rather than claiming a pass.
	 *
	 * @param string $url URL to fetch.
	 * @return Response
	 */
	public function getAsActor( string $url ): Response {
		return $this->request( $url, $this->session->headers() );
	}

	/**
	 * Whether a request can be made as the acting user at all.
	 *
	 * @return bool
	 */
	public function canActAsUser(): bool {
		return $this->session->isAvailable();
	}

	/**
	 * Destroy any credential created for this verification.
	 *
	 * @return void
	 */
	public function releaseSession(): void {
		$this->session->release();
	}

	/**
	 * Whether the site can reach itself.
	 *
	 * Answered by asking for the home page, since that is a request the site has
	 * to be able to serve anyway. A failure here means every HTTP probe will
	 * fail the same way, which §11 says is a warning about the environment
	 * rather than a verdict on the change.
	 *
	 * @return Response
	 */
	public function loopbackCheck(): Response {
		return $this->get( $this->context->home_url );
	}

	/**
	 * Perform the request.
	 *
	 * @param string                $url     URL to fetch.
	 * @param array<string,string>  $headers Extra headers.
	 * @return Response
	 */
	private function request( string $url, array $headers ): Response {
		$started = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => self::MAX_REDIRECTS,
				'sslverify'   => $this->sslVerify(),
				'headers'     => array_merge( array( self::HEADER => '1' ), $headers ),
				'user-agent'  => 'WPDebloat/' . $this->context->plugin_version . '; verification',
				// A cached response would tell us about the past, and the whole
				// question is what the site is doing now.
				'cookies'     => array(),
			)
		);

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( $response instanceof WP_Error ) {
			return new Response( 0, '', $response->get_error_message(), $elapsed, $url );
		}

		return new Response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			'',
			$elapsed,
			$url,
			(string) wp_remote_retrieve_header( $response, 'content-type' )
		);
	}

	/**
	 * Whether to verify TLS certificates.
	 *
	 * The site's own setting, not ours. A site with a self-signed certificate on
	 * a staging domain has already decided; overriding it here would either
	 * break verification on that site or quietly lower the bar on every other
	 * one.
	 *
	 * @return bool
	 */
	private function sslVerify(): bool {
		/** This filter is documented in wp-includes/class-wp-http.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, read rather than introduced.
		return (bool) apply_filters( 'https_local_ssl_verify', true );
	}
}
