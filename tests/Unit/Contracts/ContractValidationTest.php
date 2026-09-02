<?php
/**
 * Invalid-input tests for every contract.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use WPDebloat\Contracts\ApplyResult;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\ContractViolation;
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
use WPDebloat\Tests\Unit\Support\Build;

/**
 * Contracts validate in the constructor, so an instance that exists is valid
 * (docs/DECISIONS.md D-0002). These tests pin the boundaries of "valid".
 */
final class ContractValidationTest extends TestCase {

	/**
	 * A fact key must be dot-namespaced.
	 *
	 * @return void
	 */
	public function test_fact_rejects_a_key_without_a_namespace(): void {
		$this->expectException( ContractViolation::class );

		new Fact( 'heartbeat', 15 );
	}

	/**
	 * Fact keys are lowercase; a shouted key is a typo, not a namespace.
	 *
	 * @return void
	 */
	public function test_fact_rejects_an_uppercase_key(): void {
		$this->expectException( ContractViolation::class );

		new Fact( 'WP.Heartbeat', 15 );
	}

	/**
	 * Facts hold data, not object graphs.
	 *
	 * @return void
	 */
	public function test_fact_rejects_an_object_value(): void {
		$this->expectException( ContractViolation::class );

		new Fact( 'wp.thing', new \stdClass() );
	}

	/**
	 * Nesting deeper than one level below the value is refused, so facts stay
	 * trivially serialisable and diffable.
	 *
	 * @return void
	 */
	public function test_fact_rejects_deep_nesting(): void {
		$this->expectException( ContractViolation::class );

		new Fact( 'db.autoload.top', array( array( array( 'too' => 'deep' ) ) ) );
	}

	/**
	 * A fact set cannot hold the same key twice.
	 *
	 * @return void
	 */
	public function test_fact_set_rejects_duplicate_keys(): void {
		$this->expectException( ContractViolation::class );

		new FactSet( array( new Fact( 'wp.debug', true ), new Fact( 'wp.debug', false ) ) );
	}

	/**
	 * A scanner may only write into the namespace it owns.
	 *
	 * @return void
	 */
	public function test_fact_set_enforces_namespace_ownership(): void {
		$facts = new FactSet();

		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/may only be written by the "db" namespace owner/' );

		$facts->withNamespaced( 'wp', array( 'db.size_bytes' => 1 ) );
	}

	/**
	 * Writing inside the declared namespace is allowed.
	 *
	 * @return void
	 */
	public function test_fact_set_accepts_writes_inside_the_namespace(): void {
		$facts = ( new FactSet() )->withNamespaced(
			'wp',
			array(
				'wp.debug'     => false,
				'wp.shortlink' => true,
			)
		);

		$this->assertCount( 2, $facts );
		$this->assertFalse( $facts->value( 'wp.debug' ) );
	}

	/**
	 * Facts are always iterated in sorted key order, so payloads are stable.
	 *
	 * @return void
	 */
	public function test_fact_set_is_sorted(): void {
		$facts = new FactSet(
			array(
				new Fact( 'wp.debug', true ),
				new Fact( 'db.size_bytes', 1 ),
				new Fact( 'env.php_version', '8.2.19' ),
			)
		);

		$this->assertSame( array( 'db.size_bytes', 'env.php_version', 'wp.debug' ), $facts->keys() );
	}

	/**
	 * Locked decision #5: evidence without a fact key is not evidence.
	 *
	 * @return void
	 */
	public function test_evidence_requires_a_fact_key(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/evidence without provenance/' );

		new Evidence( 'Current interval', '15 s', '' );
	}

	/**
	 * Impact is a count, never a negative one.
	 *
	 * @return void
	 */
	public function test_impact_rejects_a_negative_estimate(): void {
		$this->expectException( ContractViolation::class );

		new Impact( 'requests', -1.0, 'requests', true );
	}

