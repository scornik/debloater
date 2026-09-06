<?php
/**
 * The profiles this site has.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use Debloater\Config\ProfileStore;
use Debloater\Plugin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /profiles` — built-in and saved, with the bytes each one exports as.
 *
 * The `document` field is the profile's exported file **as a string**, not as a
 * nested object. That is deliberate and it is what makes the download
 * byte-identical to `wp debloater profile export`: the browser saves exactly
 * what the server encoded. Sending an object and re-encoding it in JavaScript
 * would produce a file that differs in whitespace, key order and escaping from
 * the one the command line writes, and the two would drift apart the first time
 * either side changed.
 */
final class ProfilesRoute implements RouteInterface {

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
		return '/profiles';
	}

	/**
	 * The methods this route answers.
	 *
	 * @return string
	 */
	public function methods(): string {
		return 'GET';
	}

	/**
	 * Arguments.
	 *
	 * @return array<string,mixed>
	 */
	public function args(): array {
		return array();
	}

	/**
	 * List them.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		unset( $request );

		$store    = new ProfileStore( $this->plugin->registry() );
		$profiles = array();

		foreach ( $store->all() as $entry ) {
			$profile = $entry['profile'];

			$profiles[] = array(
				'id'        => $entry['id'],
				'name'      => $profile->name,
				'builtin'   => $entry['builtin'],
				'changes'   => $profile->count(),
				'selection' => array_keys( $profile->selection ),
				'document'  => $profile->toJson(),
			);
		}

		return new WP_REST_Response(
			array(
				'profiles' => $profiles,
				'saved'    => $store->count(),
				'max'      => ProfileStore::MAX,
			),
			200
		);
	}
}
