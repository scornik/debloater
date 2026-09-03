<?php
/**
 * Runs the scanners and records the result.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan;

use Throwable;
use Debloater\Contracts\Context;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Run;
use Debloater\Contracts\RunType;
use Debloater\Contracts\ScannerInterface;
use Debloater\Scan\Scanners\AbstractScanner;
use Debloater\Storage\Repositories\RunRepository;

/**
 * Drives a scan (BUILD-SPEC §17 Phase 2).
 *
 * Three properties are worth naming.
 *
 * **The budget is soft.** Each scanner has a two-second target, and the elapsed
 * time is measured and recorded. Nothing is interrupted: PHP cannot be preempted
 * safely, and a scanner killed halfway through would leave facts that look
 * complete but are not. An over-budget scanner produces a diagnostic fact
 * instead, so a slow site is diagnosed rather than silently truncated.
 *
 * **One failing scanner does not fail the scan.** A scanner that throws is
 * recorded by name in `scan.failed` and the rest continue. A site with one
 * unreadable table should still get findings about everything else — and the
 * failure is visible rather than presented as an absence of facts.
 *
 * **Keys are owned.** `FactSet::withNamespaced()` stops a scanner writing
 * outside its own namespace, and this class additionally refuses two scanners
 * writing the same key, so a namespace shared by two scanners (as `wp` and `db`
 * are) cannot turn into one silently overwriting the other.
 */
final class ScanRunner {

	/**
	 * Soft time budget per scanner, in milliseconds.
	 */
	public const BUDGET_MS = 2000;

	/**
	 * The namespace this runner owns for its own diagnostics.
	 */
	public const DIAGNOSTIC_NAMESPACE = 'scan';

	/**
	 * Scanners to run, in order.
	 *
	 * @var array<int,ScannerInterface>
	 */
	private array $scanners;

	/**
	 * Repository runs are recorded in.
	 *
	 * @var RunRepository
	 */
	private RunRepository $runs;

	/**
	 * Constructor.
	 *
	 * @param array<int,ScannerInterface> $scanners Scanners to run.
	 * @param RunRepository               $runs     Run repository.
	 */
	public function __construct( array $scanners, RunRepository $runs ) {
		$this->scanners = array_values( $scanners );
		$this->runs     = $runs;
	}

	/**
	 * Collect facts without recording a run.
	 *
	 * @param Context $context Site context.
	 * @return ScanResult
	 */
	public function collect( Context $context ): ScanResult {
		$facts   = new FactSet();
		$owners  = array();
		$timings = array();
		$slow    = array();
		$failed  = array();
		$started = microtime( true );

		foreach ( $this->scanners as $scanner ) {
			$name  = $this->name( $scanner );
			$begin = microtime( true );

			try {
				if ( $scanner instanceof AbstractScanner ) {
					// Scanners that cache anything across a scan are told to
					// forget it first, so no run is answered with an
					// observation from a previous one.
					$scanner->reset();
				}

				$produced = $scanner->scan( $context, new FactSet() );

				$this->assertNoOverlap( $name, $produced, $owners );

				$facts = $facts->withAll( iterator_to_array( $produced, false ) );
			} catch ( Throwable $error ) {
				$failed[ $name ] = $this->describe( $error );
			}

			$elapsed = (int) round( ( microtime( true ) - $begin ) * 1000 );

			$timings[ $name ] = $elapsed;

			if ( $elapsed > self::BUDGET_MS ) {
				$slow[] = $name;
			}
		}

		$total = (int) round( ( microtime( true ) - $started ) * 1000 );

		sort( $slow, SORT_STRING );
		ksort( $timings, SORT_STRING );
		ksort( $failed, SORT_STRING );

		$facts = $facts->withNamespaced(
			self::DIAGNOSTIC_NAMESPACE,
			array(
				'scan.elapsed_ms'  => $total,
				'scan.scanner_ms'  => $timings,
				'scan.over_budget' => $slow,
				'scan.failed'      => array_keys( $failed ),
			)
		);

		return new ScanResult( $facts, $timings, $slow, $failed, $total );
	}

	/**
	 * Run a scan and record it.
	 *
	 * @param Context $context       Site context.
	 * @param string  $registry_hash Hash of the registry in force.
	 * @return Run The persisted run.
	 */
	public function run( Context $context, string $registry_hash ): Run {
		$started = gmdate( 'Y-m-d H:i:s' );
		$result  = $this->collect( $context );

		$run = new Run(
			null,
			RunType::SCAN,
			$result->isClean() ? 'COMPLETE' : 'COMPLETE_WITH_WARNINGS',
			$context->actor,
			$started,
			gmdate( 'Y-m-d H:i:s' ),
			$context->plugin_version,
			$registry_hash,
			array(
				'facts'       => $result->facts->toArray(),
				'diagnostics' => $result->diagnostics(),
			),
			$result->errorSummary()
		);

		return $this->runs->insert( $run );
	}

	/**
	 * The scanners this runner will use.
	 *
	 * @return array<int,ScannerInterface>
	 */
	public function scanners(): array {
		return $this->scanners;
	}

	/**
	 * Refuse a second scanner writing a key another already owns.
	 *
	 * @param string             $scanner Scanner name.
	 * @param FactSet            $facts   Facts the scanner produced.
	 * @param array<string,string> $owners Key to owning scanner, by reference.
	 * @return void
	 * @throws \RuntimeException When two scanners write the same key.
	 */
	private function assertNoOverlap( string $scanner, FactSet $facts, array &$owners ): void {
		foreach ( $facts->keys() as $key ) {
			if ( array_key_exists( $key, $owners ) ) {
				throw new \RuntimeException(
					sprintf(
						'Fact "%s" is written by both %s and %s. Each fact must have exactly one owner.',
						$key,
						$owners[ $key ],
						$scanner
					)
				);
			}

			$owners[ $key ] = $scanner;
		}
	}

	/**
	 * A short name for a scanner, for diagnostics.
	 *
	 * @param ScannerInterface $scanner Scanner.
	 * @return string
	 */
	private function name( ScannerInterface $scanner ): string {
		$parts = explode( '\\', get_class( $scanner ) );

		return (string) end( $parts );
	}

	/**
	 * A safe description of a failure.
	 *
	 * The message is kept and the stack trace is not: a scan payload is shown in
	 * the admin and may be exported, and a trace carries absolute paths that
	 * belong in a log rather than in a report.
	 *
	 * @param Throwable $error The failure.
	 * @return string
	 */
	private function describe( Throwable $error ): string {
		return sprintf( '%s: %s', get_class( $error ), $error->getMessage() );
	}
}