	/**
	 * Impact must state its unit.
	 *
	 * @return void
	 */
	public function test_impact_requires_a_unit(): void {
		$this->expectException( ContractViolation::class );

		new Impact( 'requests', 1.0, '', true );
	}

	/**
	 * A finding must carry evidence (locked decision #5).
	 *
	 * @return void
	 */
	public function test_finding_requires_evidence(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/at least one evidence entry/' );

		$this->finding( array( 'evidence' => array() ) );
	}

	/**
	 * Confidence is a probability.
	 *
	 * @return void
	 */
	public function test_finding_rejects_confidence_above_one(): void {
		$this->expectException( ContractViolation::class );

		$this->finding( array( 'confidence' => 1.5 ) );
	}

	/**
	 * A dont_touch decision must explain itself (BUILD-SPEC §6).
	 *
	 * @return void
	 */
	public function test_dont_touch_requires_a_reason(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/required when the decision is dont_touch/' );

		$this->finding(
			array(
				'decision'        => Decision::DONT_TOUCH,
				'decision_reason' => null,
				'recommendation'  => null,
			)
		);
	}

	/**
	 * A reason on a recommend decision means the two disagree; refuse rather
	 * than let the UI show a refusal reason next to a recommendation.
	 *
	 * @return void
	 */
	public function test_reason_is_rejected_when_the_decision_is_not_dont_touch(): void {
		$this->expectException( ContractViolation::class );

		$this->finding( array( 'decision_reason' => 'because' ) );
	}

	/**
	 * A recommend decision must actually recommend something.
	 *
	 * @return void
	 */
	public function test_recommend_requires_a_recommendation(): void {
		$this->expectException( ContractViolation::class );

		$this->finding( array( 'recommendation' => null ) );
	}

	/**
	 * Info findings propose no change (BUILD-SPEC §6).
	 *
	 * @return void
	 */
	public function test_info_findings_cannot_carry_a_recommendation(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/not allowed on an info finding/' );

		$this->finding( array( 'decision' => Decision::INFO ) );
	}

	/**
	 * A dont_touch finding may still name the tweak it is refusing, so the UI
	 * can say what was declined and why.
	 *
	 * @return void
	 */
	public function test_dont_touch_may_name_the_refused_tweak(): void {
		$finding = $this->finding(
			array(
				'decision'        => Decision::DONT_TOUCH,
				'decision_reason' => 'Contact Form 7 submits through the public REST API.',
			)
		);

		$this->assertSame( 'core.heartbeat_interval', $finding->recommendedTweakId() );
		$this->assertFalse( $finding->isPlannable() );
	}

	/**
	 * BUILD-SPEC §12: dont_touch findings do not penalise the score.
	 *
	 * @return void
	 */
	public function test_dont_touch_findings_carry_no_score_penalty(): void {
		$recommended = $this->finding( array( 'severity' => Severity::HIGH ) );
		$refused     = $this->finding(
			array(
				'severity'        => Severity::HIGH,
				'decision'        => Decision::DONT_TOUCH,
				'decision_reason' => 'A detected plugin depends on this.',
			)
		);

		$this->assertSame( 20, $recommended->scorePenalty() );
		$this->assertSame( 0, $refused->scorePenalty() );
	}

	/**
	 * Unknown keys are a typo, not a field to ignore.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_unknown_keys(): void {
		$data            = Build::finding()->toArray();
		$data['sevrity'] = 'high';

		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/unknown keys: sevrity/' );

		Finding::fromArray( $data );
	}

	/**
	 * An out-of-vocabulary enum value names the allowed set in the message.
	 *
	 * @return void
	 */
	public function test_from_array_reports_allowed_enum_values(): void {
		$data         = Build::finding()->toArray();
		$data['risk'] = 'catastrophic';

		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/must be one of safe, low, medium, high/' );

		Finding::fromArray( $data );
	}

	/**
	 * Only data tweaks can delete rows; a config tweak changes hooks.
	 *
	 * @return void
	 */
	public function test_a_config_tweak_cannot_be_destructive(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/only data tweaks may be destructive/' );

		new Tweak(
			'core.thing',
			'Thing',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			true,
			true,
			new TweakParams(),
			'runtime-handlers/core-thing.php'
		);
	}

