<?php
/**
 * Stops two runs changing the site at once.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Apply;

use WPDebloat\Brand;

/**
 * The apply lock (BUILD-SPEC §8).
 *
 * Two applies running at once would interleave their snapshots and their
 * writes, and the result would be a site whose state matches neither run's
 * record of it. The lock makes the second one wait or fail, and failing is the
 * better of the two: an apply the user did not see start is not one they should
 * discover has finished.
 *
 * A transient with a TTL rather than a database row, deliberately. A process
 * killed mid-run cannot release its lock, and a row would hold the site closed
 * until someone found it and deleted it by hand. A transient expires on its own,
 * so the worst case is a wait rather than a permanently stuck site — and crash
 * recovery deals with the run itself on the next boot.
 *
 * The TTL is refreshed while a run is in flight, so a slow apply does not lose
 * its own lock partway through.
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

		// A transient with an expiry stores its timeout as a separate autoloaded
		// option; add_option on the value gives the atomic claim.
		$claimed = add_option( '_transient_' . self::KEY, $this->token, '', false );

		if ( ! $claimed ) {
			return false;
		}

		add_option( '_transient_timeout_' . self::KEY, (string) ( time() + self::TTL ), '', false );

		return true;
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

		update_option( '_transient_timeout_' . self::KEY, (string) ( time() + self::TTL ), false );

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

		delete_transient( self::KEY );

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
		delete_transient( self::KEY );
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
		$holder = get_transient( self::KEY );

		return is_string( $holder ) && '' !== $holder ? $holder : null;
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
