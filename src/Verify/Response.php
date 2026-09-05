<?php
/**
 * What a verification request came back with.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify;

/**
 * One HTTP response, reduced to what a probe needs to judge it.
 *
 * A transport failure — loopback blocked, DNS refused, timeout — is a response
 * too, carrying `error` instead of a status. Probes must be able to tell "the
 * site answered badly" from "the site could not be reached at all", because the
 * first is a reason to roll back and the second is a reason to say so and warn.
 */
final class Response {

	/**
	 * HTTP status code, or 0 when the request never completed.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Response body.
	 *
	 * @var string
	 */
	public readonly string $body;

	/**
	 * Transport error message, or '' when the request completed.
	 *
	 * @var string
	 */
	public readonly string $error;

	/**
	 * Milliseconds the request took.
	 *
	 * @var int
	 */
	public readonly int $elapsed_ms;

	/**
	 * The URL requested, after redirects were followed.
	 *
	 * @var string
	 */
	public readonly string $url;

	/**
	 * Content type as reported by the response.
	 *
	 * @var string
	 */
	public readonly string $content_type;

	/**
	 * Where the response said to go next, or '' when it did not.
	 *
	 * Only ever set when the request was made without following redirects. A
	 * status code alone cannot tell a probe whether `/wp-admin/` sent it to the
	 * login page or to the https version of itself, and those mean different
	 * things.
	 *
	 * @var string
	 */
	public readonly string $location;

	/**
	 * Constructor.
	 *
	 * @param int    $status       HTTP status, 0 when unreachable.
	 * @param string $body         Response body.
	 * @param string $error        Transport error, '' when none.
	 * @param int    $elapsed_ms   Milliseconds elapsed.
	 * @param string $url          URL requested.
	 * @param string $content_type Content type header.
	 * @param string $location     Location header, when redirects were not followed.
	 */
	public function __construct(
		int $status,
		string $body = '',
		string $error = '',
		int $elapsed_ms = 0,
		string $url = '',
		string $content_type = '',
		string $location = ''
	) {
		$this->status       = $status;
		$this->body         = $body;
		$this->error        = $error;
		$this->elapsed_ms   = $elapsed_ms;
		$this->url          = $url;
		$this->content_type = $content_type;
		$this->location     = $location;
	}

	/**
	 * Whether the response was a redirect rather than a page.
	 *
	 * @return bool
	 */
	public function isRedirect(): bool {
		return $this->status >= 300 && $this->status < 400;
	}

	/**
	 * Whether the response redirected to the login form.
	 *
	 * @return bool
	 */
	public function redirectsToLogin(): bool {
		return $this->isRedirect() && false !== strpos( $this->location, 'wp-login.php' );
	}

	/**
	 * Whether the request completed at all.
	 *
	 * @return bool
	 */
	public function reachable(): bool {
		return '' === $this->error;
	}

	/**
	 * Whether the status is in the 2xx range.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether the body is empty once whitespace is discounted.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return '' === trim( $this->body );
	}

	/**
	 * The response decoded as JSON, or null when it is not valid JSON.
	 *
	 * @return array<mixed>|null
	 */
	public function json(): ?array {
		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Evidence common to every probe that made a request.
	 *
	 * @return array<string,scalar|null>
	 */
	public function evidence(): array {
		return array(
			'http_status' => $this->status,
			'elapsed_ms'  => $this->elapsed_ms,
			'bytes'       => strlen( $this->body ),
			'url'         => $this->url,
		);
	}
}
