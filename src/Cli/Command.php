<?php
/**
 * `wp debloater`.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Cli;

use Throwable;
use Debloater\Apply\Lock;
use Debloater\Config\ConfigDocument;
use Debloater\Contracts\ApplyResult;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Json;
use Debloater\Contracts\ProbeStatus;
use Debloater\Contracts\Risk;
use Debloater\Contracts\RunState;
use Debloater\Contracts\Snapshot;
use Debloater\Contracts\VerificationResult;
use Debloater\Plugin;
use Debloater\Recommend\PlanResult;

/**
 * The whole MVP loop from a terminal (BUILD-SPEC §17 Phase 7).
 *
 * This class contains no product logic and is not allowed to. Every decision —
 * what to recommend, what may go in a plan, what to snapshot, whether the site
 * still works afterwards — is made by the engine, exactly as it is for the
 * dashboard. What lives here is argument parsing, formatting and exit codes.
 *
 * That constraint is not tidiness. A CLI that decided anything for itself would
 * be a second implementation of the rules, and the two would disagree the first
 * time one of them was changed.
 *
 * Exit codes (§17 Phase 7):
 *
 * - 0 — it worked.
 * - 1 — an error; nothing was changed, or the change was refused.
 * - 2 — verification failed and the change was rolled back.
 * - 3 — verification passed with warnings; the change is in place.
 */
final class Command {

	/**
	 * Everything went well.
	 */
	public const EXIT_OK = 0;

	/**
	 * Something went wrong.
	 */
	public const EXIT_ERROR = 1;

	/**
	 * Verification failed; the change was rolled back.
	 */
	public const EXIT_ROLLED_BACK = 2;

	/**
	 * Verification warned; the change stands.
	 */
	public const EXIT_WARNINGS = 3;

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Terminal.
	 *
	 * @var Io
	 */
	private Io $io;

	/**
	 * Constructor.
	 *
	 * WP-CLI constructs this with no arguments, so both dependencies have to be
	 * optional; the tests pass their own.
	 *
	 * @param Plugin|null $plugin The plugin.
	 * @param Io|null     $io     Terminal.
	 */
	public function __construct( ?Plugin $plugin = null, ?Io $io = null ) {
		$plugin = $plugin ?? Plugin::instance();

		if ( null === $plugin ) {
			throw new \RuntimeException( 'Debloater is not loaded.' );
		}

		$this->plugin = $plugin;
		$this->io     = $io ?? new WpCliIo();
	}

