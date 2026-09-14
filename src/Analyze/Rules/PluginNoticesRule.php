<?php
/**
 * Analyzer rule: admin.notices.from_plugins.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Severity;

/**
 * Plugins on the allowlist are printing admin notices.
 *
 * Reported, and not recommended. `admin.suppress_promo_notices` exists and can
 * be selected by id, but since 0.5.0 no scan offers it (`D-0079`):
 *
 * - **A scan cannot see whether it worked.** The handler removes the notices on
 *   `admin_head`, while the admin page is being built. A scan enumerates notice
 *   callbacks without building that page, so with the change in effect the
 *   next scan found the same callbacks and offered it again, for ever.
 *   `TweakEffectTest` is what showed it.
 * - **No real scan produces these facts anyway.** Admin facts are collected
 *   only when `is_admin()`, and the dashboard scans over REST and WP-CLI scans
 *   from a terminal. The finding existed in tests and nowhere else.
 *
 * What the finding still says is true on any scan that does see the admin:
 * which allowlisted plugins print notices, and that hiding them hides their
 * operational warnings too. Severity is `info`: a site is not penalised for
 * something this plugin no longer offers to change.
 */
final class PluginNoticesRule extends AbstractRule {

	/**
	 * How many notice callbacks from allowlisted plugins make this worth
	 * offering.
	 *
	 * One notice is not a problem worth a medium-risk change.
	 */
	public const THRESHOLD = 3;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'admin.notices.from_plugins';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.9;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'admin.notices', 'admin.notice_vendors' );
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

		$vendors = $facts->value( 'admin.notice_vendors', array() );
		$notices = $facts->value( 'admin.notices', array() );

		if ( ! is_array( $vendors ) || ! is_array( $notices ) || array() === $vendors ) {
			return null;
		}

		$sources = array();
		$names   = array();

		foreach ( $vendors as $vendor ) {
			if ( ! is_array( $vendor ) ) {
				continue;
			}

			$sources[ (string) ( $vendor['source'] ?? '' ) ] = true;
			$names[ (string) ( $vendor['name'] ?? '' ) ]     = true;
		}

		unset( $sources[''], $names[''] );

		$count = 0;

		foreach ( $notices as $notice ) {
			if ( is_array( $notice ) && isset( $sources[ (string) ( $notice['source'] ?? '' ) ] ) ) {
				++$count;
			}
		}

		if ( $count < self::THRESHOLD || array() === $sources ) {
			return null;
		}

		$labels = array_keys( $names );

		sort( $labels, SORT_STRING );

		return $this->inform(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::INFO,
				'title'    => sprintf(
					/* translators: %d: number of notice callbacks from plugins on the allowlist. */
					_n(
						'%d admin notice comes from a plugin whose notices you can hide',
						'%d admin notices come from plugins whose notices you can hide',
						$count,
						'hakeemify-debloater'
					),
					$count
				),
				'summary'  => sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( 'From: %s.', 'hakeemify-debloater' ),
					implode( ', ', $labels )
				),
				'why'      => __(
					'These plugins print into the admin notice area. Debloater has a change that hides everything they say there, but it does not offer it from a scan: the change acts while the admin page is being built, where a scan cannot see whether it worked. If you choose it by hand, know that it hides not only the marketing: these plugins send upgrade prompts and warnings about pending database updates or expiring licences down the same channel, and nothing reliably tells them apart.',
					'hakeemify-debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Notices by source', 'hakeemify-debloater' ), 'admin.notices' )
					->fact( __( 'Plugins whose notices can be hidden', 'hakeemify-debloater' ), 'admin.notice_vendors' )
					->optional( __( 'Total notice callbacks', 'hakeemify-debloater' ), 'admin.notices.count' )
					->build(),
			)
		);
	}
}