	/**
	 * A destructive tweak with no way back cannot be constructed at all.
	 *
	 * @return void
	 */
	public function test_a_destructive_tweak_must_be_reversible(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/must be reversible/' );

		new Tweak(
			'db.thing',
			'Thing',
			Category::DATABASE,
			TweakKind::DATA,
			Risk::HIGH,
			true,
			false,
			new TweakParams(),
			'WPDebloat\\Apply\\DataOperations\\Thing'
		);
	}

	/**
	 * A tweak cannot conflict with itself.
	 *
	 * @return void
	 */
	public function test_a_tweak_cannot_conflict_with_itself(): void {
		$this->expectException( ContractViolation::class );

		new Tweak(
			'core.thing',
			'Thing',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			false,
			true,
			new TweakParams(),
			'runtime-handlers/core-thing.php',
			array(),
			array( 'core.thing' )
		);
	}

	/**
	 * Parameter names are lower_snake_case, because they are emitted into
	 * generated PHP.
	 *
	 * @return void
	 */
	public function test_tweak_params_reject_a_bad_name(): void {
		$this->expectException( ContractViolation::class );

		new TweakParams( array( 'Interval-Seconds' => 60 ) );
	}

	/**
	 * Parameters are scalars or flat lists of scalars, never object graphs.
	 *
	 * @return void
	 */
	public function test_tweak_params_reject_nested_arrays(): void {
		$this->expectException( ContractViolation::class );

		new TweakParams( array( 'widgets' => array( array( 'nested' => true ) ) ) );
	}

	/**
	 * Parameters are sorted, so identical selections compile identically.
	 *
	 * @return void
	 */
	public function test_tweak_params_are_sorted(): void {
		$params = new TweakParams(
			array(
				'z' => 1,
				'a' => 2,
			)
		);

		$this->assertSame(
			array(
				'a' => 2,
				'z' => 1,
			),
			$params->toArray()
		);
	}

	/**
	 * A malformed tweak id in a recommendation is refused at the boundary.
	 *
	 * @return void
	 */
	public function test_recommendation_rejects_a_bad_tweak_id(): void {
		$this->expectException( ContractViolation::class );

		new Recommendation( 'DROP TABLE' );
	}

	/**
	 * BUILD-SPEC §7.4: two conflicting tweaks are never in one plan. The
	 * constructor refuses, so no code path can produce one.
	 *
	 * @return void
	 */
	public function test_plan_refuses_conflicting_tweaks(): void {
		$first  = new Tweak(
			'core.heartbeat_interval',
			'Set heartbeat interval',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::LOW,
			false,
			true,
			new TweakParams( array( 'interval' => 60 ) ),
			'runtime-handlers/core-heartbeat-interval.php',
			array(),
			array( 'core.heartbeat_disable' )
		);
		$second = Build::tweak( 'core.heartbeat_disable' );

		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/conflicting tweaks/' );

		new PreviewPlan( array( $first, $second ) );
	}

	/**
	 * A tweak cannot appear twice in a plan.
	 *
	 * @return void
	 */
	public function test_plan_refuses_duplicate_tweaks(): void {
		$this->expectException( ContractViolation::class );

		new PreviewPlan( array( Build::tweak(), Build::tweak() ) );
	}

	/**
	 * The destructive flag and required snapshot levels are derived, so a
	 * persisted plan cannot lie about them.
	 *
	 * @return void
	 */
	public function test_plan_derives_destructive_and_snapshot_levels(): void {
		$plan = new PreviewPlan( array( Build::tweak(), Build::destructiveTweak() ) );

		$this->assertTrue( $plan->destructive );
		$this->assertSame( array( SnapshotLevel::A, SnapshotLevel::B ), $plan->snapshot_levels );

		$config_only = Build::plan();

		$this->assertFalse( $config_only->destructive );
		$this->assertSame( array( SnapshotLevel::A ), $config_only->snapshot_levels );
	}

