<?php
/**
 * Stops two runs changing the site at once.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply;

use Debloater\Brand;

/**
 * The apply lock (BUILD-SPEC §8).
 *
 * Two applies running at once would interleave their snapshots and their
 * writes, and the result would be a site whose state matches neither run's
 * record of it. The lock makes the second one wait or fail, and failing is the
 * better of the two: an apply the user did not see start is not one they should
 * discover has finished.
 *
 * A self-expiring lock rather than a database row, deliberately. A process
 * killed mid-run cannot release its lock, and a row would hold the site closed
 * until someone found it and deleted it by hand. This expires on its own, so
 * the worst case is a minute's wait rather than a permanently stuck site — and
 * crash recovery deals with the run itself on the next boot.
 *
 * The TTL is refreshed while a run is in flight, so a slow apply does not lose
 * its own lock partway through.
 *
 * ## Why the expiry is inside the value
 *
 * This used to be a WordPress transient, and that made the paragraph above
 * false. A transient is two options: `_transient_x` holding the value and
 * `_transient_timeout_x` holding the expiry. `acquire()` wrote them with two
 * separate `add_option()` calls — and `get_transient()` treats a value with no
 * timeout as one that **never expires**.
 *
 * So a request that died between the two writes, or a second `add_option()`
 * that failed because a stale timeout row was already there, left a lock that
 * nothing would ever release. And `ApplyManager::recoverInterruptedRuns()`
 * refuses to run while the lock is held, on the reasoning that a held lock
 * means a live apply — so the one mechanism that could have cleared it was the
 * one thing the stuck lock prevented. A site in that state could never apply
 * anything again, and the only message it gave was "wait for it to finish".
 *
 * The token and the expiry now live in one value, written once. There is no
 * second row to lose, no window between two writes, and expiry is decided by
 * this class rather than inferred from the presence of another option.
 *
 * The option is still named as a transient so that `delete_transient()` in
 * `uninstall.php` continues to remove it.
 */
final class Lock {

	/**
	 * How long the lock lives without being refreshed, in seconds.
	 */
	public const TTL = 60;

	/**
	 * The transient the lock lives in.
	 */
	public const KEY = Brand::LOCK_TRANSIENT;

	/**
	 * The option that actually holds it.
	 *
	 * Named as a transient so `delete_transient()` during uninstall still
	 * removes it, but read and written directly, because the expiry lives in
	 * the value rather than in a second row.
	 */
	public const OPTION = '_transient_' . Brand::LOCK_TRANSIENT;

	/**
	 * Who holds this lock, when we hold it.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Identifies the holder; generated when not supplied.
	 */
	public function __construct( string $token = '' ) {
		$this->token = '' === $token ? bin2hex( random_bytes( 16 ) ) : $token;
	}

	/**
	 * Take the lock, or fail because someone else has it.
	 *
	 * Uses add_option rather than set_transient for the initial claim, because
	 * `add_option` is a single INSERT that fails on a duplicate key. Reading
	 * then writing would leave a window in which two requests both see the lock
	 * free.
	 *
	 * @return bool Whether the lock was taken.
	 */
	public function acquire(): bool {
		$holder = $this->heldBy();

		if ( null !== $holder ) {
			return $holder === $this->token;
		}

		// An expired or malformed value is not a holder, and heldBy() has just
		// said so — but the row may still be sitting there, and add_option()
		// refuses a name that exists. Clearing it first is what lets a site
		// recover from a lock the old two-option scheme left behind.
		if ( false !== get_option( self::OPTION, false ) ) {
			delete_option( self::OPTION );
		}

		// One write. add_option() is a single INSERT that fails on a duplicate
		// key, which is the atomic claim; reading then writing would leave a
		// window in which two requests both see the lock free.
		return (bool) add_option( self::OPTION, $this->value(), '', false );
	}

	/**
	 * The stored form: who holds it, and until when.
	 *
	 * @return string
	 */
	private function value(): string {
		return $this->token . '|' . ( time() + self::TTL );
	}

	/**
	 * Extend the lock, if we still hold it.
	 *
	 * @return bool Whether the lock is still ours afterwards.
	 */
	public function refresh(): bool {
		if ( ! $this->isHeldByUs() ) {
			return false;
		}

		update_option( self::OPTION, $this->value(), false );

		return true;
	}

	/**
	 * Release the lock, if we hold it.
	 *
	 * Refuses to release a lock someone else holds: a run that has lost its lock
	 * to an expiry must not then take it away from whoever picked it up.
	 *
	 * @return bool Whether the lock was released by this call.
	 */
	public function release(): bool {
		if ( ! $this->isHeldByUs() ) {
			return false;
		}

		delete_option( self::OPTION );

		return true;
	}

	/**
	 * Release the lock whoever holds it.
	 *
	 * For recovery only, where a crashed run has left a lock nobody will ever
	 * release and the TTL has not yet expired.
	 *
	 * @return void
	 */
	public function forceRelease(): void {
		delete_option( self::OPTION );

		// The old scheme's timeout row, if this site still carries one.
		delete_option( '_transient_timeout_' . self::KEY );
	}

	/**
	 * Whether anyone holds the lock.
	 *
	 * @return bool
	 */
	public function isHeld(): bool {
		return null !== $this->heldBy();
	}

	/**
	 * Whether we hold the lock.
	 *
	 * @return bool
	 */
	public function isHeldByUs(): bool {
		return $this->heldBy() === $this->token;
	}

	/**
	 * The token of whoever holds the lock, or null when it is free.
	 *
	 * @return string|null
	 */
	public function heldBy(): ?string {
		$stored = get_option( self::OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$parts = explode( '|', $stored, 2 );

		// A value with no expiry is from the two-option scheme this class used
		// to use, and it is exactly the shape that could never expire. It is
		// treated as free: a site upgrading with one of these stuck in its
		// options table starts working again on the next request, which is the
		// whole point of noticing the shape.
		if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) ) {
			return null;
		}

		if ( (int) $parts[1] <= time() ) {
			return null;
		}

		return $parts[0];
	}

	/**
	 * This holder's token.
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}
}
