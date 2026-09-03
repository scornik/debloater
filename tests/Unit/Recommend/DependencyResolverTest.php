<?php
/**
 * Tests for dependency resolution.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Recommend;

use PHPUnit\Framework\TestCase;
use Debloater\Contracts\Category;
use Debloater\Contracts\Risk;
use Debloater\Contracts\TweakKind;
use Debloater\Recommend\DependencyResolver;
use Debloater\Registry\Registry;
use Debloater\Registry\TweakDefinition;

/**
 * The resolver is where BUILD-SPEC §7.4's "two tweaks that conflict are never in
 * one plan" is enforced, so the tests are about refusals as much as acceptances.
 */
final class DependencyResolverTest extends TestCase {

	/**
	 * With nothing to resolve, nothing is accepted and nothing is rejected.
	 *
	 * @return void
	 */
	public function test_an_empty_candidate_set_resolves_to_nothing(): void {
		$resolution = $this->resolver( array() )->resolve( array() );

		$this->assertSame( array(), $resolution->accepted );
		$this->assertTrue( $resolution->isComplete() );
	}

	/**
	 * Independent tweaks are all accepted, in sorted order.
	 *
	 * @return void
	 */
	public function test_independent_tweaks_are_all_accepted(): void {
		$resolver = $this->resolver(
			array(
				$this->definition( 'core.b' ),
				$this->definition( 'core.a' ),
			)
		);

		$resolution = $resolver->resolve( array( 'core.b', 'core.a' ) );

		$this->assertSame( array( 'core.a', 'core.b' ), $resolution->accepted );
		$this->assertTrue( $resolution->isComplete() );
	}

	/**
	 * Of two conflicting candidates, exactly one survives, and the choice is
	 * deterministic rather than dependent on the order they arrived in.
	 *
	 * @return void
	 */
	public function test_conflicting_tweaks_never_both_survive(): void {
		$resolver = $this->resolver(
			array(
				$this->definition( 'core.heartbeat_interval', array( 'conflicts' => array( 'core.heartbeat_disable' ) ) ),
				$this->definition( 'core.heartbeat_disable' ),
			)
		);

		$forward  = $resolver->resolve( array( 'core.heartbeat_interval', 'core.heartbeat_disable' ) );
		$backward = $resolver->resolve( array( 'core.heartbeat_disable', 'core.heartbeat_interval' ) );

		$this->assertSame( array( 'core.heartbeat_disable' ), $forward->accepted );
		$this->assertSame( $forward->accepted, $backward->accepted, 'resolution must not depend on input order' );
		$this->assertStringContainsString( 'Conflicts with', (string) $forward->reasonFor( 'core.heartbeat_interval' ) );
	}

	/**
	 * A conflict declared on only one side still applies to both.
	 *
	 * @return void
	 */
	public function test_conflicts_apply_in_both_directions(): void {
		$resolver = $this->resolver(
			array(
				$this->definition( 'core.aaa' ),
				$this->definition( 'core.zzz', array( 'conflicts' => array( 'core.aaa' ) ) ),
			)
		);

		$resolution = $resolver->resolve( array( 'core.aaa', 'core.zzz' ) );

		$this->assertSame( array( 'core.aaa' ), $resolution->accepted );
		$this->assertNotNull( $resolution->reasonFor( 'core.zzz' ) );
	}

	/**
	 * A tweak whose requirement is not selected is excluded, with a reason
	 * naming what was missing.
	 *
	 * @return void
	 */
	public function test_a_tweak_with_an_unselected_requirement_is_excluded(): void {
		$resolver = $this->resolver(
			array(
				$this->definition( 'core.base' ),
				$this->definition( 'core.extra', array( 'requires' => array( 'core.base' ) ) ),
			)
		);

		$resolution = $resolver->resolve( array( 'core.extra' ) );

		$this->assertSame( array(), $resolution->accepted );
		$this->assertStringContainsString( 'core.base', (string) $resolution->reasonFor( 'core.extra' ) );
	}

	/**
	 * With the requirement selected too, both are accepted.
	 *
	 * @return void
	 */
	public function test_a_satisfied_requirement_is_accepted(): void {
		$resolver = $this->resolver(
			array(
				$this->definition( 'core.base' ),
				$this->definition( 'core.extra', array( 'requires' => array( 'core.base' ) ) ),
			)
		);

		$resolution = $resolver->resolve( array( 'core.extra', 'core.base' ) );

		$this->assertSame( array( 'core.base', 'core.extra' ), $resolution->accepted );
	}

	/**
	 * BUILD-SPEC §7.4: no tweak with unresolved requires enters a plan. v1 has no
	 * facts, so a fact predicate is unresolved by definition and the candidate is
	 * excluded rather than assumed satisfied.
	 *
	 * @return void
	 */
	public function test_a_fact_predicate_is_unresolved_in_v1_and_excludes_the_tweak(): void {
		$resolver = $this->resolver(
			array(
				$this->definition(
					'core.woo_only',
					array( 'requires' => array( 'fact:plugins.detected.woocommerce=true' ) )
				),
			)
		);

		$resolution = $resolver->resolve( array( 'core.woo_only' ) );

		$this->assertSame( array(), $resolution->accepted );
		$this->assertStringContainsString( 'without a scan', (string) $resolution->reasonFor( 'core.woo_only' ) );
	}