	/**
	 * An empty plan needs no snapshot, because it changes nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_plan_requires_no_snapshot(): void {
		$plan = new PreviewPlan( array() );

		$this->assertTrue( $plan->isEmpty() );
		$this->assertSame( array(), $plan->snapshot_levels );
		$this->assertFalse( $plan->destructive );
	}

	/**
	 * A persisted plan claiming to be non-destructive while containing a
	 * destructive tweak is rejected.
	 *
	 * @return void
	 */
	public function test_plan_from_array_rejects_a_forged_destructive_flag(): void {
		$data                = ( new PreviewPlan( array( Build::destructiveTweak() ) ) )->toArray();
		$data['destructive'] = false;

		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/is derived from the plan contents/' );

		PreviewPlan::fromArray( $data );
	}

	/**
	 * BUILD-SPEC §11: the aggregate is the worst probe result.
	 *
	 * @return void
	 */
	public function test_verification_aggregate_is_the_worst_result(): void {
		$this->assertSame(
			ProbeStatus::PASS,
			( new VerificationResult( array( Build::probe( 'home' ), Build::probe( 'rest' ) ) ) )->status
		);

		$this->assertSame(
			ProbeStatus::WARN,
			( new VerificationResult(
				array( Build::probe( 'home' ), Build::probe( 'rest', ProbeStatus::WARN ) )
			) )->status
		);

		$this->assertSame(
			ProbeStatus::FAIL,
			( new VerificationResult(
				array( Build::probe( 'home', ProbeStatus::WARN ), Build::probe( 'rest', ProbeStatus::FAIL ) )
			) )->status
		);
	}

	/**
	 * UNKNOWN counts as a warning, so a blocked loopback cannot read as a pass.
	 *
	 * @return void
	 */
	public function test_unknown_probes_produce_a_warning_aggregate(): void {
		$result = new VerificationResult(
			array( Build::probe( 'home' ), Build::probe( 'rest', ProbeStatus::UNKNOWN ) )
		);

		$this->assertSame( ProbeStatus::WARN, $result->status );
	}

	/**
	 * NOT_TESTED probes are listed but never counted.
	 *
	 * @return void
	 */
	public function test_not_tested_probes_are_listed_but_not_counted(): void {
		$result = new VerificationResult(
			array( Build::probe( 'home' ), Build::probe( 'woo_checkout', ProbeStatus::NOT_TESTED ) )
		);

		$this->assertSame( ProbeStatus::PASS, $result->status );
		$this->assertCount( 1, $result->notTested() );
		$this->assertSame( 'woo_checkout', $result->notTested()[0]->probe );
	}

	/**
	 * A verification that checked nothing has proved nothing.
	 *
	 * @return void
	 */
	public function test_a_verification_with_no_counted_probes_is_unknown(): void {
		$this->assertSame( ProbeStatus::UNKNOWN, ( new VerificationResult( array() ) )->status );

		$this->assertSame(
			ProbeStatus::UNKNOWN,
			( new VerificationResult( array( Build::probe( 'woo_cart', ProbeStatus::NOT_TESTED ) ) ) )->status
		);
	}

	/**
	 * A probe may not report twice in one verification.
	 *
	 * @return void
	 */
	public function test_verification_rejects_duplicate_probes(): void {
		$this->expectException( ContractViolation::class );

		new VerificationResult( array( Build::probe( 'home' ), Build::probe( 'home', ProbeStatus::FAIL ) ) );
	}

	/**
	 * A probe result must say something; an empty message is not a result.
	 *
	 * @return void
	 */
	public function test_probe_result_requires_a_message(): void {
		$this->expectException( ContractViolation::class );

		new ProbeResult( 'home', ProbeStatus::PASS, '   ' );
	}

