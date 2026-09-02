<?php
/**
 * Tests for the scan runner.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Scan;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\Fact;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\ScannerInterface;
use WPDebloat\Scan\ScanRunner;
use WPDebloat\Storage\Repositories\RunRepository;
use WPDebloat\Tests\Unit\Support\Build;

/**
 * The runner's job is to collect facts without letting one scanner ruin the
 * scan, and to be honest about what went wrong when something did.
 */
final class ScanRunnerTest extends TestCase {

	/**
	 * Facts from every scanner end up in one set.
	 *
	 * @return void
	 */
	public function test_facts_from_every_scanner_are_merged(): void {
		$result = $this->runner(
			array(
				$this->scanner( 'wp', array( 'wp.debug' => true ) ),
				$this->scanner( 'db', array( 'db.size_bytes' => 1024 ) ),
			)
		)->collect( Build::context() );

		$this->assertTrue( $result->facts->value( 'wp.debug' ) );
		$this->assertSame( 1024, $result->facts->value( 'db.size_bytes' ) );
		$this->assertTrue( $result->isClean() );
	}

	/**
	 * Two scanners may share a namespace, as `wp` and `db` do, as long as their
	 * key sets do not overlap.
	 *
	 * @return void
	 */
	public function test_two_scanners_may_share_a_namespace(): void {
		$result = $this->runner(
			array(
				$this->scanner( 'wp', array( 'wp.debug' => true ) ),
				$this->scanner( 'wp', array( 'wp.shortlink' => false ) ),
			)
		)->collect( Build::context() );

		$this->assertTrue( $result->facts->value( 'wp.debug' ) );
		$this->assertFalse( $result->facts->value( 'wp.shortlink' ) );
		$this->assertTrue( $result->isClean() );
	}

	/**
	 * Two scanners writing the same key is a programming error, not something to
	 * resolve by letting the last one win.
	 *
	 * @return void
	 */
	public function test_two_scanners_cannot_own_the_same_fact(): void {
		$result = $this->runner(
			array(
				$this->scanner( 'wp', array( 'wp.debug' => true ) ),
				$this->scanner( 'wp', array( 'wp.debug' => false ) ),
			)
		)->collect( Build::context() );

		$this->assertArrayHasKey( 'ScanRunnerTest_Scanner', $result->failed );
		$this->assertStringContainsString( 'exactly one owner', implode( ' ', $result->failed ) );
	}

	/**
	 * A scanner that throws does not take the scan with it, and the failure is
	 * recorded rather than showing up as an absence of facts.
	 *
	 * @return void
	 */
	public function test_a_failing_scanner_does_not_stop_the_scan(): void {
		$result = $this->runner(
			array(
				$this->failingScanner( 'db', 'the options table is unreadable' ),
				$this->scanner( 'wp', array( 'wp.debug' => true ) ),
			)
		)->collect( Build::context() );

		$this->assertTrue( $result->facts->value( 'wp.debug' ), 'the healthy scanner must still have run' );
		$this->assertFalse( $result->isClean() );
		$this->assertStringContainsString( 'the options table is unreadable', (string) $result->errorSummary() );
	}

	/**
	 * A failure is summarised without a stack trace: the payload is shown in the
	 * admin and may be exported, and a trace carries absolute paths.
	 *
	 * @return void
	 */
	public function test_failures_do_not_leak_a_stack_trace(): void {
		$result = $this->runner( array( $this->failingScanner( 'db', 'boom' ) ) )->collect( Build::context() );

		$summary = (string) $result->errorSummary();

		$this->assertStringNotContainsString( '.php', $summary );
		$this->assertStringNotContainsString( '#0', $summary );
	}

	/**
	 * Every scan records how long it took, per scanner and in total.
	 *
	 * @return void
	 */
	public function test_timings_are_recorded_for_every_scanner(): void {
		$result = $this->runner( array( $this->scanner( 'wp', array( 'wp.debug' => true ) ) ) )->collect( Build::context() );

		$this->assertArrayHasKey( 'ScanRunnerTest_Scanner', $result->timings );
		$this->assertGreaterThanOrEqual( 0, $result->elapsed_ms );
		$this->assertSame( $result->timings, $result->facts->value( 'scan.scanner_ms' ) );
		$this->assertSame( $result->elapsed_ms, $result->facts->value( 'scan.elapsed_ms' ) );
	}

	/**
	 * The diagnostics travel with the facts, so an incomplete scan is visible to
	 * whatever reads the payload later.
	 *
	 * @return void
	 */
	public function test_diagnostics_are_written_as_facts(): void {
		$result = $this->runner( array( $this->failingScanner( 'db', 'boom' ) ) )->collect( Build::context() );

		$this->assertSame( array( 'ScanRunnerTest_FailingScanner' ), $result->facts->value( 'scan.failed' ) );
		$this->assertSame( array(), $result->facts->value( 'scan.over_budget' ) );
	}

