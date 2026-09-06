<?php
/**
 * Where saved profiles live.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Config;

use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Json;
use Debloater\Recommend\IntentProfile;
use Debloater\Registry\Registry;

/**
 * Saved profiles, in one option (BUILD-SPEC §17 Phase 19c).
 *
 * ## One option, not autoloaded
 *
 * Fifty profiles of a few hundred bytes each is a payload nothing on a front-end
 * request needs, and autoloading it would put it in every page load of the site
 * this plugin exists to make lighter. It is read on the two admin screens that
 * ask for it and nowhere else.
 *
 * A row per profile in a table was the alternative. Fifty rows is not a table's
 * worth of data, and the plugin already has to explain four tables at uninstall.
 *
 * ## Built-ins are profiles too
 *
 * `safe`, `performance` and `maximum` come from the registry and are returned
 * alongside saved ones, marked read-only. A site that has saved nothing still
 * has profiles, which is what stops the panel that lists them from being an
 * empty box on a fresh install — the state most people see it in first.
 *
 * They cannot be renamed, edited or deleted. They can be exported and applied,
 * because those are questions about a selection and not about who owns it.
 */
final class ProfileStore {

	/**
	 * The option saved profiles live in.
	 */
	public const OPTION = 'debloater_profiles';

	/**
	 * How many a site may save.
	 *
	 * A limit rather than none, because this is one option read in one query
	 * and an unbounded list is an unbounded query. Fifty is far more than the
	 * agencies this is for have sites.
	 */
	public const MAX = 50;

	/**
	 * The registry, for built-ins and for validating selections.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Every profile this site has, built-in first.
	 *
	 * @return array<int,array{profile: Profile, id: string, builtin: bool}>
	 */
	public function all(): array {
		$profiles = array();

		foreach ( $this->builtins() as $id => $profile ) {
			$profiles[] = array(
				'profile' => $profile,
				'id'      => $id,
				'builtin' => true,
			);
		}

		foreach ( $this->saved() as $id => $profile ) {
			$profiles[] = array(
				'profile' => $profile,
				'id'      => $id,
				'builtin' => false,
			);
		}

		return $profiles;
	}

	/**
	 * The registry's own profiles, as read-only Profiles.
	 *
	 * Their selection is the tweaks the registry profile names outright. A
	 * profile that admits a whole risk band rather than listing changes has no
	 * fixed selection to export — what it produces depends on what a scan found
	 * — so it exports as what it names and the preview fills in the rest.
	 *
	 * @return array<string,Profile>
	 */
	public function builtins(): array {
		$profiles = array();

		foreach ( $this->registry->profiles() as $id => $definition ) {
			$selection = array();

			foreach ( $definition->tweaks as $tweak_id ) {
				$selection[ $tweak_id ] = $definition->params[ $tweak_id ] ?? array();
			}

			try {
				$profiles[ (string) $id ] = new Profile(
					$definition->title,
					$selection,
					new IntentProfile(),
					$this->registry->hash(),
					'1970-01-01T00:00:00Z'
				);
			} catch ( ContractViolation $error ) {
				// A registry profile with no title cannot become a named one.
				// Skipped rather than fatal: the registry is data, and one bad
				// document must not take the screen down with it.
				unset( $error );
			}
		}

		return $profiles;
	}

	/**
	 * The profiles this site has saved.
	 *
	 * @return array<string,Profile>
	 */
	public function saved(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$profiles = array();

		foreach ( $stored as $id => $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			try {
				$profiles[ (string) $id ] = Profile::fromArray( $document );
			} catch ( ContractViolation $error ) {
				// One unreadable row does not hide the rest. This is somebody's
				// list of saved work; losing all of it because one entry is
				// malformed would be the worst possible response to it.
				unset( $error );
			}
		}

		return $profiles;
	}

	/**
	 * One profile by id, built-in or saved.
	 *
	 * @param string $id Profile id.
	 * @return Profile|null
	 */
	public function find( string $id ): ?Profile {
		$saved = $this->saved();

		if ( isset( $saved[ $id ] ) ) {
			return $saved[ $id ];
		}

		return $this->builtins()[ $id ] ?? null;
	}

