<?php
/**
 * Plugin boot sequence and service locator.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater;

use Debloater\Admin\Screen;
use Debloater\Analyze\Analyzer;
use Debloater\Analyze\Rules;
use Debloater\Apply\ApplyManager;
use Debloater\Apply\Compiler;
use Debloater\Apply\DataOperations\AutoDraftsCleanup;
use Debloater\Apply\DataOperations\AutoloadReview;
use Debloater\Apply\DataOperations\ExpiredTransientsCleanup;
use Debloater\Apply\DataOperations\OrphanMetaCleanup;
use Debloater\Apply\DataOperations\RevisionsCleanup;
use Debloater\Apply\DataOperations\SpamCommentsCleanup;
use Debloater\Apply\DataOperations\TrashCleanup;
use Debloater\Apply\Lock;
use Debloater\Apply\RuntimeLoader;
use Debloater\Apply\RuntimeWriter;
use Debloater\Cli\Command;
use Debloater\Contracts\ApplyResult;
use Debloater\Contracts\Context;
use Debloater\Contracts\DataOperationInterface;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\Run;
use Debloater\Contracts\RunType;
use Debloater\Contracts\VerificationResult;
use Debloater\Journal\Journal;
use Debloater\Meter\Meter;
use Debloater\Recommend\IntentProfile;
use Debloater\Recommend\PlanResult;
use Debloater\Recommend\PreviewPlanner;
use Debloater\Recommend\RecommendationEngine;
use Debloater\Registry\Loader;
use Debloater\Update\Manifest;
use Debloater\Update\RegistryUpdater;
use Debloater\Registry\Profile;
use Debloater\Registry\Registry;
use Debloater\Registry\SchemaValidator;
use Debloater\Rest\Controller;
use Debloater\Rest\Routes\ApplyRoute;
use Debloater\Rest\Routes\FindingsRoute;
use Debloater\Rest\Routes\PreviewRoute;
use Debloater\Rest\Routes\RollbackRoute;
use Debloater\Rest\Routes\RunRoute;
use Debloater\Rest\Routes\ScanRoute;
use Debloater\Rest\Routes\SnapshotsRoute;
use Debloater\Rest\Routes\StatusRoute;
use Debloater\Scan\ScanRunner;
use Debloater\Scan\WpOrgUpdates;
use Debloater\Scan\Scanners\AdminScanner;
use Debloater\Scan\SampledPages;
use Debloater\Scan\Scanners\AssetScanner;
use Debloater\Scan\Scanners\ElementorScanner;
use Debloater\Scan\Scanners\AutoloadScanner;
use Debloater\Scan\Scanners\CoreFeatureScanner;
use Debloater\Scan\Scanners\CronScanner;
use Debloater\Scan\Scanners\DatabaseScanner;
use Debloater\Scan\Scanners\EnvironmentScanner;
use Debloater\Scan\Scanners\PluginScanner;
use Debloater\Scan\Scanners\ThemeScanner;
use Debloater\Scan\Scanners\WooCommerceScanner;
use Debloater\Scan\Scanners\UserScanner;
use Debloater\Scan\Scanners\WordPressScanner;
use Debloater\Security\Capabilities;
use Debloater\Snapshot\RollbackManager;
use Debloater\Snapshot\SnapshotManager;
use Debloater\Storage\Repositories\RunRepository;
use Debloater\Storage\Repositories\SnapshotRepository;
use Debloater\Storage\Schema;
use Debloater\Storage\State;
use Debloater\Verify\HttpClient;
use Debloater\Verify\Probes\AdminProbe;
use Debloater\Verify\Probes\ContentPageProbe;
use Debloater\Verify\Probes\HomeProbe;
use Debloater\Verify\Probes\LoginProbe;
use Debloater\Verify\Probes\RestProbe;
use Debloater\Verify\Probes\RuntimeLoadedProbe;
use Debloater\Verify\Probes\WooAccountProbe;
use Debloater\Verify\Probes\WooCartProbe;
use Debloater\Verify\Probes\WooCheckoutProbe;
use Debloater\Verify\Verifier;

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
		if ( ! defined( 'DEBLOATER_LOADER_MODE' ) ) {
			add_action( 'plugins_loaded', array( $this, 'loadRuntimeFallback' ), RuntimeLoader::FALLBACK_PRIORITY );
		}

		add_action( 'plugins_loaded', array( $this, 'resolveDeferredBypass' ), RuntimeLoader::FALLBACK_PRIORITY + 1 );

		// Tables are checked on an admin request only. A front-end request must
		// never pay for a migration check, and nothing on the front end reads
		// them anyway.
		add_action( 'admin_init', array( $this, 'ensureSchema' ) );

		// After ensureSchema, because recovery reads the snapshot tables, and
		// because a site that has just upgraded may not have them yet.
		add_action( 'admin_init', array( $this, 'recoverOnBoot' ), 11 );

		$this->restController()->boot();
		$this->adminScreen()->boot();

		// WP-CLI dispatches after plugins load, so registering here is early
		// enough, and a site that is not running WP-CLI never constructs it.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( Brand::CLI_COMMAND, Command::class );
		}
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
		if ( ! class_exists( 'Debloater_Runtime_Guard', false ) ) {
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
					new PluginScanner( $this->registry(), $this->wpOrgUpdates() ),
					new ThemeScanner(),
					new DatabaseScanner(),
					new AutoloadScanner(),
					new CronScanner(),
					new AdminScanner( $this->registry() ),
					new AssetScanner( $this->sampledPages() ),
					new WooCommerceScanner( $this->sampledPages() ),
					new ElementorScanner(),
				),
				$this->runs()
			)
		);
	}

	/**
	 * The wordpress.org release-date lookup.
	 *
	 * Off unless a scan is explicitly asked for it. There is no stored setting
	 * that, once ticked, makes every future scan reach the network: consent is
	 * given for the action that makes the request, and the next scan asks again
	 * (BUILD-SPEC §13 rule 9, docs/DECISIONS.md D-0029).
	 *
	 * @return WpOrgUpdates
	 */
	public function wpOrgUpdates(): WpOrgUpdates {
		return $this->service( 'wp_org_updates', static fn (): WpOrgUpdates => new WpOrgUpdates( false ) );
	}

	/**
	 * The tag of the registry snapshot this build carries.
	 *
	 * Read from the vendored manifest, not from a constant somebody has to
	 * remember to bump: the manifest is generated from the files themselves, so
	 * the tag and the contents cannot drift apart.
	 *
	 * @return string
	 */
	public function registryTag(): string {
		$path = $this->context()->plugin_dir . '/registry/manifest.json';

		if ( ! is_file( $path ) ) {
			return '';
		}

		try {
			$decoded = json_decode( (string) file_get_contents( $path ), true );

			return is_array( $decoded ) ? Manifest::fromArray( $decoded )->tag : '';
		} catch ( \Throwable $error ) {
			unset( $error );

			return '';
		}
	}

	/**
	 * The registry update check, off unless asked.
	 *
	 * @return RegistryUpdater
	 */
	public function registryUpdater(): RegistryUpdater {
		return $this->service(
			'registry_updater',
			fn (): RegistryUpdater => new RegistryUpdater( $this->registryTag() )
		);
	}

	/**
	 * The page sample, shared by every scanner that reads pages.
	 *
	 * One fetch, several readers. The asset scan and the WooCommerce scan both
	 * need the rendered pages, and scanners cannot read each other's facts, so
	 * without this the second one would fetch every page again to learn nothing
	 * new.
	 *
	 * @return SampledPages
	 */
	public function sampledPages(): SampledPages {
		return $this->service( 'sampled_pages', static fn (): SampledPages => new SampledPages() );
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
	 * @param bool $check_plugin_updates Whether the caller asked, on this scan,
	 *                                    for plugin release dates to be looked
	 *                                    up at wordpress.org. Off by default and
	 *                                    reset afterwards, so it can never
	 *                                    become a standing permission.
	 * @return Run
	 */
	public function scan( bool $check_plugin_updates = false ): Run {
		$this->schema()->ensure();

		$this->wpOrgUpdates()->setEnabled( $check_plugin_updates );

		try {
			$run = $this->scanRunner()->run( $this->context(), $this->registry()->hash() );
		} finally {
			$this->wpOrgUpdates()->setEnabled( false );
		}

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
	 * @return array<int,\Debloater\Contracts\Finding>
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
				$findings[] = \Debloater\Contracts\Finding::fromArray( $data );
			} catch ( \Debloater\Contracts\ContractViolation $exception ) {
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
					new SnapshotsRoute( $this ),
					new RunRoute( $this ),
					new ApplyRoute( $this ),
					new RollbackRoute( $this ),
				)
			)
		);
	}

	/**
	 * The snapshot repository.
	 *
	 * @return SnapshotRepository
	 */
	public function snapshots(): SnapshotRepository {
		return $this->service( 'snapshots', static fn (): SnapshotRepository => new SnapshotRepository() );
	}

	/**
	 * The journal.
	 *
	 * @return Journal
	 */
	public function journal(): Journal {
		return $this->service( 'journal', fn (): Journal => new Journal( $this->context()->actor ) );
	}

	/**
	 * Every data operation this version knows how to perform, by tweak id.
	 *
	 * Keyed by tweak id because that is how both the apply path and the restore
	 * path look one up: a snapshot records which tweak took it, and restoring it
	 * later means finding the operation that knows how to put those rows back.
	 * An operation removed from the plugin makes its old snapshots unrestorable,
	 * which is why removing one is a breaking change and not a tidy-up.
	 *
	 * @return array<string,DataOperationInterface>
	 */
	public function dataOperations(): array {
		return $this->service(
			'data_operations',
			static function (): array {
				$operations = array();

				$available = array(
					new ExpiredTransientsCleanup(),
					new RevisionsCleanup(),
					new AutoDraftsCleanup(),
					new TrashCleanup(),
					new SpamCommentsCleanup(),
					new OrphanMetaCleanup(),
					new AutoloadReview(),
				);

				foreach ( $available as $operation ) {
					$operations[ $operation->tweakId() ] = $operation;
				}

				return $operations;
			}
		);
	}

	/**
	 * The snapshot manager.
	 *
	 * @return SnapshotManager
	 */
	public function snapshotManager(): SnapshotManager {
		return $this->service(
			'snapshot_manager',
			fn (): SnapshotManager => new SnapshotManager( $this->context(), $this->snapshots(), $this->state() )
		);
	}

	/**
	 * The rollback manager.
	 *
	 * @return RollbackManager
	 */
	public function rollbackManager(): RollbackManager {
		return $this->service(
			'rollback_manager',
			fn (): RollbackManager => new RollbackManager(
				$this->context(),
				$this->snapshots(),
				$this->state(),
				$this->registry(),
				$this->snapshotManager(),
				$this->dataOperations()
			)
		);
	}

	/**
	 * The apply manager.
	 *
	 * @return ApplyManager
	 */
	public function applyManager(): ApplyManager {
		return $this->service(
			'apply_manager',
			fn (): ApplyManager => new ApplyManager(
				$this->context(),
				$this->registry(),
				$this->runs(),
				$this->snapshots(),
				$this->snapshotManager(),
				$this->rollbackManager(),
				$this->state(),
				$this->journal(),
				$this->dataOperations(),
				new Lock(),
				$this->verifier(),
				$this->meter()
			)
		);
	}

	/**
	 * The meter.
	 *
	 * Shares the verification HTTP client, so the pages it counts are fetched
	 * exactly the way the probes fetch them — same timeout, same header, same
	 * SSL setting. Two different clients would eventually disagree about what
	 * the site returned.
	 *
	 * @return Meter
	 */
	public function meter(): Meter {
		return $this->service(
			'meter',
			fn (): Meter => new Meter( $this->context(), $this->httpClient(), $this->state() )
		);
	}

	/**
	 * The HTTP client verification uses.
	 *
	 * @return HttpClient
	 */
	public function httpClient(): HttpClient {
		return $this->service( 'http_client', fn (): HttpClient => new HttpClient( $this->context() ) );
	}

	/**
	 * The verifier, with every probe in a deterministic order.
	 *
	 * The order is the order a person would check in: does the site work for a
	 * visitor, does a real page work, can its owner still get in, does the API
	 * answer, and only then, did the change we made actually take effect.
	 *
	 * @return Verifier
	 */
	public function verifier(): Verifier {
		return $this->service(
			'verifier',
			function (): Verifier {
				$http = $this->httpClient();

				return new Verifier(
					$this->context(),
					array(
						new HomeProbe( $http ),
						new ContentPageProbe( $http ),
						new AdminProbe( $http ),
						new RestProbe( $http ),
						new LoginProbe( $http ),
						new WooCartProbe( $http ),
						new WooCheckoutProbe( $http ),
						new WooAccountProbe( $http ),
						new RuntimeLoadedProbe( $http, $this->state() ),
					),
					$http
				);
			}
		);
	}

	/**
	 * Verify the site as it stands, without changing anything.
	 *
	 * @return VerificationResult
	 */
	public function verify(): VerificationResult {
		$run = $this->latestScan();

		return $this->verifier()->verify( null === $run ? null : $run->facts() );
	}

	/**
	 * Record that the user says they have their own external backup.
	 *
	 * This is BUILD-SPEC §8's Level C, and §12 rule 8 is explicit that it never
	 * substitutes for Level B. It is stored rather than acted on: a later
	 * conversation about a deletion is better for having a record of what the
	 * person believed at the time, and no better for having skipped a backup on
	 * the strength of it.
	 *
	 * @param bool $attested Whether the user stated they have an external backup.
	 * @return void
	 */
	public function recordAttestation( bool $attested ): void {
		$this->state()->set(
			array(
				'attestation' => array(
					'external_backup' => $attested,
					'stated_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'actor'           => $this->context()->actor,
				),
			)
		);
	}

	/**
	 * Apply a plan.
	 *
	 * @param PreviewPlan $plan Plan to apply.
	 * @return ApplyResult
	 */
	public function apply( PreviewPlan $plan ): ApplyResult {
		$this->schema()->ensure();

		return $this->applyManager()->apply( $plan );
	}

	/**
	 * Undo a run.
	 *
	 * @param int $run_id Run to undo.
	 * @return ApplyResult
	 */
	public function rollback( int $run_id ): ApplyResult {
		$this->schema()->ensure();

		return $this->applyManager()->rollbackRun( $run_id );
	}

	/**
	 * Roll back any run whose process died partway through.
	 *
	 * @return array<int,int> Ids of the runs recovered.
	 */
	public function recoverInterruptedRuns(): array {
		return $this->applyManager()->recoverInterruptedRuns();
	}

	/**
	 * Crash recovery as an action callback.
	 *
	 * Separate from recoverInterruptedRuns() only because a hook callback must
	 * return nothing, and the ids are worth having for the CLI and the tests.
	 *
	 * Nothing may escape from here. This runs on admin_init, on every admin
	 * page load, and it runs precisely when the previous request did not
	 * finish — so the state it reads is the state least likely to be
	 * well-formed. An exception at this point would be a fatal on every
	 * wp-admin page, which locks somebody out of the only screen that could fix
	 * it, over a run that was already broken before they arrived.
	 *
	 * So a failure to recover leaves the interrupted run interrupted, which is
	 * where it already was, and the dashboard still loads and still says so.
	 *
	 * @return void
	 */
	public function recoverOnBoot(): void {
		global $wpdb;

		// $wpdb prints its errors straight into the page when WP_DEBUG_DISPLAY
		// is on. On the one path where a database error is expected and
		// tolerated, that would put raw markup at the top of every admin screen
		// — which is both the admin notice this plugin promised not to add and
		// a worse way to learn about it than the dashboard already offers.
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$this->recoverInterruptedRuns();
		} catch ( \Throwable $error ) {
			// Swallowed, and not silently: the run stays in the state it was
			// already in, the dashboard and `status` both show it as
			// interrupted, and recovery runs again on the next admin page load
			// because it is idempotent. So the failure is visible in the place
			// somebody would look, without an admin notice this plugin promised
			// not to add and without taking wp-admin down to say it.
			unset( $error );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Build a plan from an explicit list of changes.
	 *
	 * The same planner, and therefore the same §7.4 invariants, as a profile
	 * plan: naming a tweak asks for it to be considered, not for the rules to be
	 * suspended. A tweak the site refuses is still excluded, with the reason.
	 *
	 * @param array<int,string> $tweak_ids Tweak ids to consider.
	 * @param int|null          $run_id    Scan run to plan from, or null for the most recent.
	 * @return PlanResult|null
	 */
	public function previewTweaks( array $tweak_ids, ?int $run_id = null ): ?PlanResult {
		$run = $this->latestScan( $run_id );

		if ( null === $run ) {
			return null;
		}

		$registry   = $this->registry();
		$candidates = array();

		foreach ( $tweak_ids as $tweak_id ) {
			if ( $registry->has( $tweak_id ) ) {
				$candidates[] = $registry->tweak( $tweak_id )->resolve();
			}
		}

		return ( new PreviewPlanner( $registry, $run->facts(), $this->findingsOf( $run ) ) )->plan( $candidates );
	}

	/**
	 * The schema a configuration document must satisfy.
	 *
	 * Import validates against this before anything in the file is looked at,
	 * because §13 rule 5 puts schema validation between user input and anything
	 * that becomes generated code.
	 *
	 * It lives in `schemas/`, not `registry/schemas/`, because it does not
	 * describe registry content. §4 names exactly six registry schemas, and a
	 * repository invariant holds them to that.
	 *
	 * @return SchemaValidator
	 */
	public function configSchema(): SchemaValidator {
		return $this->service(
			'config_schema',
			fn (): SchemaValidator => SchemaValidator::fromFile(
				dirname( $this->plugin_file ) . '/schemas/config.schema.json'
			)
		);
	}

	/**
	 * The admin screen.
	 *
	 * @return Screen
	 */
	public function adminScreen(): Screen {
		return $this->service( 'admin_screen', fn (): Screen => new Screen( $this ) );
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