	/**
	 * Scan the site and analyze what was found.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--check-plugin-updates]
	 * : Look up plugin release dates at wordpress.org. This is the only thing WP
	 * Debloat sends off the server; without this flag the scan stays entirely on
	 * the machine and staleness is read from file dates instead, which is a
	 * weaker answer and is reported as one. Not remembered: the next scan asks
	 * again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp debloater scan
	 *     wp debloater scan --json
	 *     wp debloater scan --check-plugin-updates
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function scan( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				$run      = $this->plugin->scan( $this->flag( $assoc_args, 'check-plugin-updates' ) );
				$findings = $this->plugin->findingsOf( $run );
				$analysis = is_array( $run->payload['analysis'] ?? null ) ? $run->payload['analysis'] : array();
				$score    = is_array( $analysis['score'] ?? null ) ? $analysis['score'] : array();

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json(
						array(
							'run_id'   => (int) $run->id,
							'scanned'  => $run->started_at,
							'facts'    => $run->facts()->toArray(),
							'findings' => array_map(
								static fn ( Finding $finding ): array => $finding->toArray(),
								$findings
							),
							'score'    => $score,
						)
					);

					return self::EXIT_OK;
				}

				$this->io->success(
					sprintf(
						/* translators: 1: number of facts, 2: number of findings. */
						__( 'Scanned the site: %1$d facts, %2$d findings.', 'debloater' ),
						count( $run->facts()->toArray() ),
						count( $findings )
					)
				);

				$this->printScore( $score );

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * List the findings from the most recent scan.
	 *
	 * ## OPTIONS
	 *
	 * [--risk=<risk>]
	 * : Only findings at this risk level.
	 * ---
	 * options:
	 *   - low
	 *   - medium
	 *   - high
	 * ---
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function findings( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				$run = $this->plugin->latestScan();

				if ( null === $run ) {
					$this->io->error( __( 'There is no scan to read. Run `wp debloater scan` first.', 'debloater' ) );

					return self::EXIT_ERROR;
				}

				$findings = $this->plugin->findingsOf( $run );
				$risk     = $this->option( $assoc_args, 'risk', '' );

				if ( '' !== $risk ) {
					$wanted = Risk::tryFrom( $risk );

					if ( null === $wanted ) {
						$this->io->error(
							sprintf(
								/* translators: %s: the value given. */
								__( '"%s" is not a risk level. Use low, medium or high.', 'debloater' ),
								$risk
							)
						);

						return self::EXIT_ERROR;
					}

					$findings = array_values(
						array_filter( $findings, static fn ( Finding $finding ): bool => $finding->risk === $wanted )
					);
				}

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json(
						array(
							'run_id'   => (int) $run->id,
							'risk'     => '' === $risk ? null : $risk,
							'count'    => count( $findings ),
							'findings' => array_map(
								static fn ( Finding $finding ): array => $finding->toArray(),
								$findings
							),
						)
					);

					return self::EXIT_OK;
				}

				if ( array() === $findings ) {
					$this->io->line( __( 'Nothing to report.', 'debloater' ) );

					return self::EXIT_OK;
				}

				$rows = array();

				foreach ( $findings as $finding ) {
					$rows[] = array(
						'id'         => $finding->id,
						'title'      => $finding->title,
						'severity'   => $finding->severity->value,
						'risk'       => $finding->risk->value,
						'decision'   => $finding->decision->value,
						'confidence' => $finding->confidence,
					);
				}

				$this->io->table( $rows, array( 'id', 'title', 'severity', 'risk', 'decision', 'confidence' ) );

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * Show what a change would do, without doing it.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Which profile to plan from.
	 * ---
	 * default: safe
	 * options:
	 *   - safe
	 *   - performance
	 *   - maximum
	 * ---
	 *
	 * [--tweaks=<ids>]
	 * : A comma-separated list of tweak ids to plan instead of a profile.
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function preview( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				$result = $this->planFrom( $assoc_args );

				if ( null === $result ) {
					return self::EXIT_ERROR;
				}

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $result->toArray() );

					return self::EXIT_OK;
				}

				$this->printPlan( $result );

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * Apply a plan.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Which profile to apply.
	 * ---
	 * default: safe
	 * options:
	 *   - safe
	 *   - performance
	 *   - maximum
	 * ---
	 *
	 * [--tweaks=<ids>]
	 * : A comma-separated list of tweak ids to apply instead of a profile.
	 *
	 * [--yes]
	 * : Required. Applying changes the site.
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function apply( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				if ( ! $this->confirmed( $assoc_args ) ) {
					return self::EXIT_ERROR;
				}

				$result = $this->planFrom( $assoc_args );

				if ( null === $result ) {
					return self::EXIT_ERROR;
				}

				if ( $result->plan->isEmpty() ) {
					$this->io->warning( __( 'There is nothing to apply: the plan is empty.', 'debloater' ) );

					return self::EXIT_OK;
				}

				$applied = $this->plugin->apply( $result->plan );

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $applied->toArray() );
				} else {
					$this->printApplyResult( $applied );
				}

				return $this->exitCodeFor( $applied );
			}
		);
	}

	/**
	 * Check that the site still works.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--e2e]
	 * : Print how to run the end-to-end browser suite, and exit. The suite is a
	 * development tool that is not shipped with the plugin, so this prints
	 * instructions rather than pretending to run something that is not there.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function verify( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				if ( $this->flag( $assoc_args, 'e2e' ) ) {
					$this->printE2eInstructions();

					return self::EXIT_OK;
				}

				$result = $this->plugin->verify();

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $result->toArray() );
				} else {
					$this->printVerification( $result );
				}

				if ( $result->isFailure() ) {
					return self::EXIT_ROLLED_BACK;
				}

				return $result->isClean() ? self::EXIT_OK : self::EXIT_WARNINGS;
			}
		);
	}

	/**
	 * Explain how to run the end-to-end suite.
	 *
	 * The suite lives in the repository, not in the plugin: it drives a real
	 * browser through a real WordPress with WooCommerce and Elementor on it, and
	 * none of that belongs in a plugin somebody installs. So `--e2e` is a
	 * signpost rather than a runner, and says so plainly instead of failing with
	 * "playwright: not found".
	 *
	 * @return void
	 */
	private function printE2eInstructions(): void {
		$this->io->line( __( 'The end-to-end suite is part of the Debloater repository and is not shipped with the plugin.', 'debloater' ) );
		$this->io->line( '' );
		$this->io->line( __( 'To run it from a checkout:', 'debloater' ) );
		$this->io->line( '' );
		$this->io->line( '    npm install' );
		$this->io->line( '    npm run test:e2e:install     # downloads the browser, once' );
		$this->io->line( '    npx wp-env start             # WordPress with the full stack on it' );
		$this->io->line( '    npm run build                # the admin bundle the suite drives' );
		$this->io->line( '    npm run test:e2e:seed        # a product, a form and an Elementor page' );
		$this->io->line( '    npm run test:e2e' );
		$this->io->line( '' );
		$this->io->line( __( 'It also runs nightly in CI, and on a pull request labelled "e2e".', 'debloater' ) );
		$this->io->line( '' );
		$this->io->line( __( 'To check this site instead, run `wp debloater verify` with no flag.', 'debloater' ) );
	}

	/**
	 * Show the registry this build carries, and optionally look for a newer one.
	 *
	 * ## OPTIONS
	 *
	 * [--check-updates]
	 * : Ask whether a newer registry release exists. This is the only thing this
	 * command sends off the server, it happens only with this flag, and it is not
	 * remembered. Nothing is installed: a release that verifies is reported, and
	 * installing it is a separate, deliberate act.
	 *
	 * [--format=<format>]
	 * : How to print the result.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp debloater registry
	 *     wp debloater registry --check-updates
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function registry( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				$tag      = $this->plugin->registryTag();
				$registry = $this->plugin->registry();

				if ( ! $this->flag( $assoc_args, 'check-updates' ) ) {
					$document = array(
						'tag'      => $tag,
						'hash'     => $registry->hash(),
						'tweaks'   => $registry->count(),
						'profiles' => count( $registry->profiles() ),
					);

					if ( $this->wantsJson( $assoc_args ) ) {
						$this->io->json( $document );

						return self::EXIT_OK;
					}

					$this->io->line(
						sprintf(
							/* translators: 1: registry tag, 2: number of changes. */
							__( 'Registry %1$s, %2$d changes.', 'debloater' ),
							'' === $tag ? __( 'unversioned', 'debloater' ) : $tag,
							$registry->count()
						)
					);
					$this->io->line( sprintf( 'Hash: %s', $registry->hash() ) );

					return self::EXIT_OK;
				}

				$updater = $this->plugin->registryUpdater();

				$updater->setEnabled( true );

				try {
					$result = $updater->check( $tag );
				} finally {
					$updater->setEnabled( false );
				}

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $result->toArray() );
				} else {
					$this->io->line( $result->message );
				}

				return $result->wasRefused() ? self::EXIT_WARNINGS : self::EXIT_OK;
			}
		);
	}

	/**
	 * Undo a change.
	 *
	 * ## OPTIONS
	 *
	 * [<snapshot-id>]
	 * : The recovery point to go back to. Defaults to the most recent one.
	 *
	 * [--yes]
	 * : Required. Rolling back changes the site.
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$this->run(
			function () use ( $args, $assoc_args ): int {
				if ( ! $this->confirmed( $assoc_args ) ) {
					return self::EXIT_ERROR;
				}

				$snapshot = $this->snapshotFor( $args );

				if ( null === $snapshot ) {
					return self::EXIT_ERROR;
				}

				$refusal = $this->plugin->rollbackManager()->refusalReason( $snapshot );

				if ( null !== $refusal ) {
					$this->io->error( $refusal );

					return self::EXIT_ERROR;
				}

				$result = $this->plugin->rollback( $snapshot->run_id );

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $result->toArray() );
				} elseif ( RunState::ROLLED_BACK === $result->state ) {
					$this->io->success( (string) $result->error );
				} else {
					$this->io->error( (string) $result->error );
				}

				return RunState::ROLLED_BACK === $result->state ? self::EXIT_OK : self::EXIT_ERROR;
			}
		);
	}

	/**
	 * List, show or delete recovery points.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : What to do.
	 * ---
	 * default: list
	 * options:
	 *   - list
	 *   - show
	 *   - delete
	 * ---
	 *
	 * [<id>]
	 * : The recovery point, for show and delete.
	 *
	 * [--yes]
	 * : Required for delete.
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function snapshots( array $args, array $assoc_args ): void {
		$this->run(
			function () use ( $args, $assoc_args ): int {
				$action = $args[0] ?? 'list';

				if ( 'list' === $action ) {
					return $this->listSnapshots( $assoc_args );
				}

				if ( ! isset( $args[1] ) || ! ctype_digit( (string) $args[1] ) ) {
					$this->io->error( __( 'Give the id of the recovery point.', 'debloater' ) );

					return self::EXIT_ERROR;
				}

				$snapshot = $this->plugin->snapshots()->find( (int) $args[1] );

				if ( null === $snapshot ) {
					$this->io->error(
						sprintf(
							/* translators: %s: the id given. */
							__( 'There is no recovery point with the id %s.', 'debloater' ),
							$args[1]
						)
					);

					return self::EXIT_ERROR;
				}

				if ( 'show' === $action ) {
					$this->io->json( $snapshot->toArray() );

					return self::EXIT_OK;
				}

				if ( ! $this->confirmed( $assoc_args ) ) {
					return self::EXIT_ERROR;
				}

				$this->plugin->snapshotManager()->forget( $snapshot );

				$this->io->success(
					sprintf(
						/* translators: %d: the id deleted. */
						__( 'Deleted recovery point %d. That change can no longer be undone.', 'debloater' ),
						(int) $snapshot->id
					)
				);

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * Show what Debloater is doing on this site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				$document = $this->statusDocument();

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $document );

					return self::EXIT_OK;
				}

				$this->io->line(
					sprintf(
						/* translators: 1: plugin version, 2: registry hash. */
						__( 'Debloater %1$s, registry %2$s', 'debloater' ),
						$document['plugin_version'],
						substr( (string) $document['registry_hash'], 0, 12 )
					)
				);

				$this->io->line(
					sprintf(
						/* translators: %d: number of selected changes. */
						_n( '%d change selected', '%d changes selected', (int) $document['selection_count'], 'debloater' ),
						(int) $document['selection_count']
					)
				);

				/** @var array<string,mixed> $runtime */
				$runtime = $document['runtime'];

				$this->io->line(
					$runtime['present']
						? sprintf(
							/* translators: 1: runtime hash, 2: loader mode. */
							__( 'Runtime %1$s, loaded by the %2$s', 'debloater' ),
							substr( (string) $runtime['hash'], 0, 12 ),
							(string) ( is_array( $document['loader'] ) ? $document['loader']['mode'] : '' )
						)
						: __( 'No runtime file: nothing is being changed on the front end.', 'debloater' )
				);

				if ( ! $runtime['matches_state'] ) {
					$this->io->warning(
						__( 'The runtime file on disk is not the one Debloater generated.', 'debloater' )
					);
				}

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * Write this site's configuration out as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );

		$this->run(
			function () use ( $assoc_args ): int {
				$document = ConfigDocument::fromSite(
					$this->plugin->state(),
					$this->plugin->intentProfile(),
					$this->plugin->registry(),
					$this->plugin->context()
				);

				$path = $this->option( $assoc_args, 'file', '' );

				if ( '' === $path ) {
					$this->io->json( $document->toArray() );

					return self::EXIT_OK;
				}

				$json = Json::encode( $document->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing where the operator asked, on their own machine, from their own shell.
				if ( false === file_put_contents( $path, $json ) ) {
					$this->io->error(
						sprintf(
							/* translators: %s: file path. */
							__( 'Could not write to %s.', 'debloater' ),
							$path
						)
					);

					return self::EXIT_ERROR;
				}

				$this->io->success(
					sprintf(
						/* translators: 1: number of changes, 2: file path. */
						__( 'Wrote %1$d changes to %2$s.', 'debloater' ),
						$document->count(),
						$path
					)
				);

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * Read a configuration file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The file to read.
	 *
	 * [--apply]
	 * : Apply the configuration, rather than only checking it.
	 *
	 * [--yes]
	 * : Required with --apply.
	 *
	 * [--format=<format>]
	 * : How to print the result. `--json` is accepted as shorthand for
	 * `--format=json`, which is how WP-CLI spells it.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$this->run(
			function () use ( $args, $assoc_args ): int {
				$path = $args[0] ?? '';

				if ( '' === $path || ! is_readable( $path ) ) {
					$this->io->error(
						sprintf(
							/* translators: %s: file path. */
							__( 'Cannot read %s.', 'debloater' ),
							'' === $path ? '(no file given)' : $path
						)
					);

					return self::EXIT_ERROR;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading the file the operator named, on their own machine.
				$raw     = (string) file_get_contents( $path );
				$decoded = json_decode( $raw, true );

				if ( ! is_array( $decoded ) ) {
					$this->io->error(
						sprintf(
							/* translators: %s: file path. */
							__( '%s is not valid JSON.', 'debloater' ),
							$path
						)
					);

					return self::EXIT_ERROR;
				}

				$errors = $this->plugin->configSchema()->validate( $decoded );

				if ( array() !== $errors ) {
					$this->io->error(
						sprintf(
							/* translators: 1: file path, 2: the first problem. */
							__( '%1$s is not a Debloater configuration file: %2$s', 'debloater' ),
							$path,
							$errors[0]
						)
					);

					return self::EXIT_ERROR;
				}

				$document = ConfigDocument::fromArray( $decoded );
				$problems = $document->problems( $this->plugin->registry() );

				if ( ! $document->matchesRegistry( $this->plugin->registry() ) ) {
					$this->io->warning(
						__(
							'This file was written against a different version of the change registry. Check the plan before applying it.',
							'debloater'
						)
					);
				}

				foreach ( $problems as $tweak_id => $reason ) {
					$this->io->warning( $tweak_id . ': ' . $reason );
				}

				$usable = $document->withoutProblems( $this->plugin->registry() );

				if ( ! $this->flag( $assoc_args, 'apply' ) ) {
					if ( $this->wantsJson( $assoc_args ) ) {
						$this->io->json(
							array(
								'document' => $usable->toArray(),
								'problems' => (object) $problems,
								'applied'  => false,
							)
						);

						return self::EXIT_OK;
					}

					$this->io->success(
						sprintf(
							/* translators: %d: number of changes. */
							__( 'The file is valid and carries %d changes. Add --apply --yes to put them in place.', 'debloater' ),
							$usable->count()
						)
					);

					return self::EXIT_OK;
				}

				if ( ! $this->confirmed( $assoc_args ) ) {
					return self::EXIT_ERROR;
				}

				$result = $this->planFrom(
					array( 'tweaks' => implode( ',', array_keys( $usable->selection ) ) )
				);

				if ( null === $result ) {
					return self::EXIT_ERROR;
				}

				$this->plugin->setIntentProfile( $usable->intent );

				$applied = $this->plugin->apply( $result->plan );

				if ( $this->wantsJson( $assoc_args ) ) {
					$this->io->json( $applied->toArray() );
				} else {
					$this->printApplyResult( $applied );
				}

				return $this->exitCodeFor( $applied );
			}
		);
	}

	/**
	 * Run a command body, turning any escape into exit code 1.
	 *
	 * @param callable():int $body The command.
	 * @return void
	 */
	private function run( callable $body ): void {
		try {
			$code = $body();
		} catch ( Throwable $error ) {
			$this->io->error( $error->getMessage() );

			$code = self::EXIT_ERROR;
		}

		$this->io->halt( $code );
	}

	/**
	 * Build a plan from the options given.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @return PlanResult|null Null when the plan could not be built; the reason
	 *                         has already been printed.
	 */
	private function planFrom( array $assoc_args ): ?PlanResult {
		$tweaks = $this->option( $assoc_args, 'tweaks', '' );

		if ( '' !== $tweaks ) {
			$ids     = array_values( array_filter( array_map( 'trim', explode( ',', $tweaks ) ) ) );
			$unknown = array();

			foreach ( $ids as $tweak_id ) {
				if ( ! $this->plugin->registry()->has( $tweak_id ) ) {
					$unknown[] = $tweak_id;
				}
			}

			if ( array() !== $unknown ) {
				$this->io->error(
					sprintf(
						/* translators: %s: comma-separated tweak ids. */
						__( 'No such change: %s', 'debloater' ),
						implode( ', ', $unknown )
					)
				);

				return null;
			}

			$result = $this->plugin->previewTweaks( $ids );
		} else {
			$result = $this->plugin->preview( $this->option( $assoc_args, 'profile', 'safe' ) );
		}

		if ( null === $result ) {
			$this->io->error( __( 'There is no scan to plan from. Run `wp debloater scan` first.', 'debloater' ) );

			return null;
		}

		return $result;
	}

	/**
	 * The recovery point a rollback should use.
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return Snapshot|null Null when there is none; the reason has been printed.
	 */
	private function snapshotFor( array $args ): ?Snapshot {
		if ( isset( $args[0] ) && ctype_digit( (string) $args[0] ) ) {
			$snapshot = $this->plugin->snapshots()->find( (int) $args[0] );

			if ( null === $snapshot ) {
				$this->io->error(
					sprintf(
						/* translators: %s: the id given. */
						__( 'There is no recovery point with the id %s.', 'debloater' ),
						$args[0]
					)
				);

				return null;
			}

			return $snapshot;
		}

		$snapshot = $this->plugin->snapshots()->latestRestorable( \Debloater\Contracts\SnapshotLevel::A );

		if ( null === $snapshot ) {
			$this->io->error( __( 'There is nothing to roll back to.', 'debloater' ) );

			return null;
		}

		return $snapshot;
	}

	/**
	 * List the recovery points.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @return int
	 */
	private function listSnapshots( array $assoc_args ): int {
		$snapshots = $this->plugin->snapshots()->recent( 50 );

		if ( $this->wantsJson( $assoc_args ) ) {
			$this->io->json(
				array(
					'count'     => count( $snapshots ),
					'snapshots' => array_map(
						static fn ( Snapshot $snapshot ): array => $snapshot->toArray(),
						$snapshots
					),
				)
			);

			return self::EXIT_OK;
		}

		if ( array() === $snapshots ) {
			$this->io->line( __( 'There are no recovery points yet.', 'debloater' ) );

			return self::EXIT_OK;
		}

		$rows = array();

		foreach ( $snapshots as $snapshot ) {
			$rows[] = array(
				'id'      => (int) $snapshot->id,
				'run'     => $snapshot->run_id,
				'level'   => $snapshot->level->value,
				'status'  => $snapshot->status->value,
				'items'   => $snapshot->items_count,
				'created' => $snapshot->created_at,
			);
		}

		$this->io->table( $rows, array( 'id', 'run', 'level', 'status', 'items', 'created' ) );

		return self::EXIT_OK;
	}

	/**
	 * The status document, shared by the JSON and the human output.
	 *
	 * @return array<string,mixed>
	 */
	private function statusDocument(): array {
		$state  = $this->plugin->state();
		$writer = $this->plugin->runtimeWriter();
		$loader = $this->plugin->runtimeLoader();
		$lock   = new Lock();
		$run    = $this->plugin->latestScan();
		$actual = $writer->actualHash();
		$states = array();

		foreach ( $state->tweakStates() as $tweak_id => $tweak_state ) {
			$states[ $tweak_id ] = $tweak_state->value;
		}

		return array(
			'plugin_version'  => $this->plugin->context()->plugin_version,
			'registry_hash'   => $this->plugin->registry()->hash(),
			'selection'       => array_keys( $state->selection() ),
			'selection_count' => count( $state->selection() ),
			'tweak_states'    => (object) $states,
			'runtime'         => array(
				'present'       => '' !== $actual,
				'hash'          => $actual,
				'intact'        => $writer->isIntact(),
				'matches_state' => '' === $state->runtimeHash() ? '' === $actual : hash_equals( $state->runtimeHash(), $actual ),
			),
			'loader'          => array(
				'mode'       => $loader->mode(),
				'installed'  => $loader->isInstalled(),
				'up_to_date' => $loader->isUpToDate(),
			),
			'last_scan'       => null === $run
				? null
				: array(
					'run_id'   => (int) $run->id,
					'at'       => $run->started_at,
					'findings' => count( $this->plugin->findingsOf( $run ) ),
				),
			'lock'            => array(
				'held'   => $lock->isHeld(),
				'holder' => $lock->heldBy(),
			),
		);
	}

	/**
	 * Print a plan for a person to read.
	 *
	 * @param PlanResult $result The plan.
	 * @return void
	 */
	private function printPlan( PlanResult $result ): void {
		if ( $result->plan->isEmpty() ) {
			$this->io->line( __( 'Nothing would change.', 'debloater' ) );
		} else {
			$this->io->line( __( 'This would change:', 'debloater' ) );

			foreach ( $result->plan->will_change as $line ) {
				$this->io->line( '  · ' . $line );
			}
		}

		if ( array() !== $result->plan->will_not ) {
			$this->io->line( __( 'This would not change:', 'debloater' ) );

			foreach ( $result->plan->will_not as $line ) {
				$this->io->line( '  · ' . $line );
			}
		}

		if ( $result->plan->destructive ) {
			$this->io->warning( __( 'This plan deletes data. A full recovery point is taken first.', 'debloater' ) );
		}
	}

	/**
	 * Print what an apply did.
	 *
	 * @param ApplyResult $result The result.
	 * @return void
	 */
	private function printApplyResult( ApplyResult $result ): void {
		if ( RunState::COMMITTED === $result->state ) {
			$this->io->success(
				sprintf(
					/* translators: %d: number of changes applied. */
					_n( 'Applied %d change.', 'Applied %d changes.', count( $result->applied ), 'debloater' ),
					count( $result->applied )
				)
			);

			foreach ( $result->applied as $tweak_id ) {
				$this->io->line( '  · ' . $tweak_id );
			}

			foreach ( $result->warnings as $warning ) {
				$this->io->warning( $warning );
			}

			return;
		}

		$this->io->error( (string) $result->error );
	}

	/**
	 * Print a verification for a person to read.
	 *
	 * @param VerificationResult $result The verification.
	 * @return void
	 */
	private function printVerification( VerificationResult $result ): void {
		$rows = array();

		foreach ( $result->probes as $probe ) {
			$rows[] = array(
				'check'  => $probe->probe,
				'status' => $probe->status->value,
				'detail' => $probe->message,
			);
		}

		$this->io->table( $rows, array( 'check', 'status', 'detail' ) );

		if ( $result->isFailure() ) {
			$this->io->error( __( 'The site did not pass its checks.', 'debloater' ) );

			return;
		}

		if ( ProbeStatus::PASS === $result->status ) {
			$this->io->success( __( 'Everything checked out.', 'debloater' ) );

			return;
		}

		$this->io->warning( __( 'The site works, but some checks could not be completed.', 'debloater' ) );
	}

	/**
	 * Print the headline score.
	 *
	 * @param array<string,mixed> $score Score payload.
	 * @return void
	 */
	private function printScore( array $score ): void {
		if ( ! isset( $score['headline'] ) ) {
			return;
		}

		$this->io->line(
			sprintf(
				/* translators: %s: the score out of 100. */
				__( 'Debloat score: %s / 100', 'debloater' ),
				(string) $score['headline']
			)
		);
	}

	/**
	 * The exit code an apply result deserves.
	 *
	 * @param ApplyResult $result The result.
	 * @return int
	 */
	private function exitCodeFor( ApplyResult $result ): int {
		if ( RunState::ROLLED_BACK === $result->state ) {
			return self::EXIT_ROLLED_BACK;
		}

		if ( RunState::COMMITTED !== $result->state ) {
			return self::EXIT_ERROR;
		}

		return array() === $result->warnings ? self::EXIT_OK : self::EXIT_WARNINGS;
	}

	/**
	 * Whether the operator confirmed a change to the site.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @return bool
	 */
	private function confirmed( array $assoc_args ): bool {
		if ( $this->flag( $assoc_args, 'yes' ) ) {
			return true;
		}

		$this->io->error( __( 'This changes the site. Add --yes to confirm.', 'debloater' ) );

		return false;
	}

	/**
	 * A flag's value.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @param string               $name       Flag name.
	 * @return bool
	 */
	private function flag( array $assoc_args, string $name ): bool {
		return (bool) ( $assoc_args[ $name ] ?? false );
	}

	/**
	 * Whether the caller asked for JSON.
	 *
	 * WP-CLI treats `--json` as shorthand for `--format=json` and rewrites it
	 * before a command ever sees it — a command that declares `--json` in its
	 * own synopsis is told "unknown --format parameter" the moment somebody uses
	 * it. So `--format` is what the synopsis declares, and `--json` keeps
	 * working because WP-CLI turns it into exactly that.
	 *
	 * The boolean is still honoured for callers that construct the command
	 * directly, which is how the tests drive it.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @return bool
	 */
	private function wantsJson( array $assoc_args ): bool {
		return 'json' === $this->option( $assoc_args, 'format', 'table' )
			|| $this->flag( $assoc_args, 'json' );
	}

	/**
	 * An option's value.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @param string               $name       Option name.
	 * @param string               $fallback   Value when absent.
	 * @return string
	 */
	private function option( array $assoc_args, string $name, string $fallback ): string {
		$value = $assoc_args[ $name ] ?? $fallback;

		return is_string( $value ) ? $value : $fallback;
	}
}
