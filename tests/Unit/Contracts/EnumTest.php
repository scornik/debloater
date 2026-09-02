<?php
/**
 * Tests for the contract enums.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\ProbeStatus;
use WPDebloat\Contracts\Risk;
use WPDebloat\Contracts\Severity;
use WPDebloat\Contracts\SnapshotLevel;
use WPDebloat\Contracts\SnapshotStatus;
use WPDebloat\Contracts\TweakKind;

/**
 * The enums encode rules from the specification, so they are tested as rules,
 * not as lists of constants.
 */
final class EnumTest extends TestCase {

	/**
	 * BUILD-SPEC §12 fixes the penalty per severity.
	 *
	 * @return void
	 */
	public function test_severity_penalties_match_the_scoring_rubric(): void {
		$this->assertSame( 0, Severity::INFO->penalty() );
		$this->assertSame( 4, Severity::LOW->penalty() );
		$this->assertSame( 10, Severity::MEDIUM->penalty() );
		$this->assertSame( 20, Severity::HIGH->penalty() );
	}

	/**
	 * BUILD-SPEC §7.4: only safe and low risk are eligible for the safe plan.
	 *
	 * @return void
	 */
	public function test_only_safe_and_low_risk_are_safe_plan_eligible(): void {
		$this->assertTrue( Risk::SAFE->isSafePlanEligible() );
		$this->assertTrue( Risk::LOW->isSafePlanEligible() );
		$this->assertFalse( Risk::MEDIUM->isSafePlanEligible() );
		$this->assertFalse( Risk::HIGH->isSafePlanEligible() );
	}

	/**
	 * BUILD-SPEC §17 Phase 4: risk is raised one level, saturating at high.
	 *
	 * @return void
	 */
	public function test_risk_raises_one_level_and_saturates(): void {
		$this->assertSame( Risk::LOW, Risk::SAFE->raised() );
		$this->assertSame( Risk::MEDIUM, Risk::LOW->raised() );
		$this->assertSame( Risk::HIGH, Risk::MEDIUM->raised() );
		$this->assertSame( Risk::HIGH, Risk::HIGH->raised() );
	}

	/**
	 * Risk::max picks the greater of two levels regardless of order.
	 *
	 * @return void
	 */
	public function test_risk_max_is_symmetric(): void {
		$this->assertSame( Risk::MEDIUM, Risk::SAFE->max( Risk::MEDIUM ) );
		$this->assertSame( Risk::MEDIUM, Risk::MEDIUM->max( Risk::SAFE ) );
		$this->assertSame( Risk::HIGH, Risk::HIGH->max( Risk::HIGH ) );
	}

	/**
	 * Only a recommend decision may put a tweak in a plan (locked decision #6).
	 *
	 * @return void
	 */
	public function test_only_recommend_is_plannable(): void {
		$this->assertTrue( Decision::RECOMMEND->isPlannable() );
		$this->assertFalse( Decision::DONT_TOUCH->isPlannable() );
		$this->assertFalse( Decision::INFO->isPlannable() );
	}

	/**
	 * Dont-touch requires a reason; info findings carry no recommendation.
	 *
	 * @return void
	 */
	public function test_decision_obligations(): void {
		$this->assertTrue( Decision::DONT_TOUCH->requiresReason() );
		$this->assertFalse( Decision::RECOMMEND->requiresReason() );
		$this->assertTrue( Decision::RECOMMEND->requiresRecommendation() );
		$this->assertFalse( Decision::INFO->allowsRecommendation() );
		$this->assertTrue( Decision::DONT_TOUCH->allowsRecommendation() );
	}

	/**
	 * BUILD-SPEC §11: NOT_TESTED must never be counted as a pass.
	 *
	 * @return void
	 */
	public function test_not_tested_does_not_count_toward_the_aggregate(): void {
		$this->assertFalse( ProbeStatus::NOT_TESTED->countsTowardAggregate() );
		$this->assertTrue( ProbeStatus::PASS->countsTowardAggregate() );
		$this->assertTrue( ProbeStatus::UNKNOWN->countsTowardAggregate() );
	}

	/**
	 * UNKNOWN is a warning, not a failure and not a pass.
	 *
	 * @return void
	 */
	public function test_unknown_is_a_warning(): void {
		$this->assertTrue( ProbeStatus::UNKNOWN->isWarning() );
		$this->assertFalse( ProbeStatus::UNKNOWN->isFailure() );
		$this->assertTrue( ProbeStatus::WARN->isWarning() );
		$this->assertTrue( ProbeStatus::FAIL->isFailure() );
	}

	/**
	 * BUILD-SPEC §15: every data tweak takes a Level B snapshot, even the
	 * non-destructive one.
	 *
	 * @return void
	 */
	public function test_data_tweaks_always_require_level_b(): void {
		$this->assertSame( SnapshotLevel::B, TweakKind::DATA->requiredSnapshotLevel() );
		$this->assertSame( SnapshotLevel::A, TweakKind::CONFIG->requiredSnapshotLevel() );
	}

	/**
	 * BUILD-SPEC §13 rules 7 and 8: only a complete snapshot may be restored or
	 * satisfy the recovery requirement.
	 *
	 * @return void
	 */
	public function test_only_complete_snapshots_are_restorable(): void {
		$this->assertTrue( SnapshotStatus::COMPLETE->isRestorable() );
		$this->assertTrue( SnapshotStatus::COMPLETE->satisfiesRecoveryRequirement() );

		foreach ( array( SnapshotStatus::PENDING, SnapshotStatus::RESTORED, SnapshotStatus::EXPIRED, SnapshotStatus::CORRUPT ) as $status ) {
			$this->assertFalse( $status->isRestorable(), $status->value . ' must not be restorable' );
			$this->assertFalse( $status->satisfiesRecoveryRequirement(), $status->value . ' must not satisfy recovery' );
		}
	}

	/**
	 * Level C is an external attestation and is deliberately absent from the
	 * snapshot level enum (locked decision #3).
	 *
	 * @return void
	 */
	public function test_snapshot_levels_are_only_a_and_b(): void {
		$this->assertSame(
			array( 'A', 'B' ),
			array_map( static fn ( SnapshotLevel $level ): string => $level->value, SnapshotLevel::cases() )
		);
	}

	/**
	 * The category list is the sub-score bucket list from BUILD-SPEC §12.
	 *
	 * @return void
	 */
	public function test_category_values(): void {
		$this->assertSame(
			array( 'wordpress', 'configuration', 'database', 'plugins', 'maintenance', 'admin', 'assets' ),
			array_map( static fn ( Category $category ): string => $category->value, Category::cases() )
		);
	}
}
