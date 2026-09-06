<?php
/**
 * A named selection, portable between sites.
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
 * A profile: a name, a selection, and the intent the two were decided with
 * (BUILD-SPEC §7.3, §17 Phase 19c).
 *
 * ## What it is, and what it deliberately is not
 *
 * It is a `ConfigDocument` with a name and a creation date. The document
 * already knew how to say what a site had chosen, which changes in it are
 * unknown here, and whether it was written against this registry — so a profile
 * reuses all of that rather than growing a second, slightly different opinion
 * about the same questions.
 *
 * It is **not** a record of a site. `site_hash` and `plugin_version` are in the
 * configuration document and not in a profile: a profile is meant to travel,
 * and one that carried the fingerprint of the site it came from invites
 * somebody to treat it as belonging there. There is nothing in a profile that
 * identifies where it was made.
 *
 * ## Importing never applies
 *
 * A profile pre-fills a preview. That is the whole of what importing one does.
 * The plan it produces goes through the same confirmation token, the same
 * snapshot, the same verification and the same rollback as any other plan
 * (§13 rule 8), because a file somebody was emailed is exactly the input that
 * must not be able to change a site on its own.
 */
final class Profile {

	/**
	 * The format version this class reads and writes.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * The longest a name may be, matching the schema.
	 */
	public const MAX_NAME = 80;

	/**
	 * What a person calls it.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * When it was made, ISO 8601 UTC.
	 *
	 * @var string
	 */
	public readonly string $created_at;

	/**
	 * Selected tweak ids, each with its parameters.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public readonly array $selection;

	/**
	 * What the site is for, and how much change its owner wants.
	 *
	 * @var IntentProfile
	 */
	public readonly IntentProfile $intent;

	/**
	 * The registry this was written against, or '' when not recorded.
	 *
	 * @var string
	 */
	public readonly string $registry_hash;

	/**
	 * Constructor.
	 *
	 * @param string                            $name          What a person calls it.
	 * @param array<string,array<string,mixed>> $selection     Selected tweak ids and parameters.
	 * @param IntentProfile                     $intent        Intent the selection was made with.
	 * @param string                            $registry_hash Registry hash, or ''.
	 * @param string                            $created_at    ISO 8601 UTC, or '' for now.
	 * @throws ContractViolation When the name is empty or too long.
	 */
	public function __construct(
		string $name,
		array $selection,
		IntentProfile $intent,
		string $registry_hash = '',
		string $created_at = ''
	) {
		$name = trim( $name );

		if ( '' === $name ) {
			throw new ContractViolation( self::class, 'name', 'A profile needs a name.' );
		}

		if ( mb_strlen( $name ) > self::MAX_NAME ) {
			throw new ContractViolation(
				self::class,
				'name',
				sprintf( 'A profile name may be %d characters at most.', self::MAX_NAME )
			);
		}

		$this->name          = $name;
		$this->selection     = $selection;
		$this->intent        = $intent;
		$this->registry_hash = $registry_hash;
		$this->created_at    = '' === $created_at ? gmdate( 'Y-m-d\TH:i:s\Z' ) : $created_at;
	}

	/**
	 * A profile from a decoded document.
	 *
	 * @param array<string,mixed> $data Decoded JSON.
	 * @return self
	 * @throws ContractViolation When the document is not a profile.
	 */
	public static function fromArray( array $data ): self {
		$version = $data['schema_version'] ?? null;

		if ( self::SCHEMA_VERSION !== $version ) {
			throw new ContractViolation(
				self::class,
				'schema_version',
				sprintf(
					'This profile is version %s and this Debloater reads version %d.',
					is_scalar( $version ) ? (string) $version : 'unknown',
					self::SCHEMA_VERSION
				)
			);
		}

		$selection = array();

		foreach ( (array) ( $data['selection'] ?? array() ) as $id => $params ) {
			$selection[ (string) $id ] = is_array( $params ) ? $params : array();
		}

		return new self(
			(string) ( $data['name'] ?? '' ),
			$selection,
			IntentProfile::fromArray( (array) ( $data['intent_profile'] ?? array() ) ),
			(string) ( $data['registry_hash'] ?? '' ),
			(string) ( $data['created_at'] ?? '' )
		);
	}

