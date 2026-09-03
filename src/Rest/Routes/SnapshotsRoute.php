<?php
/**
 * GET debloater/v1/snapshots.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use WP_REST_Request;
use WP_REST_Response;
use Debloater\Contracts\Run;
use Debloater\Contracts\RunType;
use Debloater\Contracts\Snapshot;
use Debloater\Plugin;
use Debloater\Rest\ConfirmationToken;

/**
 * The runs and their recovery points (BUILD-SPEC §17 Phase 8).
 *
 * Read-only, and it is where the dashboard gets the confirmation tokens for
 * restoring. Issuing the token here rather than letting the client compute one
 * is the point: a restore is only possible for a recovery point the server has
 * just described, in the state it just described it in.
 *
 * A snapshot that cannot be restored still appears, with the reason. Hiding it
 * would leave a user wondering where their recovery point went; showing it with
 * "this was taken on a different site" answers the question.
 */
final class SnapshotsRoute implements RouteInterface {

	/**
	 * How many runs to describe.
	 */
	private const LIMIT = 20;

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
		return '/snapshots';
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

		$rollback  = $this->plugin->rollbackManager();
		$snapshots = array();

		foreach ( $this->plugin->snapshots()->recent( self::LIMIT ) as $snapshot ) {
			$refusal = $rollback->refusalReason( $snapshot );

			$snapshots[] = array_merge(
				$snapshot->toArray(),
				array(
					'restorable' => null === $refusal,
					'refusal'    => $refusal,
					// Only issued for a recovery point that can actually be used;
					// a token for one that cannot would be an invitation to try.
					'confirm'    => null === $refusal ? ConfirmationToken::forSnapshot( $snapshot ) : null,
				)
			);
		}

		$runs = array();

		foreach ( $this->plugin->runs()->recent( self::LIMIT, RunType::APPLY ) as $run ) {
			$runs[] = $this->describeRun( $run );
		}

		return new WP_REST_Response(
			array(
				'runs'      => $runs,
				'snapshots' => $snapshots,
			),
			200
		);
	}

	/**
	 * A run, reduced to what the list needs.
	 *
	 * The whole payload carries the plan, the result and the state history,
	 * which is a great deal of JSON for a list of rows. The detail screens ask
	 * for what they need.
	 *
	 * @param Run $run The run.
	 * @return array<string,mixed>
	 */
	private function describeRun( Run $run ): array {
		$result = is_array( $run->payload['result'] ?? null ) ? $run->payload['result'] : array();

		return array(
			'id'          => (int) $run->id,
			'status'      => $run->status,
			'actor'       => $run->actor,
			'started_at'  => $run->started_at,
			'finished_at' => $run->finished_at,
			'error'       => $run->error,
			'applied'     => is_array( $result['applied'] ?? null ) ? $result['applied'] : array(),
			'warnings'    => is_array( $result['warnings'] ?? null ) ? $result['warnings'] : array(),
		);
	}
}
