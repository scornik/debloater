<?php
/**
 * Contract for a REST route.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use WP_REST_Request;

/**
 * One REST route.
 *
 * Routes describe themselves and handle requests. They never declare their own
 * permission callback: Rest\Controller supplies one for all of them, so the
 * capability check cannot be forgotten on a new route (BUILD-SPEC §13 rule 1).
 */
interface RouteInterface {

	/**
	 * Route path, relative to the plugin's REST namespace.
	 *
	 * @return string
	 */
	public function path(): string;

	/**
	 * HTTP methods this route answers.
	 *
	 * @return string
	 */
	public function methods(): string;

	/**
	 * Argument definitions, used by WordPress for validation and sanitisation.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function args(): array;

	/**
	 * Handle a request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( WP_REST_Request $request );
}
