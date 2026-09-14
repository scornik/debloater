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
 * Reports the selection, and what the runtime did with it in this request.
 *
 * `runtime.stored` is the handler list the next request will try to register.
 * `runtime.registered` and `runtime.skipped` are what *this* request's
 * `Apply\Runtime::load()` actually did at `plugins_loaded`, and
 * `runtime.guard` whether the guard let it run at all. The difference between
 * the first and the second is the thing worth knowing, and it is only
 * observable from inside a request that loaded the runtime — which is why the
 * `runtime_registered` probe asks this endpoint over loopback rather than
 * asking the apply request, which loaded the selection from before the change
 * (`D-0079`). `effects` is, for each selected change, whether the fact the
 * registry declares for it reads as it should in this request, which the
 * `effects_observed` probe reads the same way.
 *
 * Until 0.5.0 this comment said the route reported a runtime file, its hash and
 * its loader, and that a `runtime_loaded` probe read it. The file went in 0.3.0
 * (`D-0070`) and so did the probe; the route reported only a count of stored
 * handlers, which read as though they had registered.
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

		return new WP_REST_Response(
			array(
				'plugin_version'  => $context->plugin_version,
				'registry_hash'   => $this->plugin->registry()->hash(),
				'selection'       => array_keys( $selection ),
				'selection_count' => count( $selection ),
				'runtime'         => $this->plugin->runtimeStatus(),
				// Read in this request, after its runtime registered: the
				// `effects_observed` probe asks for this over loopback (D-0079).
				'effects'         => $this->plugin->effectReport(),
			),
			200
		);
	}
}
