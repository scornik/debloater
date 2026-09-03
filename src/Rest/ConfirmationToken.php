<?php
/**
 * Proof that the user confirmed this exact change.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Rest;

use Debloater\Contracts\Json;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\Snapshot;

/**
 * The confirmation step, made unforgeable (BUILD-SPEC §17 Phase 8, §13 rule 12).
 *
 * A nonce proves the request came from this site's admin. It does not prove the
 * user was shown *this* plan and agreed to it. Those are different questions,
 * and the second is the one that matters when the plan changes between being
 * previewed and being applied — a plugin activated in another tab, a scan that
 * ran in between — because the user would then be confirming one thing and
 * getting another.
 *
 * So a write route takes a token derived from the content it is about to act
 * on. The dashboard receives it with the preview and sends it back with the
 * apply; if the plan is no longer the plan that was previewed, the token does
 * not match and the request is refused with an explanation rather than applied
 * to something the user has not seen.
 *
 * The token is keyed on `wp_salt()`, so it cannot be constructed by anything
 * that has not already read the site's secrets.
 */
final class ConfirmationToken {

	/**
	 * Token for a plan.
	 *
	 * @param PreviewPlan $plan The plan the user was shown.
	 * @return string
	 */
	public static function forPlan( PreviewPlan $plan ): string {
		return self::sign( 'plan', Json::canonical( $plan->toArray() ) );
	}

	/**
	 * Token for a recovery point.
	 *
	 * @param Snapshot $snapshot The recovery point to restore.
	 * @return string
	 */
	public static function forSnapshot( Snapshot $snapshot ): string {
		return self::sign(
			'snapshot',
			Json::canonical(
				array(
					'id'       => $snapshot->id,
					'run_id'   => $snapshot->run_id,
					'checksum' => $snapshot->checksum,
				)
			)
		);
	}

	/**
	 * Whether a token matches a plan.
	 *
	 * @param PreviewPlan $plan  The plan about to be applied.
	 * @param string      $token The token the caller supplied.
	 * @return bool
	 */
	public static function matchesPlan( PreviewPlan $plan, string $token ): bool {
		return hash_equals( self::forPlan( $plan ), $token );
	}

	/**
	 * Whether a token matches a recovery point.
	 *
	 * @param Snapshot $snapshot The recovery point about to be restored.
	 * @param string   $token    The token the caller supplied.
	 * @return bool
	 */
	public static function matchesSnapshot( Snapshot $snapshot, string $token ): bool {
		return hash_equals( self::forSnapshot( $snapshot ), $token );
	}

	/**
	 * Sign a payload.
	 *
	 * @param string $kind    What is being confirmed.
	 * @param string $payload Canonical JSON of the thing being confirmed.
	 * @return string
	 */
	private static function sign( string $kind, string $payload ): string {
		return hash_hmac( 'sha256', $kind . '|' . $payload, wp_salt( 'debloater_confirm' ) );
	}
}