	/**
	 * BUILD-SPEC §13 rule 7: a snapshot from another site is never restorable.
	 *
	 * @return void
	 */
	public function test_snapshot_refuses_a_foreign_site(): void {
		$snapshot = Build::snapshot();

		$this->assertTrue( $snapshot->isRestorableOn( str_repeat( 'a', 64 ) ) );
		$this->assertFalse( $snapshot->isRestorableOn( str_repeat( 'd', 64 ) ) );
	}

	/**
	 * A corrupt snapshot is never restorable, even on the right site.
	 *
	 * @return void
	 */
	public function test_a_corrupt_snapshot_is_never_restorable(): void {
		$snapshot = Build::snapshot()->withStatus( SnapshotStatus::CORRUPT );

		$this->assertFalse( $snapshot->isRestorableOn( str_repeat( 'a', 64 ) ) );
	}

	/**
	 * A Level A snapshot with no configuration has nothing to restore to.
	 *
	 * @return void
	 */
	public function test_level_a_snapshot_requires_config(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/must carry the configuration/' );

		new Snapshot(
			null,
			41,
			SnapshotLevel::A,
			'2026-09-02 18:34:00',
			str_repeat( 'a', 64 ),
			'0.1.0',
			null,
			0,
			0,
			'db',
			null,
			str_repeat( 'c', 64 ),
			SnapshotStatus::COMPLETE
		);
	}

	/**
	 * A file-backed snapshot must say where the file is.
	 *
	 * @return void
	 */
	public function test_file_storage_requires_a_path(): void {
		$this->expectException( ContractViolation::class );

		new Snapshot(
			null,
			41,
			SnapshotLevel::B,
			'2026-09-02 18:34:00',
			str_repeat( 'a', 64 ),
			'0.1.0',
			null,
			10,
			1024,
			'file',
			null,
			str_repeat( 'c', 64 ),
			SnapshotStatus::COMPLETE
		);
	}

	/**
	 * A checksum that is not a sha256 digest is refused.
	 *
	 * @return void
	 */
	public function test_snapshot_rejects_a_malformed_checksum(): void {
		$this->expectException( ContractViolation::class );

		new Snapshot(
			null,
			41,
			SnapshotLevel::B,
			'2026-09-02 18:34:00',
			str_repeat( 'a', 64 ),
			'0.1.0',
			null,
			0,
			0,
			'db',
			null,
			'not-a-digest',
			SnapshotStatus::COMPLETE
		);
	}

	/**
	 * A snapshot item with no payload cannot restore anything.
	 *
	 * @return void
	 */
	public function test_snapshot_item_requires_a_payload(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/cannot be restored/' );

		new SnapshotItem( 'transient', '_transient_x', array() );
	}

	/**
	 * Object keys longer than the column would be truncated, losing the row.
	 *
	 * @return void
	 */
	public function test_snapshot_item_rejects_an_overlong_key(): void {
		$this->expectException( ContractViolation::class );

		new SnapshotItem( 'option', str_repeat( 'x', 192 ), array( 'option_value' => 'x' ) );
	}

	/**
	 * A failed run must say what failed.
	 *
	 * @return void
	 */
	public function test_a_failed_run_requires_an_error(): void {
		$this->expectException( ContractViolation::class );
		$this->expectExceptionMessageMatches( '/a failure must say what failed/' );

		new ApplyResult( 41, RunState::ROLLED_BACK );
	}

	/**
	 * A skipped tweak must say why it was skipped.
	 *
	 * @return void
	 */
	public function test_a_skipped_tweak_requires_a_reason(): void {
		$this->expectException( ContractViolation::class );

		new ApplyResult( 41, RunState::COMMITTED, array(), array( 'core.disable_emojis' => '' ) );
	}

	/**
	 * The context home URL must be a URL.
	 *
	 * @return void
	 */
	public function test_context_rejects_a_non_url_home(): void {
		$this->expectException( ContractViolation::class );

		new Context( 'example.test', '/var/www/', '/var/www/wp-content', '/p', '6.8.1', '8.2.19', '0.1.0', 'cli' );
	}

