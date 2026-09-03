<?php
/**
 * What an update check found.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Update;

/**
 * The result of asking whether a newer registry exists (BUILD-SPEC §17
 * Phase 17).
 *
 * Four outcomes, and they are deliberately not booleans. "Up to date" and
 * "could not check" look identical to a caller that only asks whether an update
 * is available, and they mean opposite things: one is reassurance, the other is
 * the absence of it.
 */
final class UpdateCheck {

	/**
	 * The vendored registry is the newest release.
	 */
	public const CURRENT = 'current';

	/**
	 * A newer release exists and verified.
	 */
	public const AVAILABLE = 'available';

	/**
	 * Something was offered and refused.
	 */
	public const REFUSED = 'refused';

	/**
	 * Nothing was asked, or the answer never arrived.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * One of the constants above.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * The tag currently vendored.
	 *
	 * @var string
	 */
	public readonly string $current_tag;

	/**
	 * The tag offered, when one was.
	 *
	 * @var string
	 */
	public readonly string $offered_tag;

	/**
	 * What happened, in words a person can act on.
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Constructor.
	 *
	 * @param string $status      One of the class constants.
	 * @param string $current_tag Tag currently vendored.
	 * @param string $offered_tag Tag offered, if any.
	 * @param string $message     Human-readable explanation.
	 */
	public function __construct( string $status, string $current_tag, string $offered_tag, string $message ) {
		$this->status      = $status;
		$this->current_tag = $current_tag;
		$this->offered_tag = $offered_tag;
		$this->message     = $message;
	}

	/**
	 * Whether a verified newer release is waiting.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return self::AVAILABLE === $this->status;
	}

	/**
	 * Whether something was offered and rejected.
	 *
	 * @return bool
	 */
	public function wasRefused(): bool {
		return self::REFUSED === $this->status;
	}

	/**
	 * The result as an array.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return array(
			'status'      => $this->status,
			'current_tag' => $this->current_tag,
			'offered_tag' => $this->offered_tag,
			'message'     => $this->message,
		);
	}
}
