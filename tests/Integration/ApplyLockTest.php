<?php
/**
 * The lock, and the deadlock it used to be able to cause.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Apply\Lock;

/**
 * BUILD-SPEC §8.
 *
 * Reported from a live site: every apply refused with "Another change is
 * already in progress on this site. Wait for it to finish and try again." The
 * last committed run had finished twenty-five minutes earlier, and the lock's
 * TTL is sixty seconds.
 *
 * It was a deadlock with two halves, and neither is a bug on its own.
 *
 * `Lock` stored itself as a WordPress transient, which is two options: the
 * value and a separate `_transient_timeout_` row. `acquire()` wrote them with
 * two `add_option()` calls, and `get_transient()` treats a value with no
 * timeout as one that **never expires**. A request that died between the two
 * writes, or a second write that failed because a stale timeout row was already
 * there, left a lock nothing would release.
 *
 * `ApplyManager::recoverInterruptedRuns()` then refuses to run while the lock
 * is held, reasoning that a held lock means a live apply. Correct in general,
 * and exactly wrong here: the one mechanism that could clear the lock was the
 * one thing the stuck lock prevented.
 *
 * The expiry now lives inside the value, written once, and a value in the old
 * shape reads as free — so a site carrying a stuck one recovers on the next
 * request rather than needing somebody to find a row and delete it.
 */
final class ApplyLockTest extends IntegrationTestCase {

	/**
	 * Start each test with no lock at all.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Lock() )->forceRelease();

		$this->plugin->schema()->ensure();
	}

	/**
	 * Leave none behind either.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		( new Lock() )->forceRelease();

		parent::tear_down();
	}

	/**
	 * One holder at a time.
	 *
	 * @return void
	 */
	public function test_only_one_holder_at_a_time(): void {
		$first  = new Lock( 'first' );
		$second = new Lock( 'second' );

		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );

		$this->assertTrue( $first->isHeldByUs() );
		$this->assertFalse( $second->isHeldByUs() );

		$this->assertTrue( $first->release() );
		$this->assertTrue( $second->acquire() );
	}

	/**
	 * A holder cannot have its lock taken away by somebody else's release.
	 *
	 * @return void
	 */
	public function test_release_refuses_a_lock_somebody_else_holds(): void {
		$holder    = new Lock( 'holder' );
		$pretender = new Lock( 'pretender' );

		$this->assertTrue( $holder->acquire() );
		$this->assertFalse( $pretender->release() );
		$this->assertTrue( $holder->isHeldByUs() );
	}

	/**
	 * An expired lock is not held.
	 *
	 * @return void
	 */
	public function test_an_expired_lock_reads_as_free(): void {
		$stale = new Lock( 'stale' );

		$this->assertTrue( $stale->acquire() );
		$this->assertTrue( $stale->isHeld() );

		// Wind the stored expiry back rather than waiting a minute. The value
		// is the contract being tested, so writing it directly is the test.
		update_option( Lock::OPTION, 'stale|' . ( time() - 1 ), false );

		$this->assertNull( $stale->heldBy() );
		$this->assertFalse( $stale->isHeld() );

		// And somebody else can take it, which is the point of an expiry.
		$this->assertTrue( ( new Lock( 'next' ) )->acquire() );
	}

	/**
	 * Refreshing extends the expiry, so a slow apply keeps its own lock.
	 *
	 * @return void
	 */
	public function test_refresh_extends_the_expiry(): void {
		$lock = new Lock( 'slow' );

		$this->assertTrue( $lock->acquire() );

		update_option( Lock::OPTION, 'slow|' . ( time() + 1 ), false );

		$this->assertTrue( $lock->refresh() );

		$stored = (string) get_option( Lock::OPTION, '' );
		$expiry = (int) explode( '|', $stored )[1];

		$this->assertGreaterThan( time() + 30, $expiry );
	}

	/**
	 * A lock in the old, un-expiring shape reads as free.
	 *
	 * This is the shape that stuck a real site. A bare token with no expiry is
	 * what the two-option scheme left behind when its second write was lost,
	 * and `get_transient()` would have returned it forever.
	 *
	 * @return void
	 */
	public function test_a_legacy_lock_with_no_expiry_reads_as_free(): void {
		add_option( Lock::OPTION, 'a-token-from-the-old-scheme', '', false );

		$lock = new Lock( 'somebody-new' );

		$this->assertNull(
			$lock->heldBy(),
			'A value with no expiry cannot be trusted and must not hold the site closed.'
		);
		$this->assertFalse( $lock->isHeld() );
		$this->assertTrue(
			$lock->acquire(),
			'A site carrying a stuck lock must be able to take a new one.'
		);
	}

	/**
	 * A malformed value does not hold the site closed either.
	 *
	 * @return void
	 */
	public function test_a_malformed_lock_reads_as_free(): void {
		foreach ( array( '|', 'token|', '|123', 'token|not-a-number', '' ) as $rubbish ) {
			delete_option( Lock::OPTION );
			add_option( Lock::OPTION, $rubbish, '', false );

			$this->assertNull(
				( new Lock( 'x' ) )->heldBy(),
				sprintf( 'A stored value of "%s" must not read as a holder.', $rubbish )
			);
		}
	}

	/**
	 * The deadlock itself: recovery must not be blocked by a stale lock.
	 *
	 * The whole failure, end to end. A lock in the un-expiring shape used to
	 * make `recoverInterruptedRuns()` return early — it saw a held lock and
	 * concluded an apply was in flight — so the run stayed interrupted, the
	 * lock stayed held, and the site could never apply anything again.
	 *
	 * @return void
	 */
	public function test_a_stale_lock_does_not_block_crash_recovery(): void {
		// The site as it was found: a lock left in the shape that never expires.
		add_option( Lock::OPTION, 'a-token-nobody-will-release', '', false );

		$this->assertFalse(
			( new Lock() )->isHeld(),
			'The stale lock must not read as held, or recovery will step aside for it.'
		);

		// Recovery runs rather than refusing, and finding nothing to recover is
		// the correct outcome on a site with no interrupted runs.
		$this->assertSame( array(), $this->plugin->recoverInterruptedRuns() );

		// And an apply can now be attempted at all, which is what the site
		// could not do.
		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$result = $this->plugin->apply( $preview->plan );

		$this->assertNotSame(
			\Debloater\Contracts\RunState::ABORTED,
			$result->state,
			'The apply must not be refused by a lock nobody holds.'
		);

		// The refusal it used to give, named exactly, so a future regression
		// cannot pass by aborting for a different reason.
		$this->assertStringNotContainsString(
			'already in progress',
			(string) $result->error
		);

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * A committed apply leaves no lock behind.
	 *
	 * @return void
	 */
	public function test_an_apply_releases_its_lock(): void {
		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$this->plugin->apply( $preview->plan );

		$this->assertNull(
			( new Lock() )->heldBy(),
			'An apply that finished must not leave the site locked.'
		);

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}
}
