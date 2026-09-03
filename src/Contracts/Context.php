<?php
/**
 * The site context a run executes against.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Current site context (BUILD-SPEC §4, locked decision #8).
 *
 * Everything that needs to know "which site, which paths, which actor" takes a
 * Context rather than reaching for WordPress globals. That is what keeps the
 * scanner, analyzer, engine and compiler unit-testable without WordPress
 * loaded, and it is where the single-site-first decision is expressed: there is
 * no site_id and no network option anywhere in the contract.
 */
final class Context {

	/**
	 * Site home URL, without a trailing slash.
	 *
	 * @var string
	 */
	public readonly string $home_url;

	/**
	 * WordPress root directory, with a trailing slash.
	 *
	 * @var string
	 */
	public readonly string $abspath;

	/**
	 * wp-content directory, without a trailing slash.
	 *
	 * @var string
	 */
	public readonly string $content_dir;

	/**
	 * The plugin's own directory, without a trailing slash.
	 *
	 * @var string
	 */
	public readonly string $plugin_dir;

	/**
	 * WordPress version.
	 *
	 * @var string
	 */
	public readonly string $wp_version;

	/**
	 * PHP version.
	 *
	 * @var string
	 */
	public readonly string $php_version;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public readonly string $plugin_version;

	/**
	 * Who is acting: "user:123", "cli", "cron" or "system".
	 *
	 * @var string
	 */
	public readonly string $actor;

	/**
	 * Whether this is a multisite install. Multisite is not supported in v1;
	 * the flag exists so the plugin can decline rather than misbehave.
	 *
	 * @var bool
	 */
	public readonly bool $is_multisite;

	/**
	 * Constructor.
	 *
	 * @param string $home_url       Site home URL.
	 * @param string $abspath        WordPress root directory.
	 * @param string $content_dir    wp-content directory.
	 * @param string $plugin_dir     Plugin directory.
	 * @param string $wp_version     WordPress version.
	 * @param string $php_version    PHP version.
	 * @param string $plugin_version Plugin version.
	 * @param string $actor          Acting principal.
	 * @param bool   $is_multisite   Whether this is multisite.
	 * @throws ContractViolation When a field is empty or malformed.
	 */
	public function __construct(
		string $home_url,
		string $abspath,
		string $content_dir,
		string $plugin_dir,
		string $wp_version,
		string $php_version,
		string $plugin_version,
		string $actor,
		bool $is_multisite = false
	) {
		if ( 1 !== preg_match( '#^https?://[^\s/]+#i', $home_url ) ) {
			throw ContractViolation::range(
				self::class,
				'home_url',
				sprintf( 'must be an http(s) URL, got "%s"', $home_url )
			);
		}

		$paths = array(
			'abspath'     => $abspath,
			'content_dir' => $content_dir,
			'plugin_dir'  => $plugin_dir,
		);

		foreach ( $paths as $field => $path ) {
			if ( '' === trim( $path ) ) {
				throw ContractViolation::range( self::class, $field, 'must not be empty' );
			}
		}

		$versions = array(
			'wp_version'     => $wp_version,
			'php_version'    => $php_version,
			'plugin_version' => $plugin_version,
		);

		foreach ( $versions as $field => $version ) {
			if ( 1 !== preg_match( '/^\d+(\.\d+)*([\-+].+)?$/', $version ) ) {
				throw ContractViolation::range(
					self::class,
					$field,
					sprintf( 'must be a dotted version string, got "%s"', $version )
				);
			}
		}

		if ( 1 !== preg_match( Identifier::ACTOR_PATTERN, $actor ) ) {
			throw ContractViolation::range(
				self::class,
				'actor',
				sprintf( 'must be "cli", "cron", "system" or "user:<id>", got "%s"', $actor )
			);
		}

		$this->home_url       = rtrim( $home_url, '/' );
		$this->abspath        = rtrim( str_replace( '\\', '/', $abspath ), '/' ) . '/';
		$this->content_dir    = rtrim( str_replace( '\\', '/', $content_dir ), '/' );
		$this->plugin_dir     = rtrim( str_replace( '\\', '/', $plugin_dir ), '/' );
		$this->wp_version     = $wp_version;
		$this->php_version    = $php_version;
		$this->plugin_version = $plugin_version;
		$this->actor          = $actor;
		$this->is_multisite   = $is_multisite;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys(
			self::class,
			$data,
			array(
				'home_url',
				'abspath',
				'content_dir',
				'plugin_dir',
				'wp_version',
				'php_version',
				'plugin_version',
				'actor',
				'is_multisite',
			)
		);

		return new self(
			Assert::string( self::class, $data, 'home_url' ),
			Assert::string( self::class, $data, 'abspath' ),
			Assert::string( self::class, $data, 'content_dir' ),
			Assert::string( self::class, $data, 'plugin_dir' ),
			Assert::string( self::class, $data, 'wp_version' ),
			Assert::string( self::class, $data, 'php_version' ),
			Assert::string( self::class, $data, 'plugin_version' ),
			Assert::string( self::class, $data, 'actor' ),
			Assert::boolOr( self::class, $data, 'is_multisite', false )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'home_url'       => $this->home_url,
			'abspath'        => $this->abspath,
			'content_dir'    => $this->content_dir,
			'plugin_dir'     => $this->plugin_dir,
			'wp_version'     => $this->wp_version,
			'php_version'    => $this->php_version,
			'plugin_version' => $this->plugin_version,
			'actor'          => $this->actor,
			'is_multisite'   => $this->is_multisite,
		);
	}