	/**
	 * The actor vocabulary is closed, so journal rows stay parseable.
	 *
	 * @return void
	 */
	public function test_context_rejects_an_unknown_actor(): void {
		$this->expectException( ContractViolation::class );

		new Context( 'https://example.test', '/var/www/', '/var/www/wp-content', '/p', '6.8.1', '8.2.19', '0.1.0', 'root' );
	}

	/**
	 * Paths are normalised so Windows and Unix produce the same site hash and
	 * the same generated file paths.
	 *
	 * @return void
	 */
	public function test_context_normalises_paths(): void {
		$context = new Context(
			'https://example.test/',
			'C:\\sites\\example\\',
			'C:\\sites\\example\\wp-content\\',
			'C:\\sites\\example\\wp-content\\plugins\\wp-debloat\\',
			'6.8.1',
			'8.2.19',
			'0.1.0',
			'user:12'
		);

		$this->assertSame( 'https://example.test', $context->home_url );
		$this->assertSame( 'C:/sites/example/', $context->abspath );
		$this->assertSame( 'C:/sites/example/wp-content/wpdebloat/runtime.php', $context->runtimeFile() );
		$this->assertSame( 'C:/sites/example/wp-content/mu-plugins', $context->muPluginsDir() );
		$this->assertSame( 12, $context->actorUserId() );
	}

	/**
	 * Everything the plugin generates lives under one directory
	 * (BUILD-SPEC §13 rule 6).
	 *
	 * @return void
	 */
	public function test_generated_paths_are_all_under_the_runtime_directory(): void {
		$context = Build::context();
		$root    = $context->runtimeDir();

		foreach ( array( $context->runtimeFile(), $context->runtimeLockFile(), $context->backupsDir() ) as $path ) {
			$this->assertStringStartsWith( $root . '/', $path );
		}
	}

	/**
	 * The site hash is stable and depends on both the URL and the path.
	 *
	 * @return void
	 */
	public function test_site_hash_depends_on_url_and_path(): void {
		$a = Build::context();
		$b = new Context(
			'https://other.test',
			$a->abspath,
			$a->content_dir,
			$a->plugin_dir,
			$a->wp_version,
			$a->php_version,
			$a->plugin_version,
			$a->actor
		);

		$this->assertSame( $a->siteHash(), Build::context()->siteHash() );
		$this->assertNotSame( $a->siteHash(), $b->siteHash() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a->siteHash() );
	}

	/**
	 * Build a finding with the given fields overridden.
	 *
	 * @param array<string,mixed> $overrides Fields to override.
	 * @return Finding
	 */
	private function finding( array $overrides = array() ): Finding {
		$defaults = array(
			'id'                    => 'wp.heartbeat.aggressive',
			'category'              => Category::WORDPRESS,
			'severity'              => Severity::LOW,
			'risk'                  => Risk::LOW,
			'confidence'            => 0.91,
			'title'                 => 'Heartbeat frequency may be unnecessarily aggressive',
			'summary'               => 'Heartbeat polls every 15 s.',
			'why'                   => 'Heartbeat fires admin-ajax requests on a timer.',
			'evidence'              => array( Build::evidence() ),
			'impact'                => Build::impact(),
			'decision'              => Decision::RECOMMEND,
			'decision_reason'       => null,
			'recommendation'        => Build::recommendation(),
			'undo'                  => true,
			'requires'              => array(),
			'conflicts'             => array(),
			'dependencies_detected' => 0,
		);

		$fields = array_merge( $defaults, $overrides );

		return new Finding(
			$fields['id'],
			$fields['category'],
			$fields['severity'],
			$fields['risk'],
			$fields['confidence'],
			$fields['title'],
			$fields['summary'],
			$fields['why'],
			$fields['evidence'],
			$fields['impact'],
			$fields['decision'],
			$fields['decision_reason'],
			$fields['recommendation'],
			$fields['undo'],
			$fields['requires'],
			$fields['conflicts'],
			$fields['dependencies_detected']
		);
	}
}
