<?php
/**
 * Whether each selected config tweak's declared effect shows in a set of facts.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify;

use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\TweakKind;
use Debloater\Registry\Registry;

/**
 * Judges a selection against the effects the registry declares for it.
 *
 * Decides and does nothing else (P7): it reads no option, fetches nothing and
 * scans nothing. `Plugin::effectReport()` gives it the facts of the request it
 * runs in — a fresh loopback request, when verification asks — and the
 * `effects_observed` probe and `wp debloater status` read the rows (`D-0079`).
 *
 * One row per selected config tweak the registry knows:
 *
 * - `observed` — the declared fact holds.
 * - `not_observed` — it does not: the tweak is stored and, if the handlers
 *   registered, is doing something no request can see, which is the failure
 *   `TweakEffectTest` was written about.
 * - `not_collected` — the fact was not in the request's facts at all, such as
 *   a WooCommerce fact on a site without WooCommerce.
 * - `unobservable` — the registry says nothing a request reads can show it, and
 *   why.
 */
final class EffectCheck {

	public const OBSERVED      = 'observed';
	public const NOT_OBSERVED  = 'not_observed';
	public const NOT_COLLECTED = 'not_collected';
	public const UNOBSERVABLE  = 'unobservable';

	/**
	 * Judge each selected config tweak.
	 *
	 * @param Registry                         $registry  Registry.
	 * @param array<string,array<string,mixed>> $selection Tweak id to stored parameters.
	 * @param FactSet                          $facts     Facts from the request being judged.
	 * @return array<int,array{tweak:string,status:string,fact:string|null,expected:string,actual:mixed,reason:string}>
	 */
	public static function evaluate( Registry $registry, array $selection, FactSet $facts ): array {
		$rows = array();

		ksort( $selection, SORT_STRING );

		foreach ( $selection as $tweak_id => $params ) {
			if ( ! is_string( $tweak_id ) || ! $registry->has( $tweak_id ) ) {
				continue;
			}

			$definition = $registry->tweak( $tweak_id );

			if ( TweakKind::CONFIG !== $definition->kind || null === $definition->effect ) {
				continue;
			}

			$effect = $definition->effect;

			if ( ! $effect->observable ) {
				$rows[] = self::row( $tweak_id, self::UNOBSERVABLE, null, '', null, (string) $effect->reason );

				continue;
			}

			try {
				$resolved = $definition->resolve( is_array( $params ) ? $params : array() )->params->toArray();
			} catch ( ContractViolation $violation ) {
				$rows[] = self::row( $tweak_id, self::NOT_COLLECTED, $effect->fact, '', null, $violation->getMessage() );

				continue;
			}

			$fact     = (string) $effect->fact;
			$expected = $effect->describeExpected( $resolved );

			if ( ! $facts->has( $fact ) ) {
				$rows[] = self::row( $tweak_id, self::NOT_COLLECTED, $fact, $expected, null, '' );

				continue;
			}

			$actual = $facts->value( $fact );
			$holds  = $effect->holds( $actual, $resolved );

			$rows[] = self::row( $tweak_id, true === $holds ? self::OBSERVED : self::NOT_OBSERVED, $fact, $expected, $actual, '' );
		}

		return $rows;
	}

	/**
	 * One row.
	 *
	 * @param string      $tweak    Tweak id.
	 * @param string      $status   Row status.
	 * @param string|null $fact     Fact key.
	 * @param string      $expected What the fact should be.
	 * @param mixed       $actual   What it was.
	 * @param string      $reason   Why, where there is one.
	 * @return array{tweak:string,status:string,fact:string|null,expected:string,actual:mixed,reason:string}
	 */
	private static function row( string $tweak, string $status, ?string $fact, string $expected, mixed $actual, string $reason ): array {
		return array(
			'tweak'    => $tweak,
			'status'   => $status,
			'fact'     => $fact,
			'expected' => $expected,
			'actual'   => $actual,
			'reason'   => $reason,
		);
	}
}
