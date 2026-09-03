<?php
/**
 * What the Elementor audit is allowed to claim.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Debloater\Analyze\Rules\ElementorAuditRule;
use Debloater\Contracts\Decision;
use Debloater\Contracts\FactSet;
use Debloater\Tests\Unit\Support\Facts;

/**
 * BUILD-SPEC §17 Phase 14.
 *
 * A site with six addon packs and a hundred and fifty widgets produces a number
 * that will make somebody want to uninstall something. These tests are mostly
 * about the sentence around the number.
 */
final class ElementorAuditTest extends TestCase {

	/**
	 * Two addon packs and a known set of used widgets reproduce the counts
	 * exactly.
	 *
	 * @return void
	 */
	public function test_the_counts_are_exact_on_a_known_fixture(): void {
		$finding = ( new ElementorAuditRule() )->analyze( $this->site() );

		$this->assertNotNull( $finding );

		// 3 packs registering 4 + 3 + 2 = 9 widgets; 3 of them appear in the
		// saved designs; 6 are therefore unaccounted for.
		$this->assertStringContainsString( '3 addon packs', $finding->title );
		$this->assertStringContainsString( '9 widgets available', $finding->title );
		$this->assertStringContainsString( '3 detected in use', $finding->title );
		$this->assertStringContainsString( '6 potentially unused', $finding->title );
	}

	/**
	 * The word is "potentially", and it is not negotiable.
	 *
	 * @return void
	 */
	public function test_the_wording_never_hardens_into_unused(): void {
		$finding = ( new ElementorAuditRule() )->analyze( $this->site() );

		$this->assertNotNull( $finding );

		$this->assertStringContainsString( 'potentially unused', $finding->title );

		$text = $finding->title . ' ' . $finding->summary . ' ' . $finding->why;

		$this->assertSame(
			0,
			preg_match( '/(?<!potentially )unused/', $text ),
			'"unused" may only ever appear after "potentially"'
		);
	}

	/**
	 * It proposes nothing, ever.
	 *
	 * Elementor has no supported way to unregister another plugin's widget, and
	 * doing it unsupported breaks the editor for every page already built with
	 * one.
	 *
	 * @return void
	 */
	public function test_it_never_proposes_disabling_a_widget(): void {
		$finding = ( new ElementorAuditRule() )->analyze( $this->site() );

		$this->assertNotNull( $finding );
		$this->assertSame( Decision::INFO, $finding->decision );
		$this->assertNull( $finding->recommendation );
		$this->assertSame( 0, $finding->scorePenalty() );
	}

	/**
	 * A dynamic tag lowers the confidence, because it hides widgets from the
	 * count.
	 *
	 * @return void
	 */
	public function test_a_dynamic_tag_lowers_confidence(): void {
		$rule = new ElementorAuditRule();

		$clean = $rule->analyze( $this->site() );
		$muddy = $rule->analyze( $this->site( array( 'elementor.dynamic_tags' => true ) ) );

		$this->assertNotNull( $clean );
		$this->assertNotNull( $muddy );

		$this->assertSame( ElementorAuditRule::CONFIDENCE_CLEAN, $clean->confidence );
		$this->assertLessThan( $clean->confidence, $muddy->confidence );
		$this->assertStringContainsString( 'dynamic tags', $muddy->why );
		$this->assertStringContainsString( 'floor rather than a total', $muddy->why );
	}

	/**
	 * Each hiding signal costs something, and they accumulate.
	 *
	 * @return void
	 */
	public function test_every_hiding_signal_costs_confidence(): void {
		$rule = new ElementorAuditRule();

		$previous = ElementorAuditRule::CONFIDENCE_CLEAN + 1.0;
		$applied  = array();

		foreach ( array(
			'elementor.dynamic_tags' => true,
			'elementor.shortcodes'   => true,
			'elementor.custom_code'  => true,
			'elementor.templates'    => 4,
		) as $fact => $value ) {
			$applied[ $fact ] = $value;

			$finding = $rule->analyze( $this->site( $applied ) );

			$this->assertNotNull( $finding );
			$this->assertLessThan( $previous, $finding->confidence, $fact . ' should have cost something' );

			$previous = $finding->confidence;
		}

		$this->assertGreaterThanOrEqual(
			ElementorAuditRule::CONFIDENCE_FLOOR,
			$previous,
			'the counts themselves are exact, so confidence has a floor'
		);
	}

