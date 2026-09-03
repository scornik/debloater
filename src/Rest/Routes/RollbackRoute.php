<?php
/**
 * POST debloater/v1/rollback.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Debloater\Contracts\RunState;
use Debloater\Contracts\SnapshotLevel;
use Debloater\Plugin;
use Debloater\Rest\ConfirmationToken;

/**
 * Puts a site back (BUILD-SPEC §17 Phase 8).
 *
 * Restoring is itself a change to the site, so it is gated exactly as applying
 * is: capability, nonce, and a confirmation token derived from the recovery
 * point being restored. The token is bound to the snapshot's checksum, so a
 * confirmation for one recovery point cannot restore another.
 *
 * The whole run is undone, not the single snapshot named. A run's configuration
 * and its data are two halves of one change, and restoring one without the
 * other would leave the site in a state nothing has a name for.
 */
final class RollbackRoute implements RouteInterface {

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
		return '/rollback';
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
	 * Argument definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function args(): array {
		return array(
			'snapshot_id' => array(
				'description' => __( 'The recovery point to go back to. Defaults to the most recent one.', 'debloater' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => false,
			),
			'confirm'     => array(
				'description' => __( 'The confirmation token for this recovery point.', 'debloater' ),
				'type'        => 'string',
				'required'    => true,
				'minLength'   => 64,
				'maxLength'   => 64,
			),
		);
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$snapshot_id = $request->get_param( 'snapshot_id' );

		$snapshot = is_numeric( $snapshot_id )
			? $this->plugin->snapshots()->find( (int) $snapshot_id )
			: $this->plugin->snapshots()->latestRestorable( SnapshotLevel::A );

		if ( null === $snapshot ) {
			return new WP_Error(
				'debloater_no_snapshot',
				__( 'There is no recovery point to restore.', 'debloater' ),
				array( 'status' => 404 )
			);
		}

		if ( ! ConfirmationToken::matchesSnapshot( $snapshot, (string) $request->get_param( 'confirm' ) ) ) {
			return new WP_Error(
				'debloater_stale_confirmation',
				__( 'That confirmation does not match this recovery point. Reload the list and try again.', 'debloater' ),
				array( 'status' => 409 )
			);
		}

		$refusal = $this->plugin->rollbackManager()->refusalReason( $snapshot );

		if ( null !== $refusal ) {
			return new WP_Error( 'debloater_not_restorable', $refusal, array( 'status' => 409 ) );
		}

		$result = $this->plugin->rollback( $snapshot->run_id );

		return new WP_REST_Response(
			array(
				'result' => $result->toArray(),
				'run_id' => $result->run_id,
				'state'  => $result->state->value,
				'ok'     => RunState::ROLLED_BACK === $result->state,
			),
			200
		);
	}
}
