<?php
/**
 * Builders for valid contract instances used across the unit tests.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Support;

use WPDebloat\Contracts\ApplyResult;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Evidence;
use WPDebloat\Contracts\Fact;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Impact;
use WPDebloat\Contracts\PreviewPlan;
use WPDebloat\Contracts\ProbeResult;
use WPDebloat\Contracts\ProbeStatus;
use WPDebloat\Contracts\Recommendation;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\RunState;
use WPDebloat\Contracts\Severity;
use WPDebloat\Contracts\Snapshot;
use WPDebloat\Contracts\SnapshotItem;
use WPDebloat\Contracts\SnapshotLevel;
use WPDebloat\Contracts\SnapshotStatus;
use WPDebloat\Contracts\Tweak;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Contracts\TweakParams;
use WPDebloat\Contracts\VerificationResult;

/**
 * Minimal valid instances, so a test that is about one field does not have to
 * spell out every other field.
 */
final class Build {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * A valid evidence entry.
	 *
	 * @param string $fact Fact key.
	 * @return Evidence
	 */
	public static function evidence( string $fact = 'wp.heartbeat_interval' ): Evidence {
		return new Evidence( 'Current interval', '15 s', $fact );
	}

	/**
	 * A valid impact.
	 *
	 * @return Impact
	 */
	public static function impact(): Impact {
		return new Impact( 'admin_ajax_requests_per_hour', 960.0, 'requests', true );
	}

	/**
	 * A valid recommendation.
	 *
	 * @param string             $tweak_id Tweak id.
	 * @param array<string,mixed> $params  Parameters.
	 * @return Recommendation
	 */
	public static function recommendation( string $tweak_id = 'core.heartbeat_interval', array $params = array( 'interval' => 60 ) ): Recommendation {
		return new Recommendation( $tweak_id, new TweakParams( $params ) );
	}

	/**
	 * A valid finding with a recommend decision.
	 *
	 * @param string $id Finding id.
	 * @return Finding
	 */
	public static function finding( string $id = 'wp.heartbeat.aggressive' ): Finding {
		return new Finding(
			$id,
			Category::WORDPRESS,
			Severity::LOW,
			Risk::LOW,
			0.91,
			'Heartbeat frequency may be unnecessarily aggressive',
			'Heartbeat polls every 15 s. With 4 admins and no collaborative editing, 60 s is sufficient.',
			'Heartbeat fires admin-ajax requests on a timer for autosave and post locking.',
			array( self::evidence() ),
			self::impact(),
			Decision::RECOMMEND,
			null,
			self::recommendation(),
			true
		);
	}

	/**
	 * A valid dont-touch finding.
	 *
	 * @param string $id Finding id.
	 * @return Finding
	 */
	public static function dontTouchFinding( string $id = 'wp.rest.public' ): Finding {
		return new Finding(
			$id,
			Category::CONFIGURATION,
			Severity::MEDIUM,
			Risk::HIGH,
			0.99,
			'REST API is publicly reachable',
			'The REST API answers unauthenticated requests.',
			'Restricting REST would be an option on some sites, but not this one.',
			array( new Evidence( 'Contact Form 7 active', true, 'plugins.detected.cf7' ) ),
			null,
			Decision::DONT_TOUCH,
			'Contact Form 7 submits through the public REST API; restricting it would break every form.',
			null,
			false
		);
	}

	/**
	 * A valid config tweak.
	 *
	 * @param string $id   Tweak id.
	 * @param Risk   $risk Risk level.
	 * @return Tweak
	 */
	public static function tweak( string $id = 'core.disable_emojis', Risk $risk = Risk::SAFE ): Tweak {
		return new Tweak(
			$id,
			'Disable emoji scripts',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			$risk,
			false,
			true,
			new TweakParams(),
			'runtime-handlers/core-disable-emojis.php',
			array(),
			array(),
			array( 'home' )
		);
	}

