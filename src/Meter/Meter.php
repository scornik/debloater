<?php
/**
 * Counting what is actually there, before and after.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Meter;

use Throwable;
use Debloater\Contracts\Context;
use Debloater\Storage\State;
use Debloater\Verify\HttpClient;

/**
 * The v1 metrics from BUILD-SPEC §12.
 *
 * A separate pipeline from Scanner → Analyzer → Engine, and deliberately so: it
 * never feeds the Debloat Score, and the Score never feeds it. The Meter exists
 * to prove a delta — measure, change something, measure again, subtract.
 *
 * Everything it measures is a count or a size. There is no timing metric, and
 * there will not be one: page-load time on somebody else's host depends on their
 * hosting, their network and their visitors, and a plugin that attributed it to
 * its own changes would be claiming credit it cannot support (§12).
 *
 * A metric that cannot be taken says so. `Measurement::unavailable()` carries
 * the reason, and the comparison reports "not measured" rather than a fall to
 * zero — which is what a naive implementation produces on a site whose loopback
 * is blocked, and which would be the plugin's most flattering possible lie.
 */
final class Meter {

	/**
	 * Metric names, in the order §12 lists them.
	 */
	public const METRICS = array(
		'frontend.requests',
		'frontend.scripts.count',
		'frontend.styles.count',
		'frontend.head_bytes',
		'frontend.external_hosts',
		'db.autoload_bytes',
		'db.revisions',
		'db.transients_expired',
		'cron.events',
		'admin.notices',
		'admin_ajax_requests_per_hour',
	);

	/**
	 * Units, by metric name.
	 */
	private const UNITS = array(
		'frontend.requests'            => 'requests',
		'frontend.scripts.count'       => 'scripts',
		'frontend.styles.count'        => 'stylesheets',
		'frontend.head_bytes'          => 'bytes',
		'frontend.external_hosts'      => 'hosts',
		'db.autoload_bytes'            => 'bytes',
		'db.revisions'                 => 'rows',
		'db.transients_expired'        => 'rows',
		'cron.events'                  => 'events',
		'admin.notices'                => 'notices',
		'admin_ajax_requests_per_hour' => 'requests per hour',
	);

	/**
	 * WordPress's default Heartbeat interval, in seconds.
	 */
	private const DEFAULT_HEARTBEAT = 15;

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The HTTP client, shared with verification.
	 *
	 * @var HttpClient
	 */
	private HttpClient $http;

	/**
	 * Plugin state, for the selected Heartbeat interval.
	 *
	 * @var State
	 */
	private State $state;

	/**
	 * Constructor.
	 *
	 * @param Context    $context Site context.
	 * @param HttpClient $http    HTTP client.
	 * @param State      $state   Plugin state.
	 */
	public function __construct( Context $context, HttpClient $http, State $state ) {
		$this->context = $context;
		$this->http    = $http;
		$this->state   = $state;
	}

	/**
	 * Take a full reading.
	 *
	 * @return MeasurementSet
	 */
	public function measure(): MeasurementSet {
		$pages   = $this->fetchPages();
		$targets = array();

		foreach ( $pages as $url => $page ) {
			if ( null !== $page ) {
				$targets[] = $url;
			}
		}

		return new MeasurementSet(
			array_merge( $this->frontEndMeasurements( $pages ), $this->siteMeasurements( $pages ) ),
			$targets
		);
	}

	/**
	 * The unit a metric is reported in.
	 *
	 * @param string $metric Metric name.
	 * @return string
	 */
	public static function unitOf( string $metric ): string {
		return self::UNITS[ $metric ] ?? '';
	}

	/**
	 * Fetch the three pages §12 measures on.
	 *
	 * @return array<string,PageMetrics|null> URL to parsed page, or null when it
	 *                                        could not be fetched.
	 */
	private function fetchPages(): array {
		$pages = array();

		foreach ( $this->pageUrls() as $kind => $url ) {
			if ( '' === $url ) {
				continue;
			}

			$response = 'admin' === $kind ? $this->http->getAsActor( $url ) : $this->http->get( $url );

			$pages[ $url ] = $response->reachable() && $response->isSuccess()
				? new PageMetrics( $response->body, $url )
				: null;
		}

		return $pages;
	}

