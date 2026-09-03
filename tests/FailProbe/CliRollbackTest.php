<?php
/**
 * What the CLI reports when a change has to be undone.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\FailProbe;

use Debloater\Apply\Lock;
use Debloater\Cli\Command;
use Debloater\Tests\Integration\Support\RecordingIo;

/**
 * BUILD-SPEC §17 Phase 7: exit code 2 means "verification failed and the change
 * was rolled back".
 *
 * The code matters more than the message. A deployment script that runs
 * `wp debloater apply --profile=safe --yes` needs to be able to tell "applied" from
 * "applied and then undone" without reading English prose, and 2 is how it is
 * told.
 */
final class CliRollbackTest extends FailProbeTestCase {

	/**
	 * Prepare the tables and act as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->plugin->resetServices();
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		( new Lock() )->forceRelease();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * `apply` exits 2 when the site fails its checks, and the site is back to
	 * what it was.
	 *
	 * @return void
	 */
	public function test_apply_exits_two_when_the_change_is_rolled_back(): void {
		$this->plugin->scan();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->apply(
			array(),
			array(
				'profile' => 'safe',
				'yes'     => true,
			) 
		);

		$this->assertSame( Command::EXIT_ROLLED_BACK, $io->code, $io->output() );
		$this->assertNotSame( array(), $io->errors, 'A rolled-back apply must say why.' );
		$this->assertStringContainsString( 'did not pass its checks', $io->output() );
		$this->assertSame( array(), $this->plugin->state()->selection() );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
	}

	/**
	 * `verify` on its own exits 2 when a check fails, without changing
	 * anything.
	 *
	 * @return void
	 */
	public function test_verify_exits_two_when_a_check_fails(): void {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->verify( array(), array( 'json' => true ) );

		$this->assertSame( Command::EXIT_ROLLED_BACK, $io->code );
		$this->assertSame( 'FAIL', $io->lastDocument()['status'] );
	}

	/**
	 * The JSON form of a rolled-back apply carries the verification, so a
	 * script can report which check failed.
	 *
	 * @return void
	 */
	public function test_the_json_result_carries_the_failed_check(): void {
		$this->plugin->scan();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->apply(
			array(),
			array(
				'profile' => 'safe',
				'yes'     => true,
				'json'    => true,
			)
		);

		$document = $io->lastDocument();

		$this->assertSame( Command::EXIT_ROLLED_BACK, $io->code );
		$this->assertSame( 'ROLLED_BACK', $document['state'] );
		$this->assertSame( 'FAIL', $document['verification']['status'] );

		$failed = array();

		foreach ( $document['verification']['probes'] as $probe ) {
			if ( 'FAIL' === $probe['status'] ) {
				$failed[] = $probe['probe'];
			}
		}

		$this->assertSame( array( 'rest' ), $failed );
	}
}
