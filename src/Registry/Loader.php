<?php
/**
 * Loads registry documents from disk.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Registry;

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
		return new Registry( $this->loadTweaks() );
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