	/**
	 * The directory generated artefacts are written to.
	 *
	 * Everything Debloater writes lives under this one directory
	 * (BUILD-SPEC §13 rule 6), which is what makes the filesystem boundary
	 * testable.
	 *
	 * @return string
	 */
	public function runtimeDir(): string {
		return $this->content_dir . '/debloater';
	}

	/**
	 * The generated runtime file path.
	 *
	 * @return string
	 */
	public function runtimeFile(): string {
		return $this->runtimeDir() . '/runtime.php';
	}

	/**
	 * The runtime lock file path, holding the runtime hash.
	 *
	 * @return string
	 */
	public function runtimeLockFile(): string {
		return $this->runtimeDir() . '/runtime.lock';
	}

	/**
	 * The Level B spill directory.
	 *
	 * @return string
	 */
	public function backupsDir(): string {
		return $this->runtimeDir() . '/backups';
	}

	/**
	 * The mu-plugins directory the loader is installed into.
	 *
	 * @return string
	 */
	public function muPluginsDir(): string {
		return $this->content_dir . '/mu-plugins';
	}

	/**
	 * The directory runtime handlers are loaded from.
	 *
	 * @return string
	 */
	public function handlersDir(): string {
		return $this->plugin_dir . '/runtime-handlers';
	}

	/**
	 * Stable identity of this site, used to refuse cross-site restores.
	 *
	 * @return string
	 */
	public function siteHash(): string {
		return hash( 'sha256', $this->home_url . '|' . $this->abspath );
	}

	/**
	 * The acting user's id, or null when the actor is not a user.
	 *
	 * @return int|null
	 */
	public function actorUserId(): ?int {
		if ( 1 !== preg_match( '/^user:([1-9][0-9]*)$/', $this->actor, $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}

	/**
	 * A copy acting as a different principal.
	 *
	 * @param string $actor New actor.
	 * @return self
	 * @throws ContractViolation When the actor is malformed.
	 */
	public function withActor( string $actor ): self {
		return new self(
			$this->home_url,
			$this->abspath,
			$this->content_dir,
			$this->plugin_dir,
			$this->wp_version,
			$this->php_version,
			$this->plugin_version,
			$actor,
			$this->is_multisite
		);
	}
}
