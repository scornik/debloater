<?php
/**
 * Reading a profile somebody brought with them.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest\Routes;

use Debloater\Config\ProfileStore;
use Debloater\Contracts\ContractViolation;
use Debloater\Plugin;
use Debloater\Registry\SchemaValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /profiles/import` — validate a file, save it, and hand back a selection.
 *
 * **It applies nothing** (BUILD-SPEC §13 rule 8, docs/DECISIONS.md D-0063). It
 * returns the tweak ids the profile names so the screen can open the ordinary
 * preview with them ticked; the preview issues its own confirmation token and
 * the ordinary apply does the applying. A file that arrived by email must not
 * be able to change a site by being uploaded, and the only reason that is true
 * is that this route has no code that could.
 *
 * Three things are checked, in this order, because each is worth a different
 * answer:
 *
 * 1. **Is it a profile at all?** Validated against
 *    `schemas/profile-export.schema.json`. If not, refused — there is nothing
 *    useful to do with a document nobody can read.
 * 2. **Does it name changes this site does not have?** Those are listed by name
 *    and left out. Refusing the whole file over one unknown change would make
 *    a profile useless the first time it crossed a version boundary.
 * 3. **Was it written against this registry?** A mismatch is a warning, never a
 *    gate. A change may mean something slightly different now, which is worth
 *    saying; the preview that follows shows exactly what it would do here.
 */
final class ProfileImportRoute implements RouteInterface {

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
		return '/profiles/import';
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
			'document' => array(
				'description' => __( 'The contents of the profile file.', 'debloater' ),
				'type'        => 'string',
				'required'    => true,
				'minLength'   => 2,

				// Generous, and bounded. A profile is a few kilobytes; a
				// megabyte of it is not a profile, and finding that out after
				// parsing costs more than finding it out here.
				'maxLength'   => 1048576,
			),
		);
	}

	/**
	 * Read it.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$raw = (string) $request->get_param( 'document' );

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'debloater_profile_unreadable',
				__( 'That file is not a Debloater profile: it is not a JSON document.', 'debloater' ),
				array( 'status' => 400 )
			);
		}

		$errors = $this->schema()->validate( $decoded );

		if ( array() !== $errors ) {
			// The validator returns violations, not strings: each one knows
			// where in the document it happened as well as what was wrong, and
			// a person looking at a file that will not import wants the where.
			$problems = array_map(
				static fn ( $violation ): string => esc_html( (string) $violation ),
				array_values( $errors )
			);

			return new WP_Error(
				'debloater_profile_invalid',
				sprintf(
					/* translators: %s: the first thing wrong with the document. */
					__( 'That file is not a Debloater profile: %s', 'debloater' ),
					$problems[0]
				),
				array(
					'status'   => 400,
					'problems' => $problems,
				)
			);
		}

		try {
			$profile = ProfileStore::read( $raw );
		} catch ( ContractViolation $error ) {
			return new WP_Error(
				'debloater_profile_invalid',
				esc_html( $error->getMessage() ),
				array( 'status' => 400 )
			);
		}

		$registry = $this->plugin->registry();
		$skipped  = $profile->unknownTweaks( $registry );
		$profile  = $profile->withoutUnknownTweaks( $registry );

		$store = new ProfileStore( $registry );
		$id    = $store->save( $profile );

		return new WP_REST_Response(
			array(
				'id'             => $id,
				'name'           => $profile->name,
				'selection'      => array_keys( $profile->selection ),
				'skipped'        => $skipped,
				'registry_match' => $profile->matchesRegistry( $registry ),

				// Said in the payload as well as in the code, because this is
				// the promise the screen repeats to the person using it.
				'applied'        => false,
				'document'       => $profile->toJson(),
			),
			200
		);
	}

	/**
	 * The profile schema.
	 *
	 * @return SchemaValidator
	 */
	private function schema(): SchemaValidator {
		$path = dirname( $this->plugin->context()->plugin_dir ) . '/debloater/schemas/profile-export.schema.json';

		if ( ! is_file( $path ) ) {
			$path = $this->plugin->context()->plugin_dir . '/schemas/profile-export.schema.json';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading a schema that ships inside this plugin.
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		return new SchemaValidator( is_array( $decoded ) ? $decoded : array() );
	}
}