	/**
	 * A valid destructive data tweak.
	 *
	 * @param string $id Tweak id.
	 * @return Tweak
	 */
	public static function destructiveTweak( string $id = 'db.clean_revisions' ): Tweak {
		return new Tweak(
			$id,
			'Delete old post revisions',
			Category::DATABASE,
			TweakKind::DATA,
			Risk::MEDIUM,
			true,
			true,
			new TweakParams( array( 'keep_per_post' => 5 ) ),
			'WPDebloat\\Apply\\DataOperations\\RevisionsCleanup',
			array(),
			array(),
			array( 'home', 'admin' )
		);
	}

	/**
	 * A valid preview plan.
	 *
	 * @return PreviewPlan
	 */
	public static function plan(): PreviewPlan {
		return new PreviewPlan(
			array( self::tweak(), self::tweak( 'core.remove_generator' ) ),
			array( 'The emoji script will no longer load on the front end.' ),
			array( 'Nothing will be deleted.' )
		);
	}

	/**
	 * A valid probe result.
	 *
	 * @param string      $probe  Probe name.
	 * @param ProbeStatus $status Status.
	 * @return ProbeResult
	 */
	public static function probe( string $probe = 'home', ProbeStatus $status = ProbeStatus::PASS ): ProbeResult {
		return new ProbeResult( $probe, $status, 'Home page rendered.', array( 'http_status' => 200 ) );
	}

	/**
	 * A valid verification result.
	 *
	 * @return VerificationResult
	 */
	public static function verification(): VerificationResult {
		return new VerificationResult( array( self::probe(), self::probe( 'rest' ) ) );
	}

	/**
	 * A valid apply result.
	 *
	 * @return ApplyResult
	 */
	public static function applyResult(): ApplyResult {
		return new ApplyResult(
			41,
			RunState::COMMITTED,
			array( 'core.disable_emojis' ),
			array( 'core.remove_shortlink' => 'Conflicts with an already selected tweak.' ),
			array( 7 ),
			self::verification(),
			null,
			array( 'Baseline measurement was skipped: loopback request timed out.' )
		);
	}

	/**
	 * A valid Level A snapshot.
	 *
	 * @return Snapshot
	 */
	public static function snapshot(): Snapshot {
		return new Snapshot(
			7,
			41,
			SnapshotLevel::A,
			'2026-09-02 18:34:00',
			str_repeat( 'a', 64 ),
			'0.1.0',
			array(
				'selection'    => array(),
				'runtime_hash' => str_repeat( 'b', 64 ),
			),
			0,
			0,
			'db',
			null,
			str_repeat( 'c', 64 ),
			SnapshotStatus::COMPLETE
		);
	}

	/**
	 * A valid snapshot item.
	 *
	 * @return SnapshotItem
	 */
	public static function snapshotItem(): SnapshotItem {
		return new SnapshotItem(
			'transient',
			'_transient_wpdebloat_demo',
			array(
				'option_name'  => '_transient_wpdebloat_demo',
				'option_value' => 'cached',
				'expires_at'   => 1756838040,
			)
		);
	}

	/**
	 * A valid fact set covering several namespaces.
	 *
	 * @return FactSet
	 */
	public static function facts(): FactSet {
		return new FactSet(
			array(
				new Fact( 'wp.heartbeat_interval', 15 ),
				new Fact( 'users.admin_count', 4 ),
				new Fact(
					'plugins.detected',
					array(
						'woocommerce' => true,
						'elementor'   => false,
					)
				),
				new Fact( 'env.host_vendor', 'unknown' ),
			)
		);
	}

	/**
	 * A valid site context.
	 *
	 * @return Context
	 */
	public static function context(): Context {
		return new Context(
			'https://example.test',
			'/var/www/html/',
			'/var/www/html/wp-content',
			'/var/www/html/wp-content/plugins/wp-debloat',
			'6.8.1',
			'8.2.19',
			'0.1.0',
			'user:1'
		);
	}
}
