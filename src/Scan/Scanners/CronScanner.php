<?php
/**
 * Facts about scheduled events.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;

/**
 * Collects the `cron.*` facts (BUILD-SPEC §5).
 *
 * The cron array is a single option, so the whole picture costs one read. What
 * it contains is worth looking at closely: an event scheduled every thirty
 * seconds fires on every visitor's request, and an event whose hook nothing
 * listens to any more runs the scheduler's bookkeeping for no reason at all.
 *
 * Both are reported as counts and hook names. Whether either is a problem is
 * the analyzer's question, not this scanner's.
 */
final class CronScanner extends AbstractScanner {

	/**
	 * Anything recurring more often than this is reported as sub-minute.
	 */
	private const SUBMINUTE_THRESHOLD = 60;

	/**
	 * How many sub-minute events to list. The count is always exact; the list
	 * is bounded so a pathological site cannot produce a payload nobody can read.
	 */
	private const MAX_LISTED = 20;

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'cron';
	}

	/**
	 * Collect cron facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		$events    = $this->events();
		$subminute = array();
		$orphans   = 0;
		$total     = 0;

		foreach ( $events as $event ) {
			++$total;

			if ( null !== $event['interval'] && $event['interval'] > 0 && $event['interval'] < self::SUBMINUTE_THRESHOLD ) {
				$subminute[ $event['hook'] ] = $event['interval'];
			}

			if ( ! has_action( $event['hook'] ) ) {
				++$orphans;
			}
		}

		ksort( $subminute, SORT_STRING );

		$listed = array();

		foreach ( array_slice( $subminute, 0, self::MAX_LISTED, true ) as $hook => $interval ) {
			$listed[] = array(
				'hook'     => $hook,
				'interval' => $interval,
			);
		}

		return array(
			'cron.events.count'     => $total,
			'cron.events.subminute' => $listed,
			'cron.orphans.count'    => $orphans,
			'cron.disable_wp_cron'  => $this->constantIsTrue( 'DISABLE_WP_CRON' ),
		);
	}

	/**
	 * Flatten the cron array into one entry per scheduled event.
	 *
	 * The stored shape is timestamp → hook → signature → args, which is awkward
	 * to reason about; flattening it here keeps the counting above readable.
	 *
	 * @return array<int,array{hook:string,interval:int|null}>
	 */
	private function events(): array {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return array();
		}

		$events = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $signatures ) {
				if ( ! is_string( $hook ) || ! is_array( $signatures ) ) {
					continue;
				}

				foreach ( $signatures as $event ) {
					if ( ! is_array( $event ) ) {
						continue;
					}

					$events[] = array(
						'hook'     => $hook,
						'interval' => isset( $event['interval'] ) && is_numeric( $event['interval'] )
							? (int) $event['interval']
							: null,
					);
				}
			}

			unset( $timestamp );
		}

		return $events;
	}
}