	/**
	 * The three pages, by kind.
	 *
	 * @return array<string,string>
	 */
	private function pageUrls(): array {
		return array(
			'home'         => $this->context->home_url,
			'content_page' => $this->newestPermalink(),
			'admin'        => admin_url(),
		);
	}

	/**
	 * The newest published post or page, or '' when there is none.
	 *
	 * @return string
	 */
	private function newestPermalink(): string {
		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);

		if ( array() === $posts ) {
			return '';
		}

		$permalink = get_permalink( $posts[0] );

		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * The metrics read from the fetched pages.
	 *
	 * Summed across the pages that could be fetched, because the metric is "what
	 * this site asks browsers to load", not "what one page does". A page that
	 * could not be fetched is left out of the sum and named in the reason, so a
	 * before-and-after taken over different pages cannot silently produce a
	 * delta.
	 *
	 * @param array<string,PageMetrics|null> $pages Fetched pages.
	 * @return array<int,Measurement>
	 */
	private function frontEndMeasurements( array $pages ): array {
		$available = array_filter( $pages );

		if ( array() === $available ) {
			$reason = __(
				'None of the pages could be fetched, so nothing about them was measured.',
				'debloater'
			);

			return array(
				Measurement::unavailable( 'frontend.requests', self::UNITS['frontend.requests'], $reason ),
				Measurement::unavailable( 'frontend.scripts.count', self::UNITS['frontend.scripts.count'], $reason ),
				Measurement::unavailable( 'frontend.styles.count', self::UNITS['frontend.styles.count'], $reason ),
				Measurement::unavailable( 'frontend.head_bytes', self::UNITS['frontend.head_bytes'], $reason ),
				Measurement::unavailable( 'frontend.external_hosts', self::UNITS['frontend.external_hosts'], $reason ),
				Measurement::unavailable( 'admin.notices', self::UNITS['admin.notices'], $reason ),
			);
		}

		$target   = implode( ', ', array_keys( $available ) );
		$requests = 0;
		$scripts  = 0;
		$styles   = 0;
		$bytes    = 0;
		$hosts    = 0;
		$notices  = 0;

		foreach ( $available as $page ) {
			$requests += $page->requests();
			$scripts  += $page->scripts();
			$styles   += $page->styles();
			$bytes    += $page->headBytes();
			$hosts    += $page->externalHosts();
			$notices  += $page->adminNotices();
		}

		return array(
			new Measurement( 'frontend.requests', (float) $requests, self::UNITS['frontend.requests'], $target ),
			new Measurement( 'frontend.scripts.count', (float) $scripts, self::UNITS['frontend.scripts.count'], $target ),
			new Measurement( 'frontend.styles.count', (float) $styles, self::UNITS['frontend.styles.count'], $target ),
			new Measurement( 'frontend.head_bytes', (float) $bytes, self::UNITS['frontend.head_bytes'], $target ),
			new Measurement( 'frontend.external_hosts', (float) $hosts, self::UNITS['frontend.external_hosts'], $target ),
			new Measurement( 'admin.notices', (float) $notices, self::UNITS['admin.notices'], $target ),
		);
	}

	/**
	 * The metrics read from the database and the schedule.
	 *
	 * @param array<string,PageMetrics|null> $pages Fetched pages, for the derived metric.
	 * @return array<int,Measurement>
	 */
	private function siteMeasurements( array $pages ): array {
		unset( $pages );

		return array(
			$this->fromDatabase( 'db.autoload_bytes', fn (): float => $this->autoloadBytes() ),
			$this->fromDatabase( 'db.revisions', fn (): float => $this->revisions() ),
			$this->fromDatabase( 'db.transients_expired', fn (): float => $this->expiredTransients() ),
			$this->fromDatabase( 'cron.events', fn (): float => $this->cronEvents() ),
			$this->adminAjaxPerHour(),
		);
	}

