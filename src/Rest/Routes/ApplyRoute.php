<?php
/**
 * POST wpdebloat/v1/apply.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Rest\Routes;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPDebloat\Contracts\RunState;
use WPDebloat\Plugin;
use WPDebloat\Rest\ConfirmationToken;

/**
 * Applies a plan the user has been shown (BUILD-SPEC §17 Phase 8).
 *
 * The only endpoint in the plugin that changes a site from a browser, and it is
 * deliberately awkward to call: it needs the capability, a valid nonce, and a
 * confirmation token derived from the exact plan being applied.
 *
 * The token is what makes "the user confirmed this" true rather than merely
 * plausible. A plan built now is compared against the plan the token was issued
 * for; if a plugin was activated in another tab, or a scan ran in between, the
 * plan has changed and the request is refused with an explanation instead of
 * quietly applying something the user has not seen.
 */
final class ApplyRoute implements RouteInterface {

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
		return '/apply';
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
			'profile'     => array(
				'description' => __( 'Which profile to apply.', 'wp-debloat' ),
				'type'        => 'string',
				'enum'        => array_keys( $this->plugin->registry()->profiles() ),
				'required'    => false,
			),
			'tweaks'      => array(
				'description' => __( 'Specific changes to apply, instead of a profile.', 'wp-debloat' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'required'    => false,
			),
			'confirm'     => array(
				'description' => __( 'The confirmation token from the preview of this exact plan.', 'wp-debloat' ),
				'type'        => 'string',
				'required'    => true,
				'minLength'   => 64,
				'maxLength'   => 64,
			),
			'attestation' => array(
				'description' => __(
					'The user states they have their own external backup. Recorded, and never a substitute for the recovery point WP Debloat takes itself.',
					'wp-debloat'
				),
				'type'        => 'boolean',
				'required'    => false,
				'default'     => false,
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
		$tweaks = $request->get_param( 'tweaks' );
		$result = is_array( $tweaks ) && array() !== $tweaks
			? $this->plugin->previewTweaks( array_values( array_filter( $tweaks, 'is_string' ) ) )
			: $this->plugin->preview( is_string( $request->get_param( 'profile' ) ) ? $request->get_param( 'profile' ) : null );

		if ( null === $result ) {
			return new WP_Error(
				'wpdebloat_not_scanned',
				__( 'There is nothing to apply yet. Run a scan first.', 'wp-debloat' ),
				array( 'status' => 409 )
			);
		}

		if ( $result->plan->isEmpty() ) {
			return new WP_Error(
				'wpdebloat_empty_plan',
				__( 'There is nothing to apply: this plan is empty.', 'wp-debloat' ),
				array( 'status' => 409 )
			);
		}

		$confirm = (string) $request->get_param( 'confirm' );

		if ( ! ConfirmationToken::matchesPlan( $result->plan, $confirm ) ) {
			return new WP_Error(
				'wpdebloat_stale_confirmation',
				__(
					'This site has changed since that preview, so the plan is no longer the one you agreed to. Preview it again to see what is different.',
					'wp-debloat'
				),
				array( 'status' => 409 )
			);
		}

		// Recorded before the apply, so the statement is part of the history of
		// the change whatever the change then does. It buys nothing: the Level B
		// recovery point is taken either way, and a destructive operation with
		// no complete one is refused with this ticked exactly as without it
		// (docs/DECISIONS.md D-0027).
		$this->plugin->recordAttestation( (bool) $request->get_param( 'attestation' ) );

		$applied = $this->plugin->apply( $result->plan );

		return new WP_REST_Response(
			array(
				'result' => $applied->toArray(),
				'run_id' => $applied->run_id,
				'state'  => $applied->state->value,
				'ok'     => RunState::COMMITTED === $applied->state,
			),
			200
		);
	}
}
