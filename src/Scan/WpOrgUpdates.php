<?php
/**
 * The one thing WP Debloat cannot learn without asking someone else.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan;

/**
 * Looks up when a plugin was last released, from wordpress.org (BUILD-SPEC §13
 * rule 9, §17 Phase 11).
 *
 * Everything else WP Debloat knows, it reads off the site itself. This is the
 * exception, and the exception is opt-in, because a plugin that quietly phones
 * home has broken a promise regardless of what it sent.
 *
 * Opting in is per scan rather than per site. There is no stored setting that,
 * once ticked, makes every future scan reach the network: the request is made
 * because somebody asked for it during the action that made it, and the next
 * scan asks again. That is a slightly worse fit for a settings screen and a much
 * better fit for what consent means.
 *
 * When it is off — which is the default, and what happens on every scan a user
 * did not explicitly ask this of — no request is made, no cache is read, and
 * `lastUpdated()` returns nothing. Nothing is nothing: the caller falls back to
 * a local heuristic and says which one it used.
 */
final class WpOrgUpdates {

	/**
	 * The wordpress.org plugin information endpoint.
	 */
	public const ENDPOINT = 'https://api.wordpress.org/plugins/info/1.2/';

	/**
	 * How long a looked-up date is reused.
	 *
	 * A release date changes a few times a year at most, so a day is generous
	 * without being stale in any way that matters.
	 */
	public const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * How many plugins one scan will ask about.
	 *
	 * A request each, so the ceiling is on the scan's wall-clock cost. A site
	 * with more active plugins than this has a bigger problem than this feature.
	 */
	public const MAX_LOOKUPS = 40;

	/**
	 * Seconds to wait for one answer.
	 */
	public const TIMEOUT = 5;

	/**
	 * Whether the user asked for this lookup.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param bool $enabled Whether the user opted in for this scan.
	 */
	public function __construct( bool $enabled = false ) {
		$this->enabled = $enabled;
	}

	/**
	 * Whether the lookup will happen.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Turn the lookup on or off for the next scan.
	 *
	 * @param bool $enabled Whether the user opted in.
	 * @return void
	 */
	public function setEnabled( bool $enabled ): void {
		$this->enabled = $enabled;
	}

	/**
	 * Release dates for plugin slugs, as ISO dates.
	 *
	 * A slug is absent from the result when it could not be looked up — a plugin
	 * that is not on wordpress.org, a request that failed, an answer that did
	 * not carry the field. Absent means "not known", and the caller must not
	 * read it as "never updated".
	 *
	 * @param array<int,string> $slugs Plugin directory slugs.
	 * @return array<string,string>
	 */
	public function lastUpdated( array $slugs ): array {
		if ( ! $this->enabled ) {
			return array();
		}

		$dates = array();
		$asked = 0;

		foreach ( array_unique( $slugs ) as $slug ) {
			if ( $asked >= self::MAX_LOOKUPS ) {
				break;
			}

			++$asked;

			$date = $this->lookup( $slug );

			if ( null !== $date ) {
				$dates[ $slug ] = $date;
			}
		}

		ksort( $dates, SORT_STRING );

		return $dates;
	}

	/**
	 * One slug, from the cache or from wordpress.org.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return string|null
	 */
	private function lookup( string $slug ): ?string {
		$key    = 'wpdebloat_wporg_' . md5( $slug );
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			return '' === $cached ? null : $cached;
		}

		$date = $this->fetch( $slug );

		// The negative is cached too, so a plugin that is not on wordpress.org
		// is not asked about again on every scan.
		set_transient( $key, $date ?? '', self::CACHE_TTL );

		return $date;
	}

	/**
	 * Ask wordpress.org about one plugin.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return string|null
	 */
	private function fetch( string $slug ): ?string {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() exists only on VIP, and WP Debloat ships with zero runtime dependencies and has to work on any host. What that function buys is a bounded timeout and a graceful failure, which this call already has: five seconds, and every failure path returns null so the scan falls back to the local reading.
		$response = wp_remote_get(
			add_query_arg(
				array(
					'action'                    => 'plugin_information',
					'request[slug]'             => $slug,
					'request[fields][sections]' => 'false',
				),
				self::ENDPOINT
			),
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'WP Debloat; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['last_updated'] ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $decoded['last_updated'] );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}
}
