<?php
/**
 * Loads registry documents from disk.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;

/**
 * Reads and validates the JSON documents that make up the registry.
 *
 * Every document is validated against its schema before it becomes an object.
 * A malformed registry is a hard failure, not a partial load: continuing with
 * "the tweaks that happened to parse" would mean the plan the user is shown
 * depends on which files were broken that day, and BUILD-SPEC's determinism
 * requirement (§2) would be quietly untrue.
 *
 * The loader never touches the network. Registry updates arrive through the
 * opt-in, signature-verified channel in Phase 17, never through this class.
 */
final class Loader {

	/**
	 * Absolute path of the registry directory.
	 *
	 * @var string
	 */
	private string $registry_dir;

	/**
	 * Cached schema validators, keyed by schema file name.
	 *
	 * @var array<string,SchemaValidator>
	 */
	private array $validators = array();

	/**
	 * Constructor.
	 *
	 * @param string $registry_dir Absolute path of the registry directory.
	 * @throws RuntimeException When the directory does not exist.
	 */
	public function __construct( string $registry_dir ) {
		$normalised = rtrim( str_replace( '\\', '/', $registry_dir ), '/' );

		if ( ! is_dir( $normalised ) ) {
			throw new RuntimeException( sprintf( 'Registry directory not found: %s', $registry_dir ) );
		}

		$this->registry_dir = $normalised;
	}

	/**
	 * Load the whole registry.
	 *
	 * @return Registry
	 * @throws RuntimeException When any document is malformed or duplicated.
	 */
	public function load(): Registry {
		return new Registry(
			$this->loadTweaks(),
			$this->loadDetectors(),
			$this->loadCompatibility(),
			$this->loadProfiles(),
			$this->loadPluginCategories(),
			$this->loadHostOptimizers(),
			$this->loadNoticeVendors()
		);
	}

