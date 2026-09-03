<?php
/**
 * Installs the runtime loader and reports how the runtime got loaded.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply;

use Debloater\Contracts\Context;

/**
 * Puts the mu-plugin loader in place, with a documented fallback
 * (BUILD-SPEC §10, docs/DECISIONS.md D-0007).
 *
 * The runtime wants to load before other plugins, which means mu-plugins. That
 * directory is not writable on every host, so there is a second path: the main
 * plugin includes the runtime itself at `plugins_loaded` priority -999. It works,
 * it is honest about being second best, and the status endpoint reports which
 * one is in use so a warning can be shown rather than a silent difference in
 * behaviour.
 */
final class RuntimeLoader {

	/**
	 * The loader is installed in mu-plugins and runs before plugins load.
	 */
	public const MODE_MU_PLUGIN = 'mu-plugin';

	/**
	 * The loader could not be installed; the plugin includes the runtime itself.
	 */
	public const MODE_FALLBACK = 'fallback';

	/**
	 * No runtime is loaded, because nothing is selected.
	 */
	public const MODE_NONE = 'none';

	/**
	 * File name of the installed loader.
	 */
	public const LOADER_FILE = 'debloater-loader.php';

	/**
	 * Priority the fallback include runs at.
	 *
	 * Early enough to beat every ordinary plugin, late enough that WordPress
	 * itself is fully set up.
	 */
	public const FALLBACK_PRIORITY = -999;

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Constructor.
	 *
	 * @param Context $context Site context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Install the mu-plugin loader, reporting which mode resulted.
	 *
	 * Called on activation and whenever the runtime is rewritten, so a loader
	 * deleted by a migration or a careless cleanup is quietly restored.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public function install(): string {
		$source = $this->context->plugin_dir . '/mu-loader/' . self::LOADER_FILE;

		if ( ! is_readable( $source ) ) {
			return self::MODE_FALLBACK;
		}

		$directory = $this->context->muPluginsDir();

		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return self::MODE_FALLBACK;
		}

		if ( ! is_writable( $directory ) ) {
			return self::MODE_FALLBACK;
		}

		$target   = $directory . '/' . self::LOADER_FILE;
		$contents = file_get_contents( $source );

		if ( false === $contents ) {
			return self::MODE_FALLBACK;
		}

		if ( $this->isCurrent( $target, $contents ) ) {
			return self::MODE_MU_PLUGIN;
		}

		$temporary = $target . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		if ( false === @file_put_contents( $temporary, $contents, LOCK_EX ) ) {
			return self::MODE_FALLBACK;
		}

		@chmod( $temporary, 0644 );

		if ( ! @rename( $temporary, $target ) ) {
			@unlink( $temporary );

			return self::MODE_FALLBACK;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $target, true );
		}

		return self::MODE_MU_PLUGIN;
	}

	/**
	 * Remove the installed loader.
	 *
	 * BUILD-SPEC §13 rule 10: uninstall always removes the runtime and the
	 * loader, whatever the user chose about dropping data.
	 *
	 * @return bool Whether nothing of ours is left in mu-plugins.
	 */
	public function uninstall(): bool {
		$target = $this->context->muPluginsDir() . '/' . self::LOADER_FILE;

		if ( ! file_exists( $target ) ) {
			return true;
		}

		return (bool) @unlink( $target );
	}

	/**
	 * Whether the loader is installed in mu-plugins.
	 *
	 * @return bool
	 */
	public function isInstalled(): bool {
		return is_readable( $this->context->muPluginsDir() . '/' . self::LOADER_FILE );
	}

	/**
	 * Whether the installed loader matches the one shipped with this version.
	 *
	 * @return bool
	 */
	public function isUpToDate(): bool {
		$source = $this->context->plugin_dir . '/mu-loader/' . self::LOADER_FILE;
		$target = $this->context->muPluginsDir() . '/' . self::LOADER_FILE;

		if ( ! is_readable( $source ) || ! is_readable( $target ) ) {
			return false;
		}

		$expected = file_get_contents( $source );

		return false !== $expected && $this->isCurrent( $target, $expected );
	}

	/**
	 * How the runtime is being loaded on this site right now.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public function mode(): string {
		if ( ! is_readable( $this->context->runtimeFile() ) ) {
			return self::MODE_NONE;
		}

		if ( defined( 'DEBLOATER_LOADER_MODE' ) ) {
			// The loader itself defines this, so it is the most direct evidence of
			// how the runtime actually got loaded on this request.
			return (string) DEBLOATER_LOADER_MODE;
		}

		return $this->isInstalled() ? self::MODE_MU_PLUGIN : self::MODE_FALLBACK;
	}

	/**
	 * Load the runtime from the main plugin, for the fallback path.
	 *
	 * Repeats the loader's hash check rather than trusting the file, so the
	 * fallback is exactly as strict as the mu-plugin it stands in for.
	 *
	 * @return bool Whether the runtime was loaded.
	 */
	public function loadFallback(): bool {
		if ( defined( 'DEBLOATER_LOADER_MODE' ) ) {
			// The mu-plugin already ran. Loading again would double-register.
			return false;
		}

		$runtime = $this->context->runtimeFile();

		if ( ! is_readable( $runtime ) ) {
			return false;
		}

		$writer   = new RuntimeWriter( $this->context );
		$recorded = $writer->recordedHash();

		if ( '' === $recorded || ! hash_equals( $recorded, $writer->actualHash() ) ) {
			return false;
		}

		if ( ! defined( 'DEBLOATER_LOADER_MODE' ) ) {
			define( 'DEBLOATER_LOADER_MODE', self::MODE_FALLBACK );
		}

		require_once $runtime;

		return true;
	}

	/**
	 * Finish a bypass request the runtime guard had to defer.
	 *
	 * The guard runs from mu-plugins, before WordPress can say who is asking, so
	 * it records the request and returns false. By `plugins_loaded` the answer is
	 * knowable: if the request really is from an administrator and carries a
	 * valid nonce, every handler the runtime registered is unregistered again.
	 *
	 * @param array<int,string> $handler_classes Handler classes the runtime registered.
	 * @return bool Whether the bypass was honoured.
	 */
	public function resolveDeferredBypass( array $handler_classes ): bool {
		if ( ! class_exists( 'Debloater_Runtime_Guard', false ) ) {
			return false;
		}

		if ( ! \Debloater_Runtime_Guard::bypass_deferred() || ! \Debloater_Runtime_Guard::authorised() ) {
			return false;
		}

		foreach ( $handler_classes as $class ) {
			if ( class_exists( $class, false ) && method_exists( $class, 'unregister' ) ) {
				$class::unregister();
			}
		}

		return true;
	}

	/**
	 * Whether a file already holds exactly these contents.
	 *
	 * @param string $path     File path.
	 * @param string $contents Expected contents.
	 * @return bool
	 */
	private function isCurrent( string $path, string $contents ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$existing = file_get_contents( $path );

		return false !== $existing && hash_equals( hash( 'sha256', $contents ), hash( 'sha256', $existing ) );
	}
}
