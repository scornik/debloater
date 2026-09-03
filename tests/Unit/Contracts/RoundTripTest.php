<?php
/**
 * Every contract must survive a toArray()/fromArray() round trip unchanged.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Debloater\Contracts\ApplyResult;
use Debloater\Contracts\Context;
use Debloater\Contracts\Evidence;
use Debloater\Contracts\Fact;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Impact;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\Recommendation;
use Debloater\Contracts\Snapshot;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\Tweak;
use Debloater\Contracts\TweakParams;
use Debloater\Contracts\VerificationResult;
use Debloater\Tests\Unit\Support\Build;

/**
 * Contracts are persisted into runs.payload and read back later, so a lossy
 * round trip is a data-loss bug, not a cosmetic one (docs/DECISIONS.md D-0002).
 */
final class RoundTripTest extends TestCase {

	/**
	 * Contracts and the builder that produces a populated instance of each.
	 *
	 * @return array<string,array{0:object,1:class-string}>
	 */
	public static function contractProvider(): array {
		return array(
			'Fact'               => array( new Fact( 'wp.heartbeat_interval', 15 ), Fact::class ),
			'Fact with map'      => array( new Fact( 'plugins.detected', array( 'woocommerce' => true ) ), Fact::class ),
			'Evidence'           => array( Build::evidence(), Evidence::class ),
			'Impact'             => array( Build::impact(), Impact::class ),
			'Recommendation'     => array( Build::recommendation(), Recommendation::class ),
			'Finding'            => array( Build::finding(), Finding::class ),
			'Finding dont_touch' => array( Build::dontTouchFinding(), Finding::class ),
			'Tweak'              => array( Build::tweak(), Tweak::class ),
			'Tweak destructive'  => array( Build::destructiveTweak(), Tweak::class ),
			'PreviewPlan'        => array( Build::plan(), PreviewPlan::class ),
			'ProbeResult'        => array( Build::probe(), ProbeResult::class ),
			'VerificationResult' => array( Build::verification(), VerificationResult::class ),
			'ApplyResult'        => array( Build::applyResult(), ApplyResult::class ),
			'Snapshot'           => array( Build::snapshot(), Snapshot::class ),
			'SnapshotItem'       => array( Build::snapshotItem(), SnapshotItem::class ),
			'Context'            => array( Build::context(), Context::class ),
			'FactSet'            => array( Build::facts(), FactSet::class ),
			'TweakParams'        => array(
				new TweakParams(
					array(
						'interval' => 60,
						'flags'    => array( 'a', 'b' ),
					)
				),
				TweakParams::class,
			),
		);
	}

	/**
	 * fromArray( x->toArray() ) must equal x.
	 *
	 * @dataProvider contractProvider
	 * @param object       $subject   A populated contract instance.
	 * @param class-string $contract  The contract class.
	 * @return void
	 */
	public function test_round_trip_preserves_the_value( object $subject, string $contract ): void {
		$array = $subject->toArray();

		$rebuilt = $contract::fromArray( $array );

		$this->assertEquals( $subject, $rebuilt );
		$this->assertSame( $array, $rebuilt->toArray() );
	}

	/**
	 * The array form must survive JSON encoding, since that is how it is stored.
	 *
	 * @dataProvider contractProvider
	 * @param object       $subject  A populated contract instance.
	 * @param class-string $contract The contract class.
	 * @return void
	 */
	public function test_round_trip_survives_json( object $subject, string $contract ): void {
		$json = self::encode( $subject->toArray() );

		$decoded = json_decode( $json, true );

		$this->assertIsArray( $decoded );

		$rebuilt = $contract::fromArray( $decoded );

		$this->assertEquals( $subject, $rebuilt );
	}

	/**
	 * Encode a value the way the plugin persists it.
	 *
	 * @param array<array-key,mixed> $value Value to encode.
	 * @return string
	 */
	private static function encode( array $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			throw new \RuntimeException( 'Failed to encode contract: ' . json_last_error_msg() );
		}

		return $json;
	}
}
