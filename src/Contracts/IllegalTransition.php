<?php
/**
 * Thrown when a state machine is asked to make a transition that does not exist.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

use RuntimeException;

/**
 * An illegal state transition was attempted (BUILD-SPEC §9).
 *
 * This is deliberately fatal rather than a returned false. The run state machine
 * governs snapshotting, applying and rollback; a caller that has lost track of
 * where it is must stop, not continue guessing, because the next step might be
 * writing to the filesystem or deleting rows.
 */
final class IllegalTransition extends RuntimeException {

	/**
	 * The state the machine was in.
	 *
	 * @var string
	 */
	private string $from;

	/**
	 * The state that was requested.
	 *
	 * @var string
	 */
	private string $to;

	/**
	 * Constructor.
	 *
	 * @param string            $machine Machine name, for the message.
	 * @param string            $from    Current state value.
	 * @param string            $to      Requested state value.
	 * @param array<int,string> $allowed Legal successors of the current state.
	 */
	public function __construct( string $machine, string $from, string $to, array $allowed ) {
		$this->from = $from;
		$this->to   = $to;

		parent::__construct(
			sprintf(
				'%s cannot transition from %s to %s; allowed: %s',
				$machine,
				$from,
				$to,
				array() === $allowed ? '(none, terminal state)' : implode( ', ', $allowed )
			)
		);
	}

	/**
	 * The state the machine was in.
	 *
	 * @return string
	 */
	public function from(): string {
		return $this->from;
	}

	/**
	 * The state that was requested.
	 *
	 * @return string
	 */
	public function to(): string {
		return $this->to;
	}
}