	/**
	 * An id that is not in the registry is rejected with a clear reason, not
	 * silently dropped.
	 *
	 * @return void
	 */
	public function test_an_unknown_tweak_id_is_rejected_with_a_reason(): void {
		$resolution = $this->resolver( array() )->resolve( array( 'core.nope' ) );

		$this->assertSame( array(), $resolution->accepted );
		$this->assertStringContainsString( 'core.nope', (string) $resolution->reasonFor( 'core.nope' ) );
		$this->assertStringContainsString( 'registry', (string) $resolution->reasonFor( 'core.nope' ) );
	}

	/**
	 * The same id twice resolves to one entry.
	 *
	 * @return void
	 */
	public function test_duplicate_candidates_collapse(): void {
		$resolver = $this->resolver( array( $this->definition( 'core.a' ) ) );

		$this->assertSame( array( 'core.a' ), $resolver->resolve( array( 'core.a', 'core.a' ) )->accepted );
	}

	/**
	 * No shipped tweak conflicts with another, so the only thing that can hold
	 * one back without a scan is a requirement about the site.
	 *
	 * Phase 14 added the first tweak with a fact predicate —
	 * `elementor.disable_google_fonts` requires Elementor to actually be there —
	 * so "everything resolves" stopped being the right assertion. What is still
	 * true, and is the property worth defending, is that nothing is rejected for
	 * a *conflict*: two shipped tweaks must never be mutually exclusive.
	 *
	 * A tweak held back for an unchecked fact is the documented behaviour
	 * (§7.4: no tweak with unresolved requires enters a plan), so it is asserted
	 * as such rather than worked around.
	 *
	 * @return void
	 */
	public function test_no_shipped_tweak_conflicts_with_another(): void {
		$registry   = ( new \Debloater\Registry\Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();
		$resolution = ( new DependencyResolver( $registry ) )->resolve( $registry->ids() );

		foreach ( $resolution->rejected as $tweak_id => $reason ) {
			$this->assertStringNotContainsString(
				'Conflicts with',
				$reason,
				$tweak_id . ' conflicts with another shipped tweak'
			);
			$this->assertStringContainsString(
				'cannot be checked without a scan',
				$reason,
				$tweak_id . ' was held back for a reason other than an unchecked fact'
			);
		}
	}

	/**
	 * Given the facts, a fact-gated tweak resolves like any other.
	 *
	 * @return void
	 */
	public function test_a_fact_gated_tweak_resolves_once_the_facts_are_there(): void {
		$registry = ( new \Debloater\Registry\Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();

		$facts = \Debloater\Tests\Unit\Support\Facts::busyStore(
			array( 'plugins.detected' => \Debloater\Tests\Unit\Support\Facts::detections( array( 'elementor' ) ) )
		);

		$resolution = ( new DependencyResolver( $registry, $facts ) )->resolve( $registry->ids() );

		$this->assertContains( 'elementor.disable_google_fonts', $resolution->accepted );
	}

	/**
	 * And is held back on a site without Elementor, which is the point of the
	 * predicate.
	 *
	 * @return void
	 */
	public function test_a_fact_gated_tweak_is_held_back_when_the_fact_is_false(): void {
		$registry = ( new \Debloater\Registry\Loader( DEBLOATER_TESTS_ROOT . '/registry' ) )->load();

		$resolution = ( new DependencyResolver( $registry, \Debloater\Tests\Unit\Support\Facts::freshInstall() ) )
			->resolve( $registry->ids() );

		$this->assertNotContains( 'elementor.disable_google_fonts', $resolution->accepted );
		$this->assertArrayHasKey( 'elementor.disable_google_fonts', $resolution->rejected );
	}

	/**
	 * A resolver over a registry built from the given definitions.
	 *
	 * @param array<int,TweakDefinition> $definitions Definitions.
	 * @return DependencyResolver
	 */
	private function resolver( array $definitions ): DependencyResolver {
		return new DependencyResolver( new Registry( $definitions ) );
	}

	/**
	 * A minimal config tweak definition.
	 *
	 * @param string              $id        Tweak id.
	 * @param array<string,mixed> $overrides Fields to override.
	 * @return TweakDefinition
	 */
	private function definition( string $id, array $overrides = array() ): TweakDefinition {
		return new TweakDefinition(
			$id,
			1,
			'Example',
			Category::WORDPRESS,
			TweakKind::CONFIG,
			Risk::SAFE,
			0.9,
			true,
			false,
			'runtime-handlers/core-disable-emojis.php',
			array(),
			'An example tweak.',
			array(),
			$overrides['requires'] ?? array(),
			$overrides['conflicts'] ?? array()
		);
	}
}