	/**
	 * Run a database measurement, turning a failure into an honest absence.
	 *
	 * @param string          $metric  Metric name.
	 * @param callable():float $reader The measurement.
	 * @return Measurement
	 */
	private function fromDatabase( string $metric, callable $reader ): Measurement {
		try {
			return new Measurement( $metric, $reader(), self::UNITS[ $metric ] );
		} catch ( Throwable $error ) {
			return Measurement::unavailable( $metric, self::UNITS[ $metric ], $error->getMessage() );
		}
	}

	/**
	 * Total size of autoloaded options.
	 *
	 * @return float
	 */
	private function autoloadBytes(): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Measuring what is there now; a cached answer would defeat the purpose.
		return (float) $wpdb->get_var(
			"SELECT COALESCE( SUM( LENGTH( option_value ) ), 0 ) FROM {$wpdb->options} WHERE autoload IN ( 'yes', 'on', 'auto', 'auto-on' )"
		);
	}

	/**
	 * Post revisions.
	 *
	 * @return float
	 */
	private function revisions(): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows; there is nothing to cache.
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision' )
		);
	}

	/**
	 * Transients whose expiry has passed.
	 *
	 * @return float
	 */
	private function expiredTransients(): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows; there is nothing to cache.
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
	}

	/**
	 * Scheduled cron events.
	 *
	 * @return float
	 */
	private function cronEvents(): float {
		$cron  = _get_cron_array();
		$count = 0;

		foreach ( is_array( $cron ) ? $cron : array() as $hooks ) {
			foreach ( is_array( $hooks ) ? $hooks : array() as $events ) {
				$count += is_array( $events ) ? count( $events ) : 0;
			}
		}

		return (float) $count;
	}

	/**
	 * Admin-ajax requests per hour, derived from the Heartbeat interval.
	 *
	 * §12 defines this as the interval multiplied by the number of active
	 * administrators. It is derived rather than observed, and it is the one
	 * metric here that is not a direct count — which is why it reports the
	 * interval it used, so the arithmetic can be checked.
	 *
	 * @return Measurement
	 */
	private function adminAjaxPerHour(): Measurement {
		$interval = $this->heartbeatInterval();

		if ( $interval <= 0 ) {
			return Measurement::unavailable(
				'admin_ajax_requests_per_hour',
				self::UNITS['admin_ajax_requests_per_hour'],
				__( 'The Heartbeat interval could not be read.', 'debloater' )
			);
		}

		$admins = max( 1, $this->activeAdministrators() );

		return new Measurement(
			'admin_ajax_requests_per_hour',
			round( ( 3600 / $interval ) * $admins, 1 ),
			self::UNITS['admin_ajax_requests_per_hour'],
			sprintf(
				/* translators: 1: seconds between beats, 2: number of administrators. */
				__( 'every %1$d seconds, %2$d signed-in administrators', 'debloater' ),
				$interval,
				$admins
			)
		);
	}

	/**
	 * The Heartbeat interval in force.
	 *
	 * Reads our own selection first, because a change that has been applied is
	 * in the generated runtime rather than in an option, and the filter would
	 * only fire on a request where the runtime had loaded.
	 *
	 * @return int
	 */
	private function heartbeatInterval(): int {
		$selection = $this->state->selection();
		$params    = $selection['core.heartbeat_interval'] ?? null;

		if ( is_array( $params ) && is_numeric( $params['interval'] ?? null ) ) {
			return (int) $params['interval'];
		}

		/** This filter is documented in wp-includes/js/heartbeat.js's PHP counterpart. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, read rather than introduced.
		$settings = apply_filters( 'heartbeat_settings', array() );

		if ( is_array( $settings ) && is_numeric( $settings['interval'] ?? null ) ) {
			return (int) $settings['interval'];
		}

		return self::DEFAULT_HEARTBEAT;
	}

	/**
	 * Administrators who have signed in within the last day.
	 *
	 * @return int
	 */
	private function activeAdministrators(): int {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 50,
			)
		);

		return count( $administrators );
	}
}
