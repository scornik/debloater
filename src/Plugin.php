<?php
/**
 * Plugin boot sequence and service locator.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat;

use WPDebloat\Analyze\Analyzer;
use WPDebloat\Analyze\Rules;
use WPDebloat\Apply\Compiler;
use WPDebloat\Apply\RuntimeLoader;
use WPDebloat\Apply\RuntimeWriter;
use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Run;
use WPDebloat\Contracts\RunType;
use WPDebloat\Recommend\IntentProfile;
use WPDebloat\Recommend\PlanResult;
use WPDebloat\Recommend\PreviewPlanner;
use WPDebloat\Recommend\RecommendationEngine;
use WPDebloat\Registry\Loader;
use WPDebloat\Registry\Profile;
use WPDebloat\Registry\Registry;
use WPDebloat\Rest\Controller;
use WPDebloat\Rest\Routes\FindingsRoute;
use WPDebloat\Rest\Routes\PreviewRoute;
use WPDebloat\Rest\Routes\ScanRoute;
use WPDebloat\Rest\Routes\StatusRoute;
use WPDebloat\Scan\ScanRunner;
use WPDebloat\Scan\Scanners\AdminScanner;
use WPDebloat\Scan\Scanners\AutoloadScanner;
use WPDebloat\Scan\Scanners\CoreFeatureScanner;
use WPDebloat\Scan\Scanners\CronScanner;
use WPDebloat\Scan\Scanners\DatabaseScanner;
use WPDebloat\Scan\Scanners\EnvironmentScanner;
use WPDebloat\Scan\Scanners\PluginScanner;
use WPDebloat\Scan\Scanners\ThemeScanner;
use WPDebloat\Scan\Scanners\UserScanner;
use WPDebloat\Scan\Scanners\WordPressScanner;
use WPDebloat\Security\Capabilities;
use WPDebloat\Storage\Repositories\RunRepository;
use WPDebloat\Storage\Schema;
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

		// Tables are checked on an admin request only. A front-end request must
		// never pay for a migration check, and nothing on the front end reads
		// them anyway.
		add_action( 'admin_init', array( $this, 'ensureSchema' ) );

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

		$this->schema()->ensure();

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
	 * Create or migrate the tables if the site is not already up to date.
	 *
	 * @return void
	 */
	public function ensureSchema(): void {
		$this->schema()->ensure();
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
	 * The database schema manager.
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->service( 'schema', fn (): Schema => new Schema( $this->state() ) );
	}

	/**
	 * The run repository.
	 *
	 * @return RunRepository
	 */
	public function runs(): RunRepository {
		return $this->service( 'runs', static fn (): RunRepository => new RunRepository() );
	}

	/**
	 * The scan runner, with every scanner in a deterministic order.
	 *
	 * Order matters only for the diagnostics and for reproducibility; no scanner
	 * depends on another's output, which is what lets one fail without taking
	 * the rest of the scan with it.
	 *
	 * @return ScanRunner
	 */
	public function scanRunner(): ScanRunner {
		return $this->service(
			'scan_runner',
			fn (): ScanRunner => new ScanRunner(
				array(
					new EnvironmentScanner(),
					new WordPressScanner(),
					new CoreFeatureScanner(),
					new UserScanner(),
					new PluginScanner( $this->registry() ),
					new ThemeScanner(),
					new DatabaseScanner(),
					new AutoloadScanner(),
					new CronScanner(),
					new AdminScanner(),
				),
				$this->runs()
			)
		);
	}

	/**
	 * The analyzer, with every rule in a deterministic order.
	 *
	 * @return Analyzer
	 */
	public function analyzer(): Analyzer {
		return $this->service(
			'analyzer',
			fn (): Analyzer => new Analyzer( Rules::all(), $this->registry(), $this->hasCustomMuPlugins() )
		);
	}

	/**
	 * Run a scan, analyze it, and record both in one run.
	 *
	 * Facts and findings live in the same run payload deliberately: a finding is
	 * only meaningful next to the facts it was drawn from, and storing them
	 * apart would let one be read against the other's site.
	 *
	 * @return Run
	 */
	public function scan(): Run {
		$this->schema()->ensure();

		$run      = $this->scanRunner()->run( $this->context(), $this->registry()->hash() );
		$analysis = $this->analyzer()->analyze( $run->facts() );

		$run = $this->runs()->update(
			$run->withPayload( array_merge( $run->payload, array( 'analysis' => $analysis->toArray() ) ) )
		);

		if ( null !== $run->id ) {
			$this->state()->set( array( 'last_scan_run_id' => $run->id ) );
		}

		return $run;
	}

	/**
	 * The stated intent for this site.
	 *
	 * @return IntentProfile
	 */
	public function intentProfile(): IntentProfile {
		$stored = $this->state()->get( 'intent_profile', array() );

		return IntentProfile::fromArray( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Record the stated intent.
	 *
	 * @param IntentProfile $intent Intent to store.
	 * @return void
	 */
	public function setIntentProfile( IntentProfile $intent ): void {
		$this->state()->set( array( 'intent_profile' => $intent->toArray() ) );
	}

	/**
	 * Build a plan from the most recent scan.
	 *
	 * Reads a recorded scan rather than running a new one, so the plan a user
	 * confirms is built from the findings they were shown. Returns null when
	 * there is nothing to plan from — a plan invented without a scan would be a
	 * plan with no evidence behind it.
	 *
	 * @param string|null $profile_id Profile to filter by, or null for the default.
	 * @param int|null    $run_id     Scan run to plan from, or null for the most recent.
	 * @return PlanResult|null
	 */
	public function preview( ?string $profile_id = null, ?int $run_id = null ): ?PlanResult {
		$run = $this->latestScan( $run_id );

		if ( null === $run ) {
			return null;
		}

		$findings = $this->findingsOf( $run );
		$facts    = $run->facts();
		$engine   = new RecommendationEngine( $this->registry(), $facts, $this->intentProfile() );
		$planner  = new PreviewPlanner( $this->registry(), $facts, $findings );
		$tweaks   = $engine->recommend( $findings )->tweaks;

		if ( null === $profile_id || Profile::SAFE === $profile_id ) {
			return $planner->safePlan( $tweaks );
		}

		return $planner->plan( $tweaks, $this->registry()->profile( $profile_id ) );
	}

	/**
	 * The findings recorded on a run, rebuilt as contracts.
	 *
	 * A finding this version cannot read is skipped rather than crashing the
	 * screen that lists it: an old run should degrade, not explode.
	 *
	 * @param Run $run Run to read.
	 * @return array<int,\WPDebloat\Contracts\Finding>
	 */
	public function findingsOf( Run $run ): array {
		$analysis = $run->payload['analysis'] ?? array();
		$stored   = is_array( $analysis ) ? ( $analysis['findings'] ?? array() ) : array();
		$findings = array();

		foreach ( is_array( $stored ) ? $stored : array() as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			try {
				$findings[] = \WPDebloat\Contracts\Finding::fromArray( $data );
			} catch ( \WPDebloat\Contracts\ContractViolation $exception ) {
				unset( $exception );
			}
		}

		return $findings;
	}

	/**
	 * The most recent scan run, or a specific one by id.
	 *
	 * @param int|null $run_id Run id, or null for the most recent scan.
	 * @return Run|null
	 */
	public function latestScan( ?int $run_id = null ): ?Run {
		if ( null !== $run_id ) {
			$run = $this->runs()->find( $run_id );

			return ( null !== $run && RunType::SCAN === $run->type ) ? $run : null;
		}

		return $this->runs()->latestOfType( RunType::SCAN );
	}

	/**
	 * Whether the site has must-use plugins of its own.
	 *
	 * Our own loader does not count: it is ours, we know exactly what it does,
	 * and penalising confidence for installing it would be absurd. Anything else
	 * in mu-plugins is site-specific code we cannot inspect, which is one of the
	 * confidence penalties in docs/SCORING.md.
	 *
	 * @return bool
	 */
	public function hasCustomMuPlugins(): bool {
		$directory = $this->context()->muPluginsDir();

		if ( ! is_dir( $directory ) ) {
			return false;
		}

		$files = glob( $directory . '/*.php' );

		foreach ( false === $files ? array() : $files as $file ) {
			if ( RuntimeLoader::LOADER_FILE !== basename( $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The REST controller.
	 *
	 * @return Controller
	 */
	public function restController(): Controller {
		return $this->service(
			'rest',
			fn (): Controller => new Controller(
				$this,
				array(
					new StatusRoute( $this ),
					new ScanRoute( $this ),
					new FindingsRoute( $this ),
					new PreviewRoute( $this ),
				)
			)
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
