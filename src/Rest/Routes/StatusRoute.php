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
		$state     = $this->plugin->state();
		$selection = $state->selection();
		$handlers  = $this->plugin->runtime()->registeredClasses();

		return new WP_REST_Response(
			array(
				'plugin_version'  => $context->plugin_version,
				'registry_hash'   => $this->plugin->registry()->hash(),
				'selection'       => array_keys( $selection ),
				'selection_count' => count( $selection ),
				// There is no generated file to be present, intact, or to
				// disagree with the state option, because there is no generated
				// file (D-0070). What is reportable is what the selection
				// resolves to and what it hashes to.
				'runtime'         => array(
					'handlers'       => count( $handlers ),
					'selection_hash' => $state->selectionHash(),
				),
			),
			200
		);
	}
}
