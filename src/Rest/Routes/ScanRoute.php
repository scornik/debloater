<?php
/**
 * POST wpdebloat/v1/scan.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Rest\Routes;

use WP_REST_Request;
use WP_REST_Response;
use WPDebloat\Plugin;

/**
 * Runs a scan and returns its findings (BUILD-SPEC §17 Phase 3).
 *
 * POST rather than GET, because it creates a run: a scan is recorded, gets an
 * id, and becomes the thing later screens refer to. Making it a GET would let a
 * prefetch or a crawler create runs.
 *
 * A scan reads and records; it changes nothing about the site. Rest\Controller
 * still applies the capability check, because what it returns is a detailed
 * description of the site's configuration.
 */
final class ScanRoute implements RouteInterface {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Route path.
	 *
	 * @return string
	 */
	public function path(): string {
		return '/scan';
	}

	/**
	 * HTTP methods.
	 *
	 * @return string
	 */
	public function methods(): string {
		return 'POST';
	}

	/**
	 * Argument definitions. This route takes none.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function args(): array {
		return array();
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		unset( $request );

		$run = $this->plugin->scan();

		return new WP_REST_Response(
			array(
				'run_id'        => $run->id,
				'status'        => $run->status,
				'started_at'    => $run->started_at,
				'finished_at'   => $run->finished_at,
				'registry_hash' => $run->registry_hash,
				'facts_count'   => $run->facts()->count(),
				'diagnostics'   => $run->payload['diagnostics'] ?? array(),
				'analysis'      => $run->payload['analysis'] ?? array(),
			),
			201
		);
	}
}
