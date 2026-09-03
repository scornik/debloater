<?php
/**
 * The record of what happened to each tweak.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Journal;

use Debloater\Contracts\JournalAction;
use Debloater\Contracts\Json;
use Debloater\Contracts\TweakParams;
use Debloater\Contracts\TweakState;
use Debloater\Storage\Schema;

/**
 * Append-only history of every tweak state transition (BUILD-SPEC §8, §9.1).
 *
 * Every transition writes a row. That is not audit theatre: it is how a run
 * that died halfway through can be understood afterwards, and how "why is this
 * setting different from last week" gets an answer better than a guess.
 *
 * Two rules give it its value.
 *
 * **It is append-only.** Nothing here is ever updated or deleted by the
 * plugin. A rollback writes `revert` rows; it does not erase the `apply` rows
 * that preceded them, because what happened is not the same as what is
 * currently true.
 *
 * **It records no personal data** beyond the actor id (§13 rule 12). Who did
 * it, when, to which tweak, with what parameters. Not who was logged in, not
 * what page they came from, not their address.
 *
 * A write that fails is swallowed. Journalling is a record of the work, not the
 * work: a full disk or a locked table should not turn a successful apply into a
 * failed one, and the run's own status is the authority on what happened.
 */
final class Journal {

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A table name cannot be a placeholder, and the one interpolated in this class is built from Schema's own constants plus $wpdb->prefix. Values are always parameterised.

	/**
	 * Who is acting, e.g. "user:123", "cli" or "cron".
	 *
	 * @var string
	 */
	private string $actor;

	/**
	 * Constructor.
	 *
	 * @param string $actor Acting principal.
	 */
	public function __construct( string $actor = 'system' ) {
		$this->actor = $actor;
	}

	/**
	 * Record a transition.
	 *
	 * @param int              $run_id     The run this belongs to.
	 * @param string           $tweak_id   Tweak that changed state.
	 * @param JournalAction    $action     What was being attempted.
	 * @param TweakState       $from       State before.
	 * @param TweakState       $to         State after.
	 * @param TweakParams|null $params     Parameters in force, when relevant.
	 * @return bool Whether the row was written.
	 */
	public function record(
		int $run_id,
		string $tweak_id,
		JournalAction $action,
		TweakState $from,
		TweakState $to,
		?TweakParams $params = null
	): bool {
		global $wpdb;

		$row = array(
			'run_id'     => $run_id,
			'tweak_id'   => $tweak_id,
			'action'     => $action->value,
			'from_state' => $from->value,
			'to_state'   => $to->value,
			'params'     => ( null === $params || $params->isEmpty() ) ? null : Json::canonical( $params->toArray() ),
			'at'         => gmdate( 'Y-m-d H:i:s' ),
			'actor'      => $this->actor,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table; a journal write must never be served from cache.
		$written = $wpdb->insert(
			Schema::table( Schema::JOURNAL ),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $written;
	}

	/**
	 * Record an applied tweak.
	 *
	 * @param int              $run_id   Run id.
	 * @param string           $tweak_id Tweak id.
	 * @param TweakState       $from     State before.
	 * @param TweakState       $to       State after.
	 * @param TweakParams|null $params   Parameters applied.
	 * @return bool
	 */
	public function applied( int $run_id, string $tweak_id, TweakState $from, TweakState $to, ?TweakParams $params = null ): bool {
		return $this->record( $run_id, $tweak_id, JournalAction::APPLY, $from, $to, $params );
	}

	/**
	 * Record a reverted tweak.
	 *
	 * @param int        $run_id   Run id.
	 * @param string     $tweak_id Tweak id.
	 * @param TweakState $from     State before.
	 * @param TweakState $to       State after.
	 * @return bool
	 */
	public function reverted( int $run_id, string $tweak_id, TweakState $from, TweakState $to ): bool {
		return $this->record( $run_id, $tweak_id, JournalAction::REVERT, $from, $to );
	}

	/**
	 * Record a tweak that was not applied, and why.
	 *
	 * A skip is journalled like anything else. "It was never applied" and "there
	 * is no record of it" look identical afterwards unless one of them is
	 * written down.
	 *
	 * @param int        $run_id   Run id.
	 * @param string     $tweak_id Tweak id.
	 * @param TweakState $from     State before.
	 * @return bool
	 */
	public function skipped( int $run_id, string $tweak_id, TweakState $from ): bool {
		return $this->record( $run_id, $tweak_id, JournalAction::SKIP, $from, $from );
	}

	/**
	 * Every entry for a run, oldest first.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,array<string,mixed>>
	 */
	public function forRun( int $run_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::JOURNAL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name; the id is parameterised.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d ORDER BY id ASC", $run_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every entry for a tweak, newest first.
	 *
	 * @param string $tweak_id Tweak id.
	 * @param int    $limit    Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function forTweak( string $tweak_id, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( Schema::JOURNAL );
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE tweak_id = %s ORDER BY id DESC LIMIT %d", $tweak_id, $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many entries a run produced.
	 *
	 * @param int $run_id Run id.
	 * @return int
	 */
	public function countForRun( int $run_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::JOURNAL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE run_id = %d", $run_id )
		);
	}

	/**
	 * The acting principal.
	 *
	 * @return string
	 */
	public function actor(): string {
		return $this->actor;
	}

	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
