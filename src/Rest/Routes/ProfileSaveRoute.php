<?php
/**
 * Saving what this site has chosen, under a name.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use Debloater\Config\ConfigDocument;
use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
use Debloater\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /profiles/save` — the current selection, named.
 *
 * What it saves is what the site has *committed*, read through
 * `ConfigDocument::fromSite()`, not whatever is ticked in the browser. Those
 * are different things: the screen may have unsaved ticks on it, and a profile
 * named "how this site is set up" that recorded a half-finished thought would
 * be worse than no profile at all.
 *
 * It changes nothing about the site. Saving a profile is bookkeeping.
 */
final class ProfileSaveRoute implements RouteInterface {

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The route path.
	 *
	 * @return string
	 */
	public function path(): string {
		return '/profiles/save';
	}

	/**
	 * The methods this route answers.
	 *
	 * @return string
	 */
	public function methods(): string {
		return 'POST';
	}

	/**
	 * Arguments.
	 *
	 * @return array<string,mixed>
	 */
	public function args(): array {
		return array(
			'name' => array(
				'description'       => __( 'What to call the profile.', 'debloater' ),
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'maxLength'         => Profile::MAX_NAME,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Save it.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$document = ConfigDocument::fromSite(
			$this->plugin->state(),
			$this->plugin->intentProfile(),
			$this->plugin->registry(),
			$this->plugin->context()
		);

		$store = new ProfileStore( $this->plugin->registry() );

		$id = $store->save(
			new Profile(
				(string) $request->get_param( 'name' ),
				$document->selection,
				$document->intent,
				$document->registry_hash
			)
		);

		$profile = $store->find( $id );

		if ( null === $profile ) {
			return new WP_Error(
				'debloater_profile_not_saved',
				__( 'The profile could not be saved.', 'debloater' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'       => $id,
				'name'     => $profile->name,
				'changes'  => $profile->count(),
				'document' => $profile->toJson(),
			),
			201
		);
	}
}
