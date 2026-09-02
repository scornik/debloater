<?php
/**
 * Plugin boot sequence and service locator.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat;

use WPDebloat\Apply\Compiler;
use WPDebloat\Apply\RuntimeLoader;
use WPDebloat\Apply\RuntimeWriter;
use WPDebloat\Contracts\Context;
use WPDebloat\Registry\Loader;
use WPDebloat\Registry\Registry;
use WPDebloat\Rest\Controller;
use WPDebloat\Rest\Routes\StatusRoute;
use WPDebloat\Security\Capabilities;
use WPDebloat\Storage\State;

/**
 * Wires the plugin together (BUILD-SPEC §4).
 *
 * A deliberately plain service locator, not a dependency-injection container:
 * there are a dozen services, they are all constructed the same way, and a
 * container would be a runtime dependency and an indirection for no gain.
 * Services are built lazily, so a front-end request that only needs the loader
 * never constructs the registry.
 *
 * Nothing here runs on a front-end request except the fallback loader hook, and
 * that hook is only added when the mu-plugin loader is not in place.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path of the plugin's main file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Lazily built services.
	 *
	 * @var array<string,mixed>
	 */
	private array $services = array();

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 * @param string $version     Plugin version.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Boot the plugin.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 * @param string $version     Plugin version.
	 * @return self
	 */
	public static function boot( string $plugin_file, string $version ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file, $version );
			self::$instance->register();
		}

		return self::$instance;
	}

	/**
	 * The booted instance, if there is one.
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		Capabilities::register();

		register_activation_hook( $this->plugin_file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->plugin_file, array( $this, 'deactivate' ) );

		// The fallback loader only matters when the mu-plugin is not in place.
		// Adding the hook unconditionally would mean every request pays for a
		// check that is almost always answered "the mu-plugin already did it".
		if ( ! defined( 'WPDEBLOAT_LOADER_MODE' ) ) {
			add_action( 'plugins_loaded', array( $this, 'loadRuntimeFallback' ), RuntimeLoader::FALLBACK_PRIORITY );
		}

		add_action( 'plugins_loaded', array( $this, 'resolveDeferredBypass' ), RuntimeLoader::FALLBACK_PRIORITY + 1 );

		$this->restController()->boot();
	}

	/**
	 * Activation: install the loader and record installation.
	 *
	 * @return void
	 */
	public function activate(): void {
		$state = $this->state();

		$state->markInstalled();

		$mode = $this->runtimeLoader()->install();

		$state->setRuntime( $this->runtimeWriter()->actualHash(), $mode );
	}

	/**
	 * Deactivation: stop the runtime loading, but keep the configuration.
	 *
	 * Deactivating is not uninstalling. The selection, the snapshots and the
	 * journal all survive, so reactivating restores exactly what was there.
	 * What must not survive is the runtime: leaving hooks registered by a
	 * deactivated plugin would be indistinguishable from a haunting.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->runtimeWriter()->remove();
		$this->runtimeLoader()->uninstall();

		$this->state()->setRuntime( '', RuntimeLoader::MODE_NONE );
	}

	/**
	 * Load the runtime from the plugin when no mu-plugin loader is installed.
	 *
	 * @return void
	 */
	public function loadRuntimeFallback(): void {
		$this->runtimeLoader()->loadFallback();
	}

	/**
	 * Honour a bypass request the runtime guard could not authorise in time.
	 *
	 * @return void
	 */
	public function resolveDeferredBypass(): void {
		if ( ! class_exists( 'WPDebloat_Runtime_Guard', false ) ) {
			return;
		}

		$compiler = $this->compiler();
		$classes  = array();

		foreach ( array_keys( $this->state()->selection() ) as $tweak_id ) {
			if ( $this->registry()->has( $tweak_id ) ) {
				$classes[] = $compiler->handlerClass( $tweak_id );
			}
		}

		$this->runtimeLoader()->resolveDeferredBypass( $classes );
	}

	/**
	 * The site context.
	 *
	 * @return Context
	 */
	public function context(): Context {
		return $this->service(
			'context',
			function (): Context {
				return new Context(
					home_url(),
					ABSPATH,
					WP_CONTENT_DIR,
					dirname( $this->plugin_file ),
					get_bloginfo( 'version' ),
					PHP_VERSION,
					$this->version,
					Capabilities::currentActor(),
					is_multisite()
				);
			}
		);
	}

	/**
	 * The persisted state.
	 *
	 * @return State
	 */
	public function state(): State {
		return $this->service( 'state', static fn (): State => new State() );
	}

	/**
	 * The loaded registry.
	 *
	 * @return Registry
	 */
	public function registry(): Registry {
		return $this->service(
			'registry',
			fn (): Registry => ( new Loader( dirname( $this->plugin_file ) . '/registry' ) )->load()
		);
	}

	/**
	 * The runtime compiler.
	 *
	 * @return Compiler
	 */
	public function compiler(): Compiler {
		return $this->service( 'compiler', fn (): Compiler => new Compiler( $this->context() ) );
	}

	/**
	 * The runtime writer.
	 *
	 * @return RuntimeWriter
	 */
	public function runtimeWriter(): RuntimeWriter {
		return $this->service( 'runtime_writer', fn (): RuntimeWriter => new RuntimeWriter( $this->context() ) );
	}

	/**
	 * The runtime loader.
	 *
	 * @return RuntimeLoader
	 */
	public function runtimeLoader(): RuntimeLoader {
		return $this->service( 'runtime_loader', fn (): RuntimeLoader => new RuntimeLoader( $this->context() ) );
	}

	/**
	 * The REST controller.
	 *
	 * @return Controller
	 */
	public function restController(): Controller {
		return $this->service(
			'rest',
			fn (): Controller => new Controller( $this, array( new StatusRoute( $this ) ) )
		);
	}

	/**
	 * Regenerate the runtime from the saved selection.
	 *
	 * Phase 1 exposes this so activation, the CLI and the tests share one code
	 * path. ApplyManager takes over the orchestration in Phase 5; this stays as
	 * the primitive it calls.
	 *
	 * @return string The runtime hash, or '' when nothing is selected.
	 */
	public function regenerateRuntime(): string {
		$registry  = $this->registry();
		$compiler  = $this->compiler();
		$selection = $this->state()->selection();
		$tweaks    = array();

		foreach ( $selection as $tweak_id => $params ) {
			if ( ! $registry->has( $tweak_id ) ) {
				continue;
			}

			$tweaks[] = $registry->tweak( $tweak_id )->resolve( $params );
		}

		$source = $compiler->compile( $tweaks, $registry->hash() );
		$hash   = $this->runtimeWriter()->write( $source, $compiler->selectionHash( $tweaks ), $registry->hash() );

		$mode = '' === $hash ? RuntimeLoader::MODE_NONE : $this->runtimeLoader()->install();

		$this->state()->setRuntime( $hash, $mode );

		return $hash;
	}

	/**
	 * The plugin version.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * The plugin's main file.
	 *
	 * @return string
	 */
	public function file(): string {
		return $this->plugin_file;
	}

	/**
	 * Build a service once and reuse it.
	 *
	 * @template T
	 * @param string       $key     Service key.
	 * @param callable():T $factory Factory.
	 * @return T
	 */
	private function service( string $key, callable $factory ) {
		if ( ! array_key_exists( $key, $this->services ) ) {
			$this->services[ $key ] = $factory();
		}

		/** @var T $service */
		$service = $this->services[ $key ];

		return $service;
	}

	/**
	 * Forget every built service. Used by tests that change the environment.
	 *
	 * @return void
	 */
	public function resetServices(): void {
		$this->services = array();
	}

	/**
	 * Discard the booted instance. Used by tests only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