	/**
	 * Whether an id is one of the registry's, and so read-only.
	 *
	 * @param string $id Profile id.
	 * @return bool
	 */
	public function isBuiltin( string $id ): bool {
		return array_key_exists( $id, $this->builtins() );
	}

	/**
	 * Save a profile, returning the id it was stored under.
	 *
	 * @param Profile     $profile The profile.
	 * @param string|null $id      Id to overwrite, or null to add.
	 * @return string
	 * @throws ContractViolation When the site is full, or the id is a built-in.
	 */
	public function save( Profile $profile, ?string $id = null ): string {
		if ( null !== $id && $this->isBuiltin( $id ) ) {
			throw new ContractViolation(
				self::class,
				'id',
				__( 'The profiles Debloater ships with cannot be changed. Save a copy under your own name instead.', 'debloater' )
			);
		}

		$saved = $this->saved();

		if ( null === $id ) {
			$id = $this->newId( $profile->name, $saved );
		}

		if ( ! isset( $saved[ $id ] ) && count( $saved ) >= self::MAX ) {
			throw new ContractViolation(
				self::class,
				'',
				sprintf(
					/* translators: %d: the maximum number of profiles. */
					__( 'This site already has %d saved profiles, which is the most it keeps. Delete one first.', 'debloater' ),
					self::MAX
				)
			);
		}

		$saved[ $id ] = $profile;

		$this->write( $saved );

		return $id;
	}

	/**
	 * Delete a saved profile.
	 *
	 * @param string $id Profile id.
	 * @return bool Whether anything was deleted.
	 * @throws ContractViolation When the id is a built-in.
	 */
	public function delete( string $id ): bool {
		if ( $this->isBuiltin( $id ) ) {
			throw new ContractViolation(
				self::class,
				'id',
				__( 'The profiles Debloater ships with cannot be deleted.', 'debloater' )
			);
		}

		$saved = $this->saved();

		if ( ! isset( $saved[ $id ] ) ) {
			return false;
		}

		unset( $saved[ $id ] );

		$this->write( $saved );

		return true;
	}

	/**
	 * How many profiles this site has saved.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->saved() );
	}

	/**
	 * Write the list back.
	 *
	 * @param array<string,Profile> $profiles Profiles by id.
	 * @return void
	 */
	private function write( array $profiles ): void {
		$documents = array();

		foreach ( $profiles as $id => $profile ) {
			$documents[ $id ] = $profile->toArray();
		}

		// Not autoloaded. Read on two admin screens; never on a front-end
		// request, which is the whole point of this plugin.
		update_option( self::OPTION, $documents, false );
	}

	/**
	 * An id for a new profile, derived from its name.
	 *
	 * @param string                $name  The profile's name.
	 * @param array<string,Profile> $taken Ids already in use.
	 * @return string
	 */
	private function newId( string $name, array $taken ): string {
		$base = sanitize_key( $name );

		if ( '' === $base ) {
			$base = 'profile';
		}

		$base = substr( $base, 0, 40 );
		$id   = $base;
		$n    = 2;

		while ( isset( $taken[ $id ] ) || $this->isBuiltin( $id ) ) {
			$id = $base . '-' . $n;
			++$n;
		}

		return $id;
	}

	/**
	 * The bytes to write when exporting, for any surface that exports.
	 *
	 * @param Profile $profile The profile.
	 * @return string
	 */
	public static function export( Profile $profile ): string {
		return $profile->toJson();
	}

	/**
	 * Read a profile from a file's contents.
	 *
	 * Validation is the schema's and the registry's, in that order: a document
	 * that is not a profile is refused outright, and one that names changes
	 * this site does not have is accepted with those changes listed and left
	 * out. Neither path applies anything.
	 *
	 * @param string $json The file's contents.
	 * @return Profile
	 * @throws ContractViolation When the document cannot be read as a profile.
	 */
	public static function read( string $json ): Profile {
		$decoded = Json::decode( $json );

		if ( ! is_array( $decoded ) ) {
			throw new ContractViolation(
				Profile::class,
				'',
				__( 'That file is not a Debloater profile: it is not a JSON document.', 'debloater' )
			);
		}

		/** @var array<string,mixed> $decoded */
		return Profile::fromArray( $decoded );
	}
}