	/**
	 * BUILD-SPEC §17 Phase 2: the budget is soft. A slow scanner is recorded as
	 * over budget and its facts are kept — interrupting it would leave a fact
	 * set that looks complete and is not.
	 *
	 * @return void
	 */
	public function test_an_over_budget_scanner_is_recorded_not_interrupted(): void {
		$slow = new class() implements ScannerInterface {

			/**
			 * The namespace this scanner owns.
			 *
			 * @return string
			 */
			public function namespaceName(): string {
				return 'wp';
			}

			/**
			 * Take longer than the budget allows, then report normally.
			 *
			 * @param Context $context Site context.
			 * @param FactSet $facts   Facts so far.
			 * @return FactSet
			 */
			public function scan( Context $context, FactSet $facts ): FactSet {
				unset( $context );

				usleep( ( ScanRunner::BUDGET_MS + 20 ) * 1000 );

				return $facts->with( new Fact( 'wp.debug', true ) );
			}
		};

		$result = $this->runner( array( $slow ) )->collect( Build::context() );

		$this->assertTrue( $result->facts->value( 'wp.debug' ), 'an over-budget scanner still contributes its facts' );
		$this->assertNotSame( array(), $result->facts->value( 'scan.over_budget' ) );
		$this->assertFalse( $result->isClean() );
	}

	/**
	 * With no scanners at all, the scan is clean and reports only diagnostics.
	 *
	 * @return void
	 */
	public function test_an_empty_scan_is_clean(): void {
		$result = $this->runner( array() )->collect( Build::context() );

		$this->assertTrue( $result->isClean() );
		$this->assertNull( $result->errorSummary() );
		$this->assertSame(
			array( 'scan.elapsed_ms', 'scan.failed', 'scan.over_budget', 'scan.scanner_ms' ),
			$result->facts->keys()
		);
	}

	/**
	 * A runner over the given scanners, with a repository that is never reached
	 * by collect().
	 *
	 * @param array<int,ScannerInterface> $scanners Scanners.
	 * @return ScanRunner
	 */
	private function runner( array $scanners ): ScanRunner {
		return new ScanRunner( $scanners, new RunRepository() );
	}

	/**
	 * A scanner that reports fixed facts.
	 *
	 * @param string              $namespace_name Namespace it owns.
	 * @param array<string,mixed> $facts          Facts to report.
	 * @return ScannerInterface
	 */
	private function scanner( string $namespace_name, array $facts ): ScannerInterface {
		return new ScanRunnerTest_Scanner( $namespace_name, $facts );
	}

	/**
	 * A scanner that throws.
	 *
	 * @param string $namespace_name Namespace it owns.
	 * @param string $message        Failure message.
	 * @return ScannerInterface
	 */
	private function failingScanner( string $namespace_name, string $message ): ScannerInterface {
		return new ScanRunnerTest_FailingScanner( $namespace_name, $message );
	}
}

/**
 * A scanner reporting fixed facts.
 *
 * Named rather than anonymous so the runner's diagnostics have a name to
 * report, which is what the assertions above check.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles for the class above; keeping them here makes the test readable.
final class ScanRunnerTest_Scanner implements ScannerInterface {

	/**
	 * Namespace this scanner owns.
	 *
	 * @var string
	 */
	private string $namespace_name;

	/**
	 * Facts to report.
	 *
	 * @var array<string,mixed>
	 */
	private array $facts;

	/**
	 * Constructor.
	 *
	 * @param string              $namespace_name Namespace.
	 * @param array<string,mixed> $facts          Facts to report.
	 */
	public function __construct( string $namespace_name, array $facts ) {
		$this->namespace_name = $namespace_name;
		$this->facts          = $facts;
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return $this->namespace_name;
	}

	/**
	 * Report the fixed facts.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts so far.
	 * @return FactSet
	 */
	public function scan( Context $context, FactSet $facts ): FactSet {
		unset( $context );

		return $facts->withNamespaced( $this->namespace_name, $this->facts );
	}
}

/**
 * A scanner that always throws.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- As above.
final class ScanRunnerTest_FailingScanner implements ScannerInterface {

	/**
	 * Namespace this scanner owns.
	 *
	 * @var string
	 */
	private string $namespace_name;

	/**
	 * Failure message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $namespace_name Namespace.
	 * @param string $message        Failure message.
	 */
	public function __construct( string $namespace_name, string $message ) {
		$this->namespace_name = $namespace_name;
		$this->message        = $message;
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return $this->namespace_name;
	}

	/**
	 * Always throw.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts so far.
	 * @return FactSet
	 * @throws RuntimeException Always.
	 */
	public function scan( Context $context, FactSet $facts ): FactSet {
		unset( $context, $facts );

		throw new RuntimeException( $this->message );
	}
}
