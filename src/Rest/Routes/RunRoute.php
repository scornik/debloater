<?php
/**
 * GET debloater/v1/runs/<id>.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Debloater\Contracts\RunState;
use Debloater\Plugin;

/**
 * One run, in enough detail to watch it happen (BUILD-SPEC §17 Phase 9).
 *
 * The dashboard polls this while an apply is running and renders each state the
 * run passes through. Showing the state machine to the user is not decoration:
 * a change that stops halfway is far less alarming when the screen has been
 * saying "taking a recovery point" and then "checking the site" than when it has
 * been showing a spinner.
 *
 * Read-only, and it never starts anything.
 */
final class RunRoute implements RouteInterface {

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
		return '/runs/(?P<id>\d+)';
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
	 * Argument definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function args(): array {
		return array(
			'id' => array(
				'description' => __( 'The run to read.', 'debloater' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
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
		$run = $this->plugin->runs()->find( (int) $request->get_param( 'id' ) );

		if ( null === $run ) {
			return new WP_Error(
				'debloater_no_run',
				__( 'There is no change with that id.', 'debloater' ),
				array( 'status' => 404 )
			);
		}

		$state    = RunState::tryFrom( $run->status );
		$result   = is_array( $run->payload['result'] ?? null ) ? $run->payload['result'] : null;
		$history  = is_array( $run->payload['history'] ?? null ) ? $run->payload['history'] : array();
		$measured = is_array( $run->payload['measurements'] ?? null ) ? $run->payload['measurements'] : null;

		return new WP_REST_Response(
			array(
				'id'           => (int) $run->id,
				'type'         => $run->type->value,
				'status'       => $run->status,
				'label'        => null === $state ? $run->status : $this->label( $state ),
				'finished'     => null === $state || $state->isTerminal() || RunState::ROLLED_BACK === $state,
				'actor'        => $run->actor,
				'started_at'   => $run->started_at,
				'finished_at'  => $run->finished_at,
				'error'        => $run->error,
				'history'      => array_values( array_filter( $history, 'is_string' ) ),
				'result'       => $result,
				'measurements' => $measured,
			),
			200
		);
	}

	/**
	 * What a state means, in words a person can read while waiting.
	 *
	 * @param RunState $state The state.
	 * @return string
	 */
	private function label( RunState $state ): string {
		return match ( $state ) {
			RunState::IDLE                   => __( 'Waiting to start', 'debloater' ),
			RunState::PLANNING               => __( 'Working out what to change', 'debloater' ),
			RunState::PREVIEWED              => __( 'Waiting for you to confirm', 'debloater' ),
			RunState::LOCKED                 => __( 'Holding the site so nothing else changes it', 'debloater' ),
			RunState::MEASURING_BEFORE       => __( 'Counting what is there now', 'debloater' ),
			RunState::SNAPSHOTTING           => __( 'Taking a recovery point', 'debloater' ),
			RunState::APPLYING               => __( 'Applying the changes', 'debloater' ),
			RunState::APPLIED                => __( 'Changes applied', 'debloater' ),
			RunState::APPLY_FAILED           => __( 'Something went wrong while applying', 'debloater' ),
			RunState::VERIFYING              => __( 'Checking the site still works', 'debloater' ),
			RunState::VERIFIED               => __( 'The site checked out', 'debloater' ),
			RunState::VERIFIED_WITH_WARNINGS => __( 'The site works, with some checks incomplete', 'debloater' ),
			RunState::VERIFICATION_FAILED    => __( 'The site did not pass its checks', 'debloater' ),
			RunState::MEASURING_AFTER        => __( 'Counting what is there now', 'debloater' ),
			RunState::COMMITTED              => __( 'Done', 'debloater' ),
			RunState::ROLLING_BACK           => __( 'Putting the site back', 'debloater' ),
			RunState::ROLLED_BACK            => __( 'Rollback complete', 'debloater' ),
			RunState::ABORTED                => __( 'Stopped before anything changed', 'debloater' ),
			RunState::INTERRUPTED            => __( 'Interrupted partway through', 'debloater' ),
		};
	}
}
