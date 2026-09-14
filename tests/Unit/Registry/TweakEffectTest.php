<?php
/**
 * Tests for the registry's tweak effect declarations.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\FactSet;
use Debloater\Registry\Loader;
use Debloater\Registry\TweakEffect;
use Debloater\Verify\EffectCheck;

/**
 * A declaration says something checkable, and is judged the way it reads.
 */
final class TweakEffectTest extends TestCase {

	/**
	 * `equals` compares strictly: false is not 0 and not null.
	 *
	 * @return void
	 */
	public function test_equals_is_strict(): void {
		$effect = TweakEffect::fromArray(
			array(
				'fact'   => 'wp.generator_tag',
				'equals' => false,
			)
		);

		$this->assertTrue( $effect->holds( false, array() ) );
		$this->assertFalse( $effect->holds( true, array() ) );
		$this->assertFalse( $effect->holds( 0, array() ) );
		$this->assertNull( $effect->holds( null, array() ), 'a fact that was not observed is not judged' );
	}

	/**
	 * `equals_param` reads the parameter the tweak was applied with.
	 *
	 * @return void
	 */
	public function test_equals_param_follows_the_applied_parameter(): void {
		$effect = TweakEffect::fromArray(
			array(
				'fact'         => 'wp.heartbeat_interval',
				'equals_param' => 'interval',
			)
		);

		$this->assertTrue( $effect->holds( 120, array( 'interval' => 120 ) ) );
		$this->assertFalse( $effect->holds( 60, array( 'interval' => 120 ) ) );
		$this->assertFalse( $effect->holds( 120, array() ), 'no parameter, nothing to equal' );
		$this->assertSame( '= 120', $effect->describeExpected( array( 'interval' => 120 ) ) );
	}

	/**
	 * `min` and `max_param` bound a value, and -1 ("unlimited") is outside.
	 *
	 * @return void
	 */
	public function test_a_range_excludes_unlimited(): void {
		$effect = TweakEffect::fromArray(
			array(
				'fact'      => 'wp.revisions_limit',
				'min'       => 0,
				'max_param' => 'keep',
			)
		);

		$this->assertTrue( $effect->holds( 5, array( 'keep' => 5 ) ) );
		$this->assertTrue( $effect->holds( 3, array( 'keep' => 5 ) ), 'a lower limit the site already had still holds' );
		$this->assertFalse( $effect->holds( 6, array( 'keep' => 5 ) ) );
		$this->assertFalse( $effect->holds( -1, array( 'keep' => 5 ) ) );
		$this->assertSame( '>= 0 and <= 5', $effect->describeExpected( array( 'keep' => 5 ) ) );
	}

	/**
	 * Unobservable carries a reason and nothing else, and is never judged.
	 *
	 * @return void
	 */
	public function test_unobservable_needs_a_reason_and_is_not_judged(): void {
		$effect = TweakEffect::fromArray(
			array(
				'observable' => false,
				'reason'     => 'Decided while a page is built.',
			)
		);

		$this->assertFalse( $effect->observable );
		$this->assertNull( $effect->holds( true, array() ) );
		$this->assertSame(
			array(
				'observable' => false,
				'reason'     => 'Decided while a page is built.',
			),
			$effect->toArray()
		);

		$this->expectException( ContractViolation::class );

		TweakEffect::fromArray(
			array(
				'observable' => false,
				'reason'     => '   ',
			)
		);
	}

	/**
	 * A fact with nothing to compare it to is not a declaration.
	 *
	 * @return void
	 */
	public function test_a_fact_alone_is_refused(): void {
		$this->expectException( ContractViolation::class );

		TweakEffect::fromArray( array( 'fact' => 'wp.generator_tag' ) );
	}

	/**
	 * Unobservable with an expectation attached is contradictory, and refused.
	 *
	 * @return void
	 */
	public function test_unobservable_with_a_fact_is_refused(): void {
		$this->expectException( ContractViolation::class );

		TweakEffect::fromArray(
			array(
				'observable' => false,
				'reason'     => 'Because.',
				'fact'       => 'wp.generator_tag',
			)
		);
	}

	/**
	 * The shipped declarations round-trip through the contract unchanged.
	 *
	 * @return void
	 */
	public function test_shipped_declarations_round_trip(): void {
		$registry = ( new Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();

		foreach ( $registry->all() as $definition ) {
			$path     = DEBLOATER_TESTS_ROOT . '/registry/tweaks/' . $definition->id . '.json';
			$document = json_decode( (string) file_get_contents( $path ), true );

			$this->assertSame( $document['effect'] ?? null, $definition->effect?->toArray(), $definition->id );
		}
	}

	/**
	 * The check reports each selected change by status, with what was read.
	 *
	 * @return void
	 */
	public function test_the_check_reports_each_selected_change(): void {
		$registry = ( new Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();

		$rows = EffectCheck::evaluate(
			$registry,
			array(
				'core.remove_rsd'                      => array(),
				'core.remove_generator'                => array(),
				'core.disable_dashicons_guests'        => array(),
				'woo.suppress_marketplace_suggestions' => array(),
				'db.clean_revisions'                   => array(),
				'core.not_a_tweak'                     => array(),
			),
			FactSet::fromArray(
				array(
					'wp.generator_tag' => false,
					'wp.rsd_link'      => true,
				)
			)
		);

		$by_tweak = array_column( $rows, 'status', 'tweak' );

		$this->assertSame(
			array(
				'core.disable_dashicons_guests'        => 'unobservable',
				'core.remove_generator'                => 'observed',
				'core.remove_rsd'                      => 'not_observed',
				'woo.suppress_marketplace_suggestions' => 'not_collected',
			),
			$by_tweak,
			'data tweaks and unknown ids have no row; the rest are sorted by id'
		);
	}
}
