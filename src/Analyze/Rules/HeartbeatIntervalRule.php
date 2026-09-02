<?php
/**
 * Analyzer rule: wp.heartbeat.aggressive.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze\Rules;

use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;

/**
 * Fires when Heartbeat polls more often than this site needs.
 *
 * This is the worked example in BUILD-SPEC §6, and it is the finding that shows
 * why the architecture is shaped the way it is. The same configuration — 15
 * second polling — is wasteful on a one-person blog and load-bearing on a store
 * where four people edit the same orders. The fact is identical; the right
 * answer is not.
 *
 * So the rule does not decide. It reports what it saw, proposes an interval
 * suited to what it saw, and leaves the refusal to DontTouchRules, which is
 * where the site's circumstances are weighed.
 *
 * The proposed interval follows the same reasoning: a quiet site can afford 120
 * seconds, a busy one gets 60, which is a real reduction without making post
 * locking useless.
 */
final class HeartbeatIntervalRule extends AbstractRule {

	/**
	 * Below this many seconds, polling is worth looking at.
	 */
	private const AGGRESSIVE_BELOW = 60;

	/**
	 * Proposed interval for a site with collaborative editing or a store.
	 */
	private const BUSY_INTERVAL = 60;

	/**
	 * Proposed interval for a quiet site.
	 */
	private const QUIET_INTERVAL = 120;

	/**
	 * Admin count above which a site is treated as busy.
	 */
	private const BUSY_ADMIN_COUNT = 1;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.heartbeat.aggressive';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.95;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'wp.heartbeat_interval', 'users.admin_count' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) ) {
			return null;
		}

		$interval = (int) $facts->value( 'wp.heartbeat_interval' );

		if ( $interval >= self::AGGRESSIVE_BELOW ) {
			return null;
		}

		$admins   = (int) $facts->value( 'users.admin_count' );
		$proposed = $this->proposedInterval( $facts, $admins );

		$evidence = $this->evidence( $facts )
			->formatted(
				__( 'Current interval', 'wp-debloat' ),
				sprintf(
					/* translators: %d: number of seconds. */
					__( '%d s', 'wp-debloat' ),
					$interval
				),
				'wp.heartbeat_interval'
			)
			->fact( __( 'Administrators', 'wp-debloat' ), 'users.admin_count' )
			->optional( __( 'Edited content in the last week', 'wp-debloat' ), 'users.recent_editors_7d' );

		if ( $facts->has( 'plugins.detected' ) ) {
			$evidence->within( __( 'WooCommerce active', 'wp-debloat' ), 'plugins.detected', 'woocommerce' );
		}

		return $this->recommend(
			array(
				'category' => Category::WORDPRESS,
				'severity' => Severity::LOW,
				'risk'     => Risk::LOW,
				'title'    => __( 'Heartbeat polls more often than this site needs', 'wp-debloat' ),
				'summary'  => sprintf(
					/* translators: 1: current interval in seconds, 2: proposed interval in seconds. */
					__( 'Heartbeat polls every %1$d s. Nothing about how this site is used needs it that often; %2$d s is enough.', 'wp-debloat' ),
					$interval,
					$proposed
				),
				'why'      => __(
					'Heartbeat sends a background request on a timer to autosave drafts, warn when two people open the same post, and notice an expired login. Every open admin tab does it. On a busy site that is useful; on a quiet one it is a steady stream of admin-ajax requests for events that are not happening.',
					'wp-debloat'
				),
				'evidence' => $evidence->build(),
				'impact'   => $this->measurable(
					'admin_ajax_requests_per_hour',
					$this->requestsSaved( $interval, $proposed, $admins ),
					'requests'
				),
				'tweak_id' => 'core.heartbeat_interval',
				'params'   => array( 'interval' => $proposed ),
			)
		);
	}

	/**
	 * The interval to propose for this site.
	 *
	 * @param FactSet $facts  Facts from the scan.
	 * @param int     $admins Administrator count.
	 * @return int
	 */
	private function proposedInterval( FactSet $facts, int $admins ): int {
		$detected = $facts->value( 'plugins.detected', array() );
		$is_store = is_array( $detected ) && ! empty( $detected['woocommerce'] );

		if ( $is_store || $admins > self::BUSY_ADMIN_COUNT ) {
			return self::BUSY_INTERVAL;
		}

		return self::QUIET_INTERVAL;
	}

	/**
	 * Requests per hour this change would avoid.
	 *
	 * A deliberately conservative model: one open admin tab per administrator,
	 * for one hour. Real usage is lower most of the time and higher during a
	 * working session, so this is presented as an estimate the Meter will
	 * confirm or correct rather than as a result.
	 *
	 * @param int $current  Current interval in seconds.
	 * @param int $proposed Proposed interval in seconds.
	 * @param int $admins   Administrator count.
	 * @return float
	 */
	private function requestsSaved( int $current, int $proposed, int $admins ): float {
		if ( $current <= 0 || $proposed <= 0 ) {
			return 0.0;
		}

		$per_hour_now   = 3600 / $current;
		$per_hour_after = 3600 / $proposed;

		return round( max( 0.0, ( $per_hour_now - $per_hour_after ) * max( 1, $admins ) ), 1 );
	}
}
