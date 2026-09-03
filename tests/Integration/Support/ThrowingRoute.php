<?php
/**
 * A REST route that does nothing but throw.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration\Support;

use Debloater\Rest\Routes\RouteInterface;
use WP_REST_Request;

/**
 * A route that does nothing but throw.
 *
 * Not a mock. The point of the test is what `Controller` does with a route
 * whose `handle()` raises, and the only way to ask that honestly is to give it
 * one and register it the way every real route is registered.
 */
final class ThrowingRoute implements RouteInterface {

	/**
	 * The message to throw, chosen to matter if it were ever printed raw.
	 */
	public const MESSAGE = '<script>alert("xss")</script> & \'quoted\'';

	/**
	 * Route path.
	 *
	 * @return string
	 */
	public function path(): string {
		return '/test-throws';
	}

	/**
	 * HTTP methods.
	 *
	 * @return string
	 */
	public function methods(): string {
		return 'GET';
	}

	/**
	 * Arguments.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function args(): array {
		return array();
	}

	/**
	 * Throw, the way the engine does.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \RuntimeException Always.
	 */
	public function handle( WP_REST_Request $request ) {
		unset( $request );

		throw new \RuntimeException( self::MESSAGE );
	}
}
