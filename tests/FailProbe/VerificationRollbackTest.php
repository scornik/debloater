<?php
/**
 * What happens when the site does not pass its checks.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\FailProbe;

use Debloater\Apply\Lock;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\ProbeStatus;
use Debloater\Contracts\RunState;
use Debloater\Contracts\TweakState;
use Debloater\Verify\Verifier;

/**
 * BUILD-SPEC §11 and §9.2: a FAIL rolls the run back, without being asked.
 *
 * This is the promise the whole plugin rests on — that a change which breaks
 * the site undoes itself — so it is tested end to end rather than by asserting
 * that a method was called. The run really applies, really fails verification,
 * really rolls back, and the assertions compare the runtime bytes and the
 * stored selection against what was there before.
 *
 * The failure is produced by `DEBLOATER_TEST_FAIL_PROBE`, defined in this
 * suite's bootstrap. Breaking the site for real would prove the same thing and
 * leave nothing to compare against.
 */
final class VerificationRollbackTest extends FailProbeTestCase {

	/**
	 * Prepare the tables and clear any lock.
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
	 * Release the lock and stop intercepting HTTP.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		( new Lock() )->forceRelease();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The constant is in force for this process.
	 *
	 * @return void
	 */
	public function test_the_forced_failure_constant_is_defined(): void {
		$this->assertTrue( defined( Verifier::TEST_FAIL_CONSTANT ) );
		$this->assertSame( 'rest', constant( Verifier::TEST_FAIL_CONSTANT ) );
	}

	/**
	 * The named probe fails, and only the named probe.
	 *
	 * @return void
	 */
	public function test_only_the_named_probe_is_forced_to_fail(): void {
		$result = $this->plugin->verifier()->verify();
		$rest   = null;

		foreach ( $result->probes as $probe ) {
			if ( 'rest' === $probe->probe ) {
				$rest = $probe;

				continue;
			}

			$this->assertNotSame(
				ProbeStatus::FAIL,
				$probe->status,
				sprintf( '%s should not have been forced to fail.', $probe->probe )
			);
		}

		$this->assertNotNull( $rest );
		$this->assertSame( ProbeStatus::FAIL, $rest->status );
		$this->assertTrue( (bool) $rest->evidence['forced'] );
		$this->assertSame( ProbeStatus::FAIL, $result->status );
	}

	/**
	 * An apply that fails verification is rolled back automatically, and the
	 * site is returned to exactly what it was.
	 *
	 * @return void
	 */
	public function test_a_failed_verification_rolls_the_apply_back_to_the_byte(): void {
		$before_hash      = $this->selectAndGenerate( array( 'core.remove_jquery_migrate' => array() ) );
		$before_selection = $this->plugin->state()->selection();
		$before_runtime   = (string) file_get_contents( $this->context()->runtimeFile() );

		$this->assertNotSame( '', $before_hash );

		$result = $this->plugin->apply( $this->safePlan() );

		$this->assertSame( RunState::ROLLED_BACK, $result->state );
		$this->assertSame( array(), $result->applied, 'A rolled-back run applied nothing in the end.' );
		$this->assertStringContainsString( 'did not pass its checks', (string) $result->error );
		$this->assertStringContainsString( 'forced to fail', (string) $result->error );

		$this->assertNotNull( $result->verification );
		$this->assertSame( ProbeStatus::FAIL, $result->verification->status );

		$this->assertSame(
			$before_runtime,
			(string) file_get_contents( $this->context()->runtimeFile() ),
			'The runtime must be byte-identical to what was there before the apply.'
		);

		$this->assertSame( $before_hash, $this->plugin->state()->runtimeHash() );
		$this->assertSame( $before_selection, $this->plugin->state()->selection() );

		$history = $this->historyOf( $result->run_id );

		$this->assertContains( RunState::VERIFICATION_FAILED->value, $history );
		$this->assertContains( RunState::ROLLING_BACK->value, $history );
		$this->assertContains( RunState::ROLLED_BACK->value, $history );
		$this->assertNotContains( RunState::COMMITTED->value, $history );

		$this->assertNull( ( new Lock() )->heldBy(), 'A rolled-back run must release the lock.' );
	}

	/**
	 * Every tweak in the failed run is recorded as rolled back, by a route the
	 * lifecycle table allows.
	 *
	 * @return void
	 */
	public function test_the_tweaks_are_journalled_as_rolled_back(): void {
		$plan   = $this->safePlan();
		$result = $this->plugin->apply( $plan );

		$this->assertSame( RunState::ROLLED_BACK, $result->state );

		$states = $this->plugin->state()->tweakStates();

		foreach ( $plan->tweakIds() as $tweak_id ) {
			$this->assertSame(
				TweakState::ROLLED_BACK,
				$states[ $tweak_id ] ?? null,
				sprintf( '%s should be recorded as rolled back.', $tweak_id )
			);
		}

		$seen = array();

		foreach ( $this->plugin->journal()->forRun( $result->run_id ) as $entry ) {
			$from = TweakState::from( (string) $entry['from_state'] );
			$to   = TweakState::from( (string) $entry['to_state'] );

			$this->assertTrue(
				$from === $to || $from->canTransitionTo( $to ),
				sprintf( 'The journal records %s -> %s, which the table does not allow.', $from->value, $to->value )
			);

			$seen[] = $from->value . '->' . $to->value;
		}

		$this->assertContains( 'APPLIED->VERIFICATION_FAILED', $seen );
		$this->assertContains( 'VERIFICATION_FAILED->ROLLED_BACK', $seen );
	}

	/**
	 * Nothing is left holding the lock, so the next apply can proceed.
	 *
	 * @return void
	 */
	public function test_a_second_apply_can_still_run_afterwards(): void {
		$this->plugin->apply( $this->safePlan() );

		$second = $this->plugin->apply( $this->safePlan() );

		$this->assertSame(
			RunState::ROLLED_BACK,
			$second->state,
			'It should fail the same way, not be refused because the lock was left behind.'
		);
	}

	/**
	 * The states a run passed through.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,string>
	 */
	private function historyOf( int $run_id ): array {
		$run     = $this->plugin->runs()->find( $run_id );
		$history = $run->payload['history'] ?? array();

		return is_array( $history ) ? array_values( array_filter( $history, 'is_string' ) ) : array();
	}

	/**
	 * The safe plan for this site.
	 *
	 * @return PreviewPlan
	 */
	private function safePlan(): PreviewPlan {
		$this->plugin->scan();

		$plan = $this->plugin->preview();

		$this->assertNotNull( $plan, 'A scan should always produce a plan to preview.' );
		$this->assertFalse( $plan->plan->isEmpty(), 'The safe plan on a default install should not be empty.' );

		return $plan->plan;
	}
}
