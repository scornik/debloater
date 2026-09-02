<?php
/**
 * GET wpdebloat/v1/preview.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Rest\Routes;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPDebloat\Plugin;
use WPDebloat\Registry\Profile;
use WPDebloat\Rest\ConfirmationToken;

/**
 * Shows what a plan would do, without doing any of it
 * (BUILD-SPEC §17 Phase 4).
 *
 * A GET, because it changes nothing: it reads a recorded scan and computes what
 * the plan would be. Nothing is applied, nothing is written, and no run is
 * created. The applying happens in Phase 5, behind an explicit confirmation.
 *
 * The response carries the exclusions as well as the plan. A user looking at
 * six changes after a scan that found eleven findings is owed an account of the
 * other five.
 */
final class PreviewRoute implements RouteInterface {

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
		return '/preview';
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
			'profile' => array(
				'description' => __( 'Which profile to plan with.', 'wp-debloat' ),
				'type'        => 'string',
				'enum'        => array_keys( $this->plugin->registry()->profiles() ),
				'required'    => false,
			),
			'run_id'  => array(
				'description' => __( 'Plan from a specific scan instead of the most recent one.', 'wp-debloat' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => false,
			),
			'tweaks'  => array(
				'description' => __( 'Plan these specific changes instead of a profile.', 'wp-debloat' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'required'    => false,
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
		$profile_id = is_string( $request->get_param( 'profile' ) ) ? $request->get_param( 'profile' ) : null;
		$run_id     = is_numeric( $request->get_param( 'run_id' ) ) ? (int) $request->get_param( 'run_id' ) : null;
		$tweaks     = $request->get_param( 'tweaks' );

		$result = is_array( $tweaks ) && array() !== $tweaks
			? $this->plugin->previewTweaks( array_values( array_filter( $tweaks, 'is_string' ) ), $run_id )
			: $this->plugin->preview( $profile_id, $run_id );

		if ( null === $result ) {
			return new WP_Error(
				'wpdebloat_not_scanned',
				__( 'There is nothing to preview yet. Run a scan first.', 'wp-debloat' ),
				array( 'status' => 409 )
			);
		}

		$profile = $this->plugin->registry()->profile( $profile_id ?? Profile::SAFE );

		return new WP_REST_Response(
			array(
				'profile'     => array(
					'id'          => $profile->id,
					'title'       => $profile->title,
					'description' => $profile->description,
				),
				'plan'        => $result->plan->toArray(),
				'excluded'    => (object) $result->excluded,
				'count'       => $result->count(),
				'destructive' => $result->plan->destructive,
				// The dashboard sends this back with the apply. It is what makes
				// "the user confirmed this plan" true rather than merely likely:
				// a plan that has changed since the preview will not match it.
				'confirm'     => ConfirmationToken::forPlan( $result->plan ),
			),
			200
		);
	}
}