	/**
	 * The document, in the order the schema describes it.
	 *
	 * Key order is fixed here rather than left to whoever built the array,
	 * because the CLI and the admin screen both export profiles and the two
	 * files must be identical byte for byte. A user who exports the same
	 * profile twice, once from each, and finds two different files has been
	 * given a reason to wonder which one is right.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'name'           => $this->name,
			'created_at'     => $this->created_at,
			'registry_hash'  => $this->registry_hash,
			'intent_profile' => $this->intent->toArray(),
			// Each entry cast to an object too, not just the map. A tweak
			// selected with no parameters holds an empty array, and an empty
			// PHP array encodes as `[]` — which the schema rejects, because it
			// asks for an object there. The map alone being cast was enough to
			// produce a document this plugin writes and refuses to read.
			'selection'      => (object) array_map(
				static fn ( array $params ): object => (object) $params,
				$this->selection
			),
		);
	}

	/**
	 * The bytes an export writes.
	 *
	 * One encoder, used by every surface that exports, for the reason given on
	 * `toArray()`. Pretty-printed because a profile is a file people read,
	 * diff and commit; slashes unescaped because `\/` in a JSON file is noise.
	 *
	 * @return string
	 */
	public function toJson(): string {
		// Contracts\Json, not wp_json_encode: a profile is built and compared in
		// the unit suite, which runs with no WordPress loaded, and an encoder
		// that exists on only one of the two paths cannot be used to prove the
		// two paths agree.
		return Json::encode(
			$this->toArray(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";
	}

	/**
	 * The tweak ids this profile names that this site does not have.
	 *
	 * Listed rather than counted: "three changes were skipped" tells somebody
	 * that something is wrong and nothing about what, and the names are what
	 * they need to decide whether it matters.
	 *
	 * @param Registry $registry The registry to check against.
	 * @return array<int,string> Unknown tweak ids, sorted.
	 */
	public function unknownTweaks( Registry $registry ): array {
		$unknown = array();

		foreach ( array_keys( $this->selection ) as $id ) {
			if ( ! $registry->has( $id ) ) {
				$unknown[] = $id;
			}
		}

		sort( $unknown );

		return $unknown;
	}

	/**
	 * The same profile with anything this site does not know about removed.
	 *
	 * @param Registry $registry The registry to check against.
	 * @return self
	 */
	public function withoutUnknownTweaks( Registry $registry ): self {
		$known = array();

		foreach ( $this->selection as $id => $params ) {
			if ( $registry->has( $id ) ) {
				$known[ $id ] = $params;
			}
		}

		return new self( $this->name, $known, $this->intent, $this->registry_hash, $this->created_at );
	}

	/**
	 * Whether this profile was written against the registry this site has.
	 *
	 * A mismatch is a warning and never a refusal. A change may mean something
	 * slightly different than it did when the profile was saved, which is worth
	 * saying; it is not a reason to refuse a file somebody asked to import, and
	 * the preview that follows shows exactly what would happen anyway.
	 *
	 * @param Registry $registry The registry to check against.
	 * @return bool
	 */
	public function matchesRegistry( Registry $registry ): bool {
		return '' === $this->registry_hash || $this->registry_hash === $registry->hash();
	}

	/**
	 * A copy under a different name.
	 *
	 * @param string $name The new name.
	 * @return self
	 * @throws ContractViolation When the name is empty or too long.
	 */
	public function renamed( string $name ): self {
		return new self( $name, $this->selection, $this->intent, $this->registry_hash, $this->created_at );
	}

	/**
	 * How many changes it selects.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->selection );
	}
}
