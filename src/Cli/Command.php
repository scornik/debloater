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
use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
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
						__( 'Scanned the site: %1$d facts, %2$d findings.', 'hakeemify-debloater' ),
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
					$this->io->error( __( 'There is no scan to read. Run `wp debloater scan` first.', 'hakeemify-debloater' ) );

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
								__( '"%s" is not a risk level. Use low, medium or high.', 'hakeemify-debloater' ),
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
					$this->io->line( __( 'Nothing to report.', 'hakeemify-debloater' ) );

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
					$this->io->warning( __( 'There is nothing to apply: the plan is empty.', 'hakeemify-debloater' ) );

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
		$this->io->line( __( 'The end-to-end suite is part of the Debloater repository and is not shipped with the plugin.', 'hakeemify-debloater' ) );
		$this->io->line( '' );
		$this->io->line( __( 'To run it from a checkout:', 'hakeemify-debloater' ) );
		$this->io->line( '' );
		$this->io->line( '    npm install' );
		$this->io->line( '    npm run test:e2e:install     # downloads the browser, once' );
		$this->io->line( '    npx wp-env start             # WordPress with the full stack on it' );
		$this->io->line( '    npm run build                # the admin bundle the suite drives' );
		$this->io->line( '    npm run test:e2e:seed        # a product, a form and an Elementor page' );
		$this->io->line( '    npm run test:e2e' );
		$this->io->line( '' );
		$this->io->line( __( 'It also runs nightly in CI, and on a pull request labelled "e2e".', 'hakeemify-debloater' ) );
		$this->io->line( '' );
		$this->io->line( __( 'To check this site instead, run `wp debloater verify` with no flag.', 'hakeemify-debloater' ) );
	}

	/**
	 * Show the registry this build carries.
	 *
	 * There is no update check. The registry ships inside the plugin and a newer
	 * one arrives with a plugin release (D-0073).
	 *
	 * ## OPTIONS
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
						__( 'Registry %1$s, %2$d changes.', 'hakeemify-debloater' ),
						'' === $tag ? __( 'unversioned', 'hakeemify-debloater' ) : $tag,
						$registry->count()
					)
				);
				$this->io->line( sprintf( 'Hash: %s', $registry->hash() ) );

				return self::EXIT_OK;
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
					$this->io->error( __( 'Give the id of the recovery point.', 'hakeemify-debloater' ) );

					return self::EXIT_ERROR;
				}

				$snapshot = $this->plugin->snapshots()->find( (int) $args[1] );

				if ( null === $snapshot ) {
					$this->io->error(
						sprintf(
							/* translators: %s: the id given. */
							__( 'There is no recovery point with the id %s.', 'hakeemify-debloater' ),
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
						__( 'Deleted recovery point %d. That change can no longer be undone.', 'hakeemify-debloater' ),
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
						__( 'Debloater %1$s, registry %2$s', 'hakeemify-debloater' ),
						$document['plugin_version'],
						substr( (string) $document['registry_hash'], 0, 12 )
					)
				);

				$this->io->line(
					sprintf(
						/* translators: %d: number of selected changes. */
						_n( '%d change selected', '%d changes selected', (int) $document['selection_count'], 'hakeemify-debloater' ),
						(int) $document['selection_count']
					)
				);

				// What the runtime loads is the stored handler list (D-0070). This
				// used to describe a generated file — its hash, its loader, whether
				// it matched — and went on reading those keys after the file was
				// gone: three PHP warnings on every call, and "No runtime file:
				// nothing is being changed" printed on a site with changes applied.
				// Only the JSON form had been updated, and only the JSON form was
				// tested. `CliTest::test_status_speaks_about_the_runtime_that_exists`
				// runs this one.
				//
				// Stored and registered are printed separately. They were one
				// number until 0.5.0, "N handlers are loaded on every request",
				// which counted what was stored and said it had registered
				// (D-0079). WP-CLI loads plugins like any request, so what this
				// process registered is what a request registers.
				/** @var array{handlers:int,guard:string,stored:array<int,string>,registered:array<int,string>,skipped:array<int,array{class:string,file:string,reason:string}>} $runtime */
				$runtime = $document['runtime'];
				$stored  = count( $runtime['stored'] );

				if ( 0 === $stored ) {
					$this->io->line( __( 'No handlers are stored: nothing is being changed on the front end or in the admin.', 'hakeemify-debloater' ) );

					return self::EXIT_OK;
				}

				$this->io->line(
					sprintf(
						/* translators: 1: handlers registered in this request, 2: handlers stored. */
						__( '%1$d of %2$d stored handlers registered in this request.', 'hakeemify-debloater' ),
						count( $runtime['registered'] ),
						$stored
					)
				);

				if ( 'active' !== $runtime['guard'] ) {
					$this->io->warning( $this->guardMessage( $runtime['guard'] ) );
				}

				foreach ( $runtime['skipped'] as $skipped ) {
					$this->io->warning(
						sprintf(
							/* translators: 1: handler class, 2: handler file, 3: reason code. */
							__( '%1$s (%2$s) did not register: %3$s', 'hakeemify-debloater' ),
							$skipped['class'],
							$skipped['file'],
							$skipped['reason']
						)
					);
				}

				// What the registry says each change should look like, read in
				// this process after its runtime registered (D-0079).
				/** @var array<int,array{tweak:string,status:string,fact:string|null,expected:string,actual:mixed,reason:string}> $effects */
				$effects = $document['effects'];
				$by      = array_count_values( array_column( $effects, 'status' ) );

				if ( array() !== $effects ) {
					$this->io->line(
						sprintf(
							/* translators: 1: changes observed, 2: changes not observed, 3: changes no request can observe. */
							__( 'Effects: %1$d observed, %2$d not observed, %3$d cannot be observed from a request.', 'hakeemify-debloater' ),
							$by['observed'] ?? 0,
							$by['not_observed'] ?? 0,
							$by['unobservable'] ?? 0
						)
					);
				}

				foreach ( $effects as $effect ) {
					if ( 'not_observed' === $effect['status'] ) {
						$this->io->warning(
							sprintf(
								/* translators: 1: tweak id, 2: fact key, 3: value read, 4: expected value. */
								__( 'Applied but not observed: %1$s (%2$s is %3$s, expected %4$s)', 'hakeemify-debloater' ),
								$effect['tweak'],
								(string) $effect['fact'],
								(string) wp_json_encode( $effect['actual'] ),
								$effect['expected']
							)
						);
					}
				}

				return self::EXIT_OK;
			}
		);
	}

	/**
	 * Save, list, export, import and apply profiles.
	 *
	 * A profile is a named selection of changes, in a file that can be moved
	 * between sites. Importing one never applies anything: it produces a plan,
	 * and applying that plan is a separate, confirmed step (docs/DECISIONS.md
	 * D-0063, BUILD-SPEC §13 rule 8).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - list
	 *   - save
	 *   - export
	 *   - import
	 *   - apply
	 * ---
	 *
	 * [<name>]
	 * : The profile to act on, or the name to save under. Not used by `list`;
	 * for `import` this is the path to the file.
	 *
	 * [--file=<dash>]
	 * : Pass `-` to print to standard output instead of writing a file. `-` is
	 * the only value this takes and any other is refused: an export always
	 * lands in `uploads/debloater/`, and a pipe is not a file write, which is
	 * why this one is still here.
	 *
	 * [--yes]
	 * : Required by `apply`, which changes the site.
	 *
	 * [--format=<format>]
	 * : How to print. `--json` is shorthand for `--format=json`.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp debloater profile list
	 *     wp debloater profile save "Client baseline"
	 *     wp debloater profile export "Client baseline"
	 *     wp debloater profile export "Client baseline" --file=- > baseline.json
	 *     wp debloater profile import baseline.json
	 *     wp debloater profile apply "Client baseline" --yes
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function profile( array $args, array $assoc_args ): void {
		$this->run(
			function () use ( $args, $assoc_args ): int {
				$action = $args[0] ?? '';
				$name   = $args[1] ?? '';

				switch ( $action ) {
					case 'list':
						return $this->profileList( $assoc_args );

					case 'save':
						return $this->profileSave( $name );

					case 'export':
						return $this->profileExport( $name, $assoc_args );

					case 'import':
						return $this->profileImport( $name, $assoc_args );

					case 'apply':
						return $this->profileApply( $name, $assoc_args );

					default:
						$this->io->error(
							__( 'Use: profile list|save <name>|export <name>|import <file>|apply <name> --yes', 'hakeemify-debloater' )
						);

						return self::EXIT_ERROR;
				}
			}
		);
	}

	/**
	 * Every profile this site has.
	 *
	 * @param array<string,string> $assoc_args Options.
	 * @return int
	 */
	private function profileList( array $assoc_args ): int {
		$rows = array();

		foreach ( $this->profiles()->all() as $entry ) {
			$rows[] = array(
				'id'      => $entry['id'],
				'name'    => $entry['profile']->name,
				'changes' => (string) $entry['profile']->count(),
				'source'  => $entry['builtin']
					? __( 'built in', 'hakeemify-debloater' )
					: __( 'saved here', 'hakeemify-debloater' ),
			);
		}

		if ( $this->wantsJson( $assoc_args ) ) {
			$this->io->json( array( 'profiles' => $rows ) );

			return self::EXIT_OK;
		}

		$this->io->table( $rows, array( 'id', 'name', 'changes', 'source' ) );

		return self::EXIT_OK;
	}

	/**
	 * Save what this site currently has selected.
	 *
	 * @param string $name What to call it.
	 * @return int
	 */
	private function profileSave( string $name ): int {
		if ( '' === $name ) {
			$this->io->error( __( 'A profile needs a name: profile save "Client baseline"', 'hakeemify-debloater' ) );

			return self::EXIT_ERROR;
		}

		$document = ConfigDocument::fromSite(
			$this->plugin->state(),
			$this->plugin->intentProfile(),
			$this->plugin->registry(),
			$this->plugin->context()
		);

		$id = $this->profiles()->save(
			new Profile( $name, $document->selection, $document->intent, $document->registry_hash )
		);

		$this->io->success(
			sprintf(
				/* translators: 1: profile name, 2: profile id, 3: number of changes. */
				__( 'Saved "%1$s" as %2$s, with %3$d changes.', 'hakeemify-debloater' ),
				$name,
				$id,
				count( $document->selection )
			)
		);

		return self::EXIT_OK;
	}

	/**
	 * Write a profile out.
	 *
	 * @param string               $name       Profile id or name.
	 * @param array<string,string> $assoc_args Options.
	 * @return int
	 */
	private function profileExport( string $name, array $assoc_args ): int {
		$profile = $this->profileNamed( $name );

		if ( null === $profile ) {
			return self::EXIT_ERROR;
		}

		// One encoder, the profile's own. The admin screen exports through the
		// same method, which is what makes "the CLI and the UI produce the same
		// file" a fact rather than an intention.
		$json = $profile->toJson();
		$path = $this->option( $assoc_args, 'file', '' );

		// `-` is standard output, which is not a write at all, and is the
		// only value --file takes. A plugin that cannot be piped is a plugin
		// somebody writes a wrapper for.
		if ( '-' === $path ) {
			$this->io->line( rtrim( $json, "\n" ) );

			return self::EXIT_OK;
		}

		if ( '' !== $path ) {
			return $this->refuseFilePath( $path );
		}

		$path = $this->exportPath( $profile->name );

		if ( '' === $path ) {
			return self::EXIT_ERROR;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing where the operator asked, on their own machine, from their own shell.
		if ( false === file_put_contents( $path, $json ) ) {
			$this->io->error(
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not write %s.', 'hakeemify-debloater' ),
					$path
				)
			);

			return self::EXIT_ERROR;
		}

		$this->io->success(
			sprintf(
				/* translators: 1: profile name, 2: file path. */
				__( 'Wrote "%1$s" to %2$s.', 'hakeemify-debloater' ),
				$profile->name,
				$path
			)
		);

		return self::EXIT_OK;
	}

	/**
	 * Read a profile from a file and save it here.
	 *
	 * Saves it. Does not apply it, and cannot: applying is `profile apply`,
	 * which asks for confirmation of its own. A file that arrived by email must
	 * not be able to change a site by being read.
	 *
	 * @param string               $path       Path to the file.
	 * @param array<string,string> $assoc_args Options.
	 * @return int
	 */
	private function profileImport( string $path, array $assoc_args ): int {
		if ( '' === $path ) {
			$this->io->error( __( 'Which file? profile import <file>', 'hakeemify-debloater' ) );

			return self::EXIT_ERROR;
		}

		if ( ! is_readable( $path ) ) {
			$this->io->error(
				sprintf(
					/* translators: %s: file path. */
					__( 'Cannot read %s.', 'hakeemify-debloater' ),
					$path
				)
			);

			return self::EXIT_ERROR;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading the file the operator named, on their own machine.
		$profile = ProfileStore::read( (string) file_get_contents( $path ) );
		$store   = $this->profiles();

		$unknown = $profile->unknownTweaks( $this->plugin->registry() );

		if ( array() !== $unknown ) {
			// Listed, then skipped. A count would say something is wrong and
			// nothing about what; the names are what somebody needs in order to
			// decide whether it matters.
			$this->io->warning(
				sprintf(
					/* translators: %s: comma-separated tweak ids. */
					__( 'This profile names changes this site does not have, and they were left out: %s', 'hakeemify-debloater' ),
					implode( ', ', $unknown )
				)
			);

			$profile = $profile->withoutUnknownTweaks( $this->plugin->registry() );
		}

		if ( ! $profile->matchesRegistry( $this->plugin->registry() ) ) {
			$this->io->warning(
				__( 'This profile was written against a different registry, so a change may mean something slightly different now. The preview shows what it would do here.', 'hakeemify-debloater' )
			);
		}

		$id = $store->save( $profile );

		$this->io->success(
			sprintf(
				/* translators: 1: profile name, 2: profile id. */
				__( 'Imported "%1$s" as %2$s. Nothing has been applied — run `profile apply %2$s --yes` when you have read the preview.', 'hakeemify-debloater' ),
				$profile->name,
				$id
			)
		);

		if ( $this->wantsJson( $assoc_args ) ) {
			$this->io->json(
				array(
					'id'      => $id,
					'name'    => $profile->name,
					'skipped' => $unknown,
					'applied' => false,
				)
			);
		}

		return self::EXIT_OK;
	}

	/**
	 * Apply a profile's selection, through the ordinary confirmed path.
	 *
	 * The profile is resolved to a list of tweak ids and handed to the same
	 * planner, the same confirmation and the same apply that `wp debloater
	 * apply` uses. There is no shortcut here and deliberately no second code
	 * path: a profile is a way of choosing changes, not a way of applying them
	 * differently.
	 *
	 * @param string               $name       Profile id or name.
	 * @param array<string,string> $assoc_args Options.
	 * @return int
	 */
	private function profileApply( string $name, array $assoc_args ): int {
		$profile = $this->profileNamed( $name );

		if ( null === $profile ) {
			return self::EXIT_ERROR;
		}

		// Asked before anything is planned. §13 rule 8: applying is confirmed,
		// and on the command line `--yes` is that confirmation.
		if ( ! $this->confirmed( $assoc_args ) ) {
			return self::EXIT_ERROR;
		}

		// Two kinds of profile, one apply path each, and both already exist.
		//
		// A saved profile names its changes, so it plans as a list of tweak
		// ids. A built-in names a risk band instead — "everything safe that
		// this scan found" — and has no fixed list to hand over, which is why
		// `profile list` shows it as nought changes. That one plans through the
		// profile planner, exactly as `wp debloater apply --profile=safe` does.
		$store = $this->profiles();
		$ids   = array_keys( $profile->withoutUnknownTweaks( $this->plugin->registry() )->selection );

		if ( array() === $ids ) {
			$builtin = null;

			foreach ( $store->builtins() as $id => $candidate ) {
				if ( $candidate->name === $profile->name ) {
					$builtin = $id;

					break;
				}
			}

			if ( null === $builtin ) {
				$this->io->warning( __( 'That profile selects nothing this site has, so there is nothing to apply.', 'hakeemify-debloater' ) );

				return self::EXIT_OK;
			}

			$result = $this->plugin->preview( $builtin );
		} else {
			$result = $this->plugin->previewTweaks( $ids );
		}

		if ( null === $result ) {
			$this->io->error( __( 'There is no scan to plan from. Run `wp debloater scan` first.', 'hakeemify-debloater' ) );

			return self::EXIT_ERROR;
		}

		if ( $result->plan->isEmpty() ) {
			$this->io->warning( __( 'There is nothing to apply: the plan is empty.', 'hakeemify-debloater' ) );

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

	/**
	 * A profile by id or by name, with the failure already reported.
	 *
	 * By either, because a person who saved "Client baseline" thinks of it as
	 * "Client baseline" and not as `client-baseline`.
	 *
	 * @param string $name Profile id or name.
	 * @return Profile|null
	 */
	private function profileNamed( string $name ): ?Profile {
		if ( '' === $name ) {
			$this->io->error( __( 'Which profile? Run `profile list` to see them.', 'hakeemify-debloater' ) );

			return null;
		}

		$store = $this->profiles();
		$found = $store->find( $name );

		if ( null !== $found ) {
			return $found;
		}

		foreach ( $store->all() as $entry ) {
			if ( strcasecmp( $entry['profile']->name, $name ) === 0 ) {
				return $entry['profile'];
			}
		}

		$this->io->error(
			sprintf(
				/* translators: %s: the name given. */
				__( 'No profile called "%s". Run `profile list` to see them.', 'hakeemify-debloater' ),
				$name
			)
		);

		return null;
	}

	/**
	 * The profile store.
	 *
	 * @return ProfileStore
	 */
	private function profiles(): ProfileStore {
		return new ProfileStore( $this->plugin->registry() );
	}

	/**
	 * Write this site's configuration out as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<dash>]
	 * : Pass `-` to print to standard output instead of writing a file. `-` is
	 * the only value this takes and any other is refused: an export always
	 * lands in `uploads/debloater/`, and a pipe is not a file write, which is
	 * why this one is still here.
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

				if ( '-' === $path ) {
					$this->io->json( $document->toArray() );

					return self::EXIT_OK;
				}

				if ( '' !== $path ) {
					return $this->refuseFilePath( $path );
				}

				$path = $this->exportPath( 'config' );

				if ( '' === $path ) {
					return self::EXIT_ERROR;
				}

				$json = Json::encode( $document->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing where the operator asked, on their own machine, from their own shell.
				if ( false === file_put_contents( $path, $json ) ) {
					$this->io->error(
						sprintf(
							/* translators: %s: file path. */
							__( 'Could not write to %s.', 'hakeemify-debloater' ),
							$path
						)
					);

					return self::EXIT_ERROR;
				}

				$this->io->success(
					sprintf(
						/* translators: 1: number of changes, 2: file path. */
						__( 'Wrote %1$d changes to %2$s.', 'hakeemify-debloater' ),
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
							__( 'Cannot read %s.', 'hakeemify-debloater' ),
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
							__( '%s is not valid JSON.', 'hakeemify-debloater' ),
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
							__( '%1$s is not a Debloater configuration file: %2$s', 'hakeemify-debloater' ),
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
							'hakeemify-debloater'
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
							__( 'The file is valid and carries %d changes. Add --apply --yes to put them in place.', 'hakeemify-debloater' ),
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
						__( 'No such change: %s', 'hakeemify-debloater' ),
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
			$this->io->error( __( 'There is no scan to plan from. Run `wp debloater scan` first.', 'hakeemify-debloater' ) );

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
						__( 'There is no recovery point with the id %s.', 'hakeemify-debloater' ),
						$args[0]
					)
				);

				return null;
			}

			return $snapshot;
		}

		$snapshot = $this->plugin->snapshots()->latestRestorable( \Debloater\Contracts\SnapshotLevel::A );

		if ( null === $snapshot ) {
			$this->io->error( __( 'There is nothing to roll back to.', 'hakeemify-debloater' ) );

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
			$this->io->line( __( 'There are no recovery points yet.', 'hakeemify-debloater' ) );

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
	 * Where an export is written. There is only one answer.
	 *
	 * `uploads/debloater/`, created on demand and closed to the web.
	 *
	 * 0.3.0 let `--file` name any path, on the reasoning that somebody with
	 * shell access can already write anywhere the web user can. wordpress.org
	 * round 2 refused it anyway, and they are right about the thing that
	 * reasoning missed: a guideline that says "plugins write to uploads" is
	 * worth more as a rule with no exceptions than as a rule with one good one,
	 * because the next person to add an export will copy whichever pattern they
	 * find (D-0074).
	 *
	 * @param string $basename What to name the file after.
	 * @return string The path, or '' when the destination could not be prepared.
	 */
	private function exportPath( string $basename ): string {
		try {
			return ( new ExportDestination() )->resolve( $basename );
		} catch ( \RuntimeException $error ) {
			$this->io->error( esc_html( $error->getMessage() ) );

			return '';
		}
	}

	/**
	 * Say no to a path, and say where the file goes instead.
	 *
	 * A refusal rather than silently ignoring it: somebody who typed a path
	 * expects their file to be there, and writing it somewhere else without
	 * saying so is how an export goes missing.
	 *
	 * @param string $path What they asked for.
	 * @return int Exit code.
	 */
	private function refuseFilePath( string $path ): int {
		$this->io->error(
			sprintf(
				/* translators: 1: the path the operator asked for, 2: the directory exports go to. */
				__(
					'Exports cannot be written to %1$s. They go to %2$s, and `--file=-` prints to standard output.',
					'hakeemify-debloater'
				),
				esc_html( $path ),
				esc_html( 'uploads/' . ExportDestination::FOLDER . '/' )
			)
		);

		return self::EXIT_ERROR;
	}

	/**
	 * The status document, shared by the JSON and the human output.
	 *
	 * @return array<string,mixed>
	 */
	private function statusDocument(): array {
		$state  = $this->plugin->state();
		$lock   = new Lock();
		$run    = $this->plugin->latestScan();
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
			'runtime'         => $this->plugin->runtimeStatus(),
			'effects'         => $this->plugin->effectReport(),
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
	 * Why the guard registered nothing, in words.
	 *
	 * @param string $guard Guard state from `Runtime::report()`.
	 * @return string
	 */
	private function guardMessage( string $guard ): string {
		switch ( $guard ) {
			case 'disabled':
				return __( 'DEBLOATER_DISABLE is defined, so no handler registers. Every stored change is off.', 'hakeemify-debloater' );
			case 'guard_missing':
				return __( 'runtime-handlers/runtime-guard.php could not be read, so no handler registers. Every stored change is off.', 'hakeemify-debloater' );
			case 'bypassed':
				return __( 'This request asked to run without Debloater, so no handler registered.', 'hakeemify-debloater' );
			default:
				return __( 'The runtime was not loaded in this request, so what registers is not known from here.', 'hakeemify-debloater' );
		}
	}

	/**
	 * Print a plan for a person to read.
	 *
	 * @param PlanResult $result The plan.
	 * @return void
	 */
	private function printPlan( PlanResult $result ): void {
		if ( $result->plan->isEmpty() ) {
			$this->io->line( __( 'Nothing would change.', 'hakeemify-debloater' ) );
		} else {
			$this->io->line( __( 'This would change:', 'hakeemify-debloater' ) );

			foreach ( $result->plan->will_change as $line ) {
				$this->io->line( '  · ' . $line );
			}
		}

		if ( array() !== $result->plan->will_not ) {
			$this->io->line( __( 'This would not change:', 'hakeemify-debloater' ) );

			foreach ( $result->plan->will_not as $line ) {
				$this->io->line( '  · ' . $line );
			}
		}

		if ( $result->plan->destructive ) {
			$this->io->warning( __( 'This plan deletes data. A full recovery point is taken first.', 'hakeemify-debloater' ) );
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
					_n( 'Applied %d change.', 'Applied %d changes.', count( $result->applied ), 'hakeemify-debloater' ),
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
			$this->io->error( __( 'The site did not pass its checks.', 'hakeemify-debloater' ) );

			return;
		}

		if ( ProbeStatus::PASS === $result->status ) {
			$this->io->success( __( 'Everything checked out.', 'hakeemify-debloater' ) );

			return;
		}

		$this->io->warning( __( 'The site works, but some checks could not be completed.', 'hakeemify-debloater' ) );
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
				__( 'Debloat score: %s / 100', 'hakeemify-debloater' ),
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

		$this->io->error( __( 'This changes the site. Add --yes to confirm.', 'hakeemify-debloater' ) );

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
