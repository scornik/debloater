<?php
/**
 * Analyzer rule: admin.notices.from_plugins.
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
 * Plugins on the allowlist are printing admin notices.
 *
 * This is the most cautious recommendation WP Debloat makes, and the wording
 * has to carry that. The change it proposes hides *everything* those plugins
 * say in the notice area, because they print upgrade prompts and "your database
 * needs updating" from the same hook and nothing separates the two. So:
 *
 * - It is `medium` risk, which keeps it out of "Fix Safe Issues" entirely.
 * - It proposes only vendors that are both on the allowlist *and* actually
 *   printing notices here, so it never suggests silencing something silent.
 * - The reasoning says what will be missed, not only what will be gained.
 *
 * A rule that said "hide promotional notices" and then hid an expiring licence
 * warning would have lied. This one says what it does.
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

		$selected = array_keys( $sources );
		$labels   = array_keys( $names );

		sort( $selected, SORT_STRING );
		sort( $labels, SORT_STRING );

		return $this->recommend(
			array(
				'category' => Category::ADMIN,
				'severity' => Severity::LOW,
				'risk'     => Risk::MEDIUM,
				'tweak_id' => 'admin.suppress_promo_notices',
				'params'   => array( 'sources' => $selected ),
				'title'    => sprintf(
					/* translators: %d: number of notice callbacks from plugins on the allowlist. */
					_n(
						'%d admin notice comes from a plugin whose notices you can hide',
						'%d admin notices come from plugins whose notices you can hide',
						$count,
						'wp-debloat'
					),
					$count
				),
				'summary'  => sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( 'From: %s.', 'wp-debloat' ),
					implode( ', ', $labels )
				),
				'why'      => __(
					'These plugins print into the admin notice area on every screen. Hiding them is offered because the interruption is real — but read this first: it hides everything they say there, not only the marketing. These plugins send upgrade prompts and warnings about pending database updates or expiring licences down the same channel, and nothing reliably tells them apart. Nothing is uninstalled or switched off, and unselecting this brings the notices straight back.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Notices by source', 'wp-debloat' ), 'admin.notices' )
					->fact( __( 'Plugins whose notices can be hidden', 'wp-debloat' ), 'admin.notice_vendors' )
					->optional( __( 'Total notice callbacks', 'wp-debloat' ), 'admin.notices.count' )
					->build(),
			)
		);
	}
}