	/**
	 * A widget in a design whose plugin has since been removed is not counted
	 * against the catalogue.
	 *
	 * It is genuinely in the design and genuinely not registered. Counting it
	 * as "in use" would make the arithmetic lie in the reassuring direction.
	 *
	 * @return void
	 */
	public function test_a_widget_whose_plugin_is_gone_is_not_counted_as_in_use(): void {
		$finding = ( new ElementorAuditRule() )->analyze(
			$this->site(
				array(
					'elementor.widgets_in_use' => array( 'heading', 'image', 'button', 'a-widget-from-a-removed-plugin' ),
				)
			)
		);

		$this->assertNotNull( $finding );
		$this->assertStringContainsString( '3 detected in use', $finding->title );
	}

	/**
	 * A site without Elementor is evaluated and says nothing.
	 *
	 * "We looked and there is no Elementor" is a different answer from "nobody
	 * looked", and only the first should leave the rule silent rather than
	 * unevaluated.
	 *
	 * @return void
	 */
	public function test_a_site_without_elementor_is_evaluated_and_silent(): void {
		$rule  = new ElementorAuditRule();
		$facts = Facts::freshInstall();

		$this->assertTrue( $rule->supports( $facts ), 'presence is observed, so the rule can evaluate' );
		$this->assertNull( $rule->analyze( $facts ) );
	}

	/**
	 * A catalogue that could not be read produces no audit at all.
	 *
	 * An unread catalogue is absent, not empty. Treating it as empty would
	 * report every widget on the site as unaccounted for, which is the most
	 * alarming possible way to be wrong.
	 *
	 * @return void
	 */
	public function test_an_unreadable_catalogue_produces_no_audit(): void {
		$facts = FactSet::fromArray(
			array(
				'elementor.present'        => true,
				'elementor.widgets_in_use' => array( 'heading' ),
				'elementor.documents'      => 3,
			)
		);

		$this->assertNull( ( new ElementorAuditRule() )->analyze( $facts ) );
	}

	/**
	 * An Elementor site with three addon packs and a known set of used widgets.
	 *
	 * @param array<string,mixed> $overrides Facts to override.
	 * @return FactSet
	 */
	private function site( array $overrides = array() ): FactSet {
		$widgets = array();

		foreach ( array(
			'elementor'                      => array( 'heading', 'image', 'text-editor', 'button' ),
			'essential-addons-for-elementor' => array( 'eael-post-grid', 'eael-fancy-text', 'eael-countdown' ),
			'happy-elementor-addons'         => array( 'happy-card', 'happy-slider' ),
		) as $source => $names ) {
			foreach ( $names as $name ) {
				$widgets[] = array(
					'name'   => $name,
					'source' => $source,
				);
			}
		}

		return FactSet::fromArray(
			array_merge(
				array(
					'elementor.present'           => true,
					'elementor.pro'               => false,
					'elementor.version'           => '3.23.4',
					'elementor.widgets'           => $widgets,
					'elementor.widgets_available' => count( $widgets ),
					'elementor.packs'             => array(
						array(
							'source' => 'elementor',
							'count'  => 4,
						),
						array(
							'source' => 'essential-addons-for-elementor',
							'count'  => 3,
						),
						array(
							'source' => 'happy-elementor-addons',
							'count'  => 2,
						),
					),
					'elementor.widgets_in_use'    => array( 'heading', 'image', 'button' ),
					'elementor.documents'         => 12,
					'elementor.templates'         => 0,
					'elementor.dynamic_tags'      => false,
					'elementor.shortcodes'        => false,
					'elementor.custom_code'       => false,
					'elementor.fonts'             => array( 'Roboto' ),
					'elementor.experiments'       => array(),
				),
				$overrides
			)
		);
	}
}
