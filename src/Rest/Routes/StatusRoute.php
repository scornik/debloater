<?php
/**
 * GET debloater/v1/status.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use WP_REST_Request;
use WP_REST_Response;
use Debloater\Plugin;

/**
 * Reports what the runtime is actually doing (BUILD-SPEC §17 Phase 1, §11).
 *
 * This is the endpoint the `runtime_loaded` probe reads after an apply, so it
 * answers the questions that probe needs: is a runtime file present, does its
 * hash match what we recorded, and which loader put it in place. It reports
 * observed state rather than intended state — if the file on disk disagrees
 * with the lock, that disagreement is what gets reported.
 */
final class StatusRoute implements RouteInterface {

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
		return '/status';
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

		$context   = $this->plugin->context();
		$writer    = $this->plugin->runtimeWriter();
		$loader    = $this->plugin->runtimeLoader();
		$state     = $this->plugin->state();
		$selection = $state->selection();

		$actual   = $writer->actualHash();
		$recorded = $writer->recordedHash();
		$expected = $state->runtimeHash();

		return new WP_REST_Response(
			array(
				'plugin_version'  => $context->plugin_version,
				'registry_hash'   => $this->plugin->registry()->hash(),
				'selection'       => array_keys( $selection ),
				'selection_count' => count( $selection ),
				'runtime'         => array(
					'present'       => '' !== $actual,
					'hash'          => $actual,
					'recorded'      => $recorded,
					'expected'      => $expected,
					'intact'        => $writer->isIntact(),
					// The state option is what the plugin believes it generated;
					// a mismatch means something rewrote the file behind us.
					'matches_state' => '' === $expected ? '' === $actual : hash_equals( $expected, $actual ),
				),
				'loader'          => array(
					'mode'       => $loader->mode(),
					'installed'  => $loader->isInstalled(),
					'up_to_date' => $loader->isUpToDate(),
					'fallback'   => \Debloater\Apply\RuntimeLoader::MODE_FALLBACK === $loader->mode(),
				),
			),
			200
		);
	}
}