	/**
	 * Load the admin-notice vendor allowlist.
	 *
	 * @return array<int,NoticeVendor>
	 * @throws RuntimeException When the document exists but is invalid.
	 */
	public function loadNoticeVendors(): array {
		$path = $this->file( 'admin-notices.json' );

		if ( ! is_file( $path ) ) {
			return array();
		}

		$document   = $this->decode( $path );
		$violations = $this->validator( 'admin-notices.schema.json' )->validate( $document );

		if ( array() !== $violations ) {
			throw new RuntimeException(
				sprintf(
					'registry/admin-notices.json is invalid: %s',
					implode( '; ', array_map( 'strval', $violations ) )
				)
			);
		}

		$vendors = array();
		$entries = $document['vendors'] ?? array();

		if ( ! is_array( $entries ) ) {
			return array();
		}

		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$vendors[] = NoticeVendor::fromArray( $entry );
			}
		}

		return $vendors;
	}

	/**
	 * Load the plugin category table.
	 *
	 * Unlike the four sets above, this is one file rather than one file per
	 * object, so there is no id-matches-filename check to make and no ordering
	 * to impose beyond what the value object does for itself.
	 *
	 * A registry without the file is valid and yields an empty table: the rules
	 * that read it simply have nothing to say, which is the correct behaviour
	 * for a lookup table nobody has authored yet.
	 *
	 * @return PluginCategories
	 * @throws RuntimeException When the document exists but is invalid.
	 */
	public function loadPluginCategories(): PluginCategories {
		$path = $this->file( 'plugin-categories.json' );

		if ( ! is_file( $path ) ) {
			return new PluginCategories();
		}

		$document   = $this->decode( $path );
		$violations = $this->validator( 'plugin-categories.schema.json' )->validate( $document );

		if ( array() !== $violations ) {
			throw new RuntimeException(
				sprintf(
					'registry/plugin-categories.json is invalid: %s',
					implode( '; ', array_map( 'strval', $violations ) )
				)
			);
		}

		return PluginCategories::fromArray( $document );
	}

	/**
	 * Load the host and stack optimizer table.
	 *
	 * @return array<int,HostOptimizer>
	 * @throws RuntimeException When the document exists but is invalid.
	 */
	public function loadHostOptimizers(): array {
		$path = $this->file( 'host-optimizers.json' );

		if ( ! is_file( $path ) ) {
			return array();
		}

		$document   = $this->decode( $path );
		$violations = $this->validator( 'host-optimizers.schema.json' )->validate( $document );

		if ( array() !== $violations ) {
			throw new RuntimeException(
				sprintf(
					'registry/host-optimizers.json is invalid: %s',
					implode( '; ', array_map( 'strval', $violations ) )
				)
			);
		}

		$optimizers = array();
		$entries    = $document['optimizers'] ?? array();

		if ( ! is_array( $entries ) ) {
			return array();
		}

		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$optimizers[] = HostOptimizer::fromArray( $entry );
			}
		}

		return $optimizers;
	}

	/**
	 * Load and validate every profile.
	 *
	 * @return array<int,Profile>
	 * @throws RuntimeException When a document is invalid or an id repeats.
	 */
	public function loadProfiles(): array {
		$validator = $this->validator( 'profile.schema.json' );
		$profiles  = array();
		$seen      = array();

		foreach ( $this->jsonFiles( 'profiles' ) as $path ) {
			$document = $this->decode( $path );

			$validator->assertValid( $document, $this->relative( $path ) );

			$profile = Profile::fromArray( $document );

			if ( array_key_exists( $profile->id, $seen ) ) {
				throw new RuntimeException(
					sprintf(
						'Duplicate profile id "%s" in %s and %s',
						$profile->id,
						$this->relative( $seen[ $profile->id ] ),
						$this->relative( $path )
					)
				);
			}

			$expected = $profile->id . '.json';

			if ( basename( $path ) !== $expected ) {
				throw new RuntimeException(
					sprintf(
						'Profile "%s" must be defined in %s, found in %s',
						$profile->id,
						$expected,
						$this->relative( $path )
					)
				);
			}

			$seen[ $profile->id ] = $path;
			$profiles[]           = $profile;
		}

		return $profiles;
	}

	/**
	 * Load and validate every compatibility rule.
	 *
	 * Unlike tweaks and detectors, the file name is the subject *slug* rather
	 * than the whole subject: a rule about "plugin:contact-form-7" lives in
	 * contact-form-7.json, because the type prefix would be noise in a directory
	 * that only ever holds one kind of thing per slug.
	 *
	 * @return array<int,CompatRule>
	 * @throws RuntimeException When a document is invalid or a subject repeats.
	 */
	public function loadCompatibility(): array {
		$validator = $this->validator( 'compat.schema.json' );
		$rules     = array();
		$seen      = array();

		foreach ( $this->jsonFiles( 'compatibility' ) as $path ) {
			$document = $this->decode( $path );

			$validator->assertValid( $document, $this->relative( $path ) );

			$rule = CompatRule::fromArray( $document );

			if ( array_key_exists( $rule->subject, $seen ) ) {
				throw new RuntimeException(
					sprintf(
						'Duplicate compatibility subject "%s" in %s and %s',
						$rule->subject,
						$this->relative( $seen[ $rule->subject ] ),
						$this->relative( $path )
					)
				);
			}

			$expected = $rule->subjectSlug() . '.json';

			if ( basename( $path ) !== $expected ) {
				throw new RuntimeException(
					sprintf(
						'Compatibility rule for "%s" must be defined in %s, found in %s',
						$rule->subject,
						$expected,
						$this->relative( $path )
					)
				);
			}

			$seen[ $rule->subject ] = $path;
			$rules[]                = $rule;
		}

		return $rules;
	}

	/**
	 * Load and validate every detector.
	 *
	 * @return array<int,Detector>
	 * @throws RuntimeException When a document is invalid or an id is duplicated.
	 */
	public function loadDetectors(): array {
		$validator = $this->validator( 'detector.schema.json' );
		$detectors = array();
		$seen      = array();

		foreach ( $this->jsonFiles( 'detectors' ) as $path ) {
			$document = $this->decode( $path );

			$validator->assertValid( $document, $this->relative( $path ) );

			$detector = Detector::fromArray( $document );

			if ( array_key_exists( $detector->id, $seen ) ) {
				throw new RuntimeException(
					sprintf(
						'Duplicate detector id "%s" in %s and %s',
						$detector->id,
						$this->relative( $seen[ $detector->id ] ),
						$this->relative( $path )
					)
				);
			}

			// The file name is part of the contract here too: it is how a
			// reviewer finds the detector for a slug.
			$expected = $detector->id . '.json';

			if ( basename( $path ) !== $expected ) {
				throw new RuntimeException(
					sprintf(
						'Detector "%s" must be defined in %s, found in %s',
						$detector->id,
						$expected,
						$this->relative( $path )
					)
				);
			}

			$seen[ $detector->id ] = $path;
			$detectors[]           = $detector;
		}

		return $detectors;
	}

	/**
	 * Load and validate every tweak definition.
	 *
	 * @return array<int,TweakDefinition>
	 * @throws RuntimeException When a document is invalid or an id is duplicated.
	 */
	public function loadTweaks(): array {
		$validator   = $this->validator( 'tweak.schema.json' );
		$definitions = array();
		$seen        = array();

		foreach ( $this->jsonFiles( 'tweaks' ) as $path ) {
			$document = $this->decode( $path );

			$validator->assertValid( $document, $this->relative( $path ) );

			$definition = TweakDefinition::fromArray( $document );

			if ( array_key_exists( $definition->id, $seen ) ) {
				throw new RuntimeException(
					sprintf(
						'Duplicate tweak id "%s" in %s and %s',
						$definition->id,
						$this->relative( $seen[ $definition->id ] ),
						$this->relative( $path )
					)
				);
			}

			// The file name is part of the contract: it is how a reviewer finds
			// the definition for a tweak id without grepping.
			$expected = $definition->id . '.json';

			if ( basename( $path ) !== $expected ) {
				throw new RuntimeException(
					sprintf(
						'Tweak "%s" must be defined in %s, found in %s',
						$definition->id,
						$expected,
						$this->relative( $path )
					)
				);
			}

			$seen[ $definition->id ] = $path;
			$definitions[]           = $definition;
		}

		return $definitions;
	}

	/**
	 * The absolute path of a registry subdirectory.
	 *
	 * @param string $subdirectory Subdirectory name.
	 * @return string
	 */
	public function directory( string $subdirectory ): string {
		return $this->registry_dir . '/' . $subdirectory;
	}

	/**
	 * The absolute path of a registry file that is not inside a subdirectory.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public function file( string $name ): string {
		return $this->registry_dir . '/' . $name;
	}

	/**
	 * A schema validator for one of the shipped schemas.
	 *
	 * @param string $schema Schema file name.
	 * @return SchemaValidator
	 * @throws RuntimeException When the schema cannot be read.
	 */
	public function validator( string $schema ): SchemaValidator {
		if ( ! array_key_exists( $schema, $this->validators ) ) {
			$this->validators[ $schema ] = SchemaValidator::fromFile(
				$this->registry_dir . '/schemas/' . $schema
			);
		}

		return $this->validators[ $schema ];
	}

	/**
	 * JSON files in a registry subdirectory, in deterministic name order.
	 *
	 * @param string $subdirectory Subdirectory name.
	 * @return array<int,string>
	 */
	public function jsonFiles( string $subdirectory ): array {
		$directory = $this->directory( $subdirectory );

		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$paths = glob( $directory . '/*.json' );

		if ( false === $paths ) {
			return array();
		}

		sort( $paths, SORT_STRING );

		return $paths;
	}

	/**
	 * Decode a registry document.
	 *
	 * @param string $path Absolute file path.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the file cannot be read or parsed.
	 */
	public function decode( string $path ): array {
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			throw new RuntimeException( sprintf( 'Could not read registry file: %s', $this->relative( $path ) ) );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException(
				sprintf( '%s is not valid JSON: %s', $this->relative( $path ), json_last_error_msg() )
			);
		}

		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/**
	 * A path relative to the registry directory, for error messages.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function relative( string $path ): string {
		$normalised = str_replace( '\\', '/', $path );

		if ( str_starts_with( $normalised, $this->registry_dir . '/' ) ) {
			return 'registry/' . substr( $normalised, strlen( $this->registry_dir ) + 1 );
		}

		return $normalised;
	}
}
