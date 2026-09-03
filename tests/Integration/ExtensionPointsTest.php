<?php
/**
 * The hooks docs/HOOKS.md promises.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Contracts\ApplyResult;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\Run;
use Debloater\Plugin;
use Debloater\Update\RegistryOrigin;

/**
 * BUILD-SPEC §17 Phase 19.
 *
 * `docs/HOOKS.md` is a contract with anything built on top of this plugin, and
 * a contract nobody checks is a wish. Each hook here is asserted to fire, with
 * the documented arguments, of the documented types.
 *
 * The filters get a second kind of test: what happens when an extension abuses
 * them. A dashboard filter that returned markup, a registry filter that
 * returned rubbish — those are the interesting cases, because they are the ones
 * where an extension's mistake could become the free plugin's problem.
 */
final class ExtensionPointsTest extends IntegrationTestCase {

	/**
	 * Clean up anything a test hooked.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'debloater_dashboard_panels' );
		remove_all_filters( 'debloater_registry_origin' );
		remove_all_actions( 'debloater_scan_complete' );
		remove_all_actions( 'debloater_apply_complete' );

		parent::tear_down();
	}

	/**
	 * `debloater_loaded` hands over the plugin.
	 *
	 * @return void
	 */
	public function test_loaded_passes_the_plugin(): void {
		$seen = null;

		add_action(
			'debloater_loaded',
			static function ( $plugin ) use ( &$seen ): void {
				$seen = $plugin;
			}
		);

		$this->plugin->register();

		$this->assertInstanceOf( Plugin::class, $seen );
		$this->assertSame( $this->plugin, $seen );
	}

	/**
	 * `debloater_scan_complete` fires with the run and the plugin.
	 *
	 * @return void
	 */
	public function test_scan_complete_passes_the_run(): void {
		$calls = array();

		add_action(
			'debloater_scan_complete',
			static function ( $run, $plugin ) use ( &$calls ): void {
				$calls[] = array( $run, $plugin );
			},
			10,
			2
		);

		$run = $this->plugin->scan();

		$this->assertCount( 1, $calls, 'the hook should fire exactly once per scan' );
		$this->assertInstanceOf( Run::class, $calls[0][0] );
		$this->assertInstanceOf( Plugin::class, $calls[0][1] );
		$this->assertSame( $run->id, $calls[0][0]->id );

		// The findings are readable from what the hook was given, which is the
		// whole reason an extension subscribes to it.
		$this->assertNotEmpty( $this->plugin->findingsOf( $calls[0][0] ) );
	}

	/**
	 * `debloater_apply_complete` fires with the result and the plan.
	 *
	 * @return void
	 */
	public function test_apply_complete_passes_the_result_and_plan(): void {
		$calls = array();

		add_action(
			'debloater_apply_complete',
			static function ( $result, $plan, $plugin ) use ( &$calls ): void {
				$calls[] = array( $result, $plan, $plugin );
			},
			10,
			3
		);

		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$this->plugin->apply( $preview->plan );

		$this->assertCount( 1, $calls );
		$this->assertInstanceOf( ApplyResult::class, $calls[0][0] );
		$this->assertInstanceOf( PreviewPlan::class, $calls[0][1] );
		$this->assertInstanceOf( Plugin::class, $calls[0][2] );

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * A panel of text reaches the bootstrap payload.
	 *
	 * @return void
	 */
	public function test_dashboard_panels_reach_the_screen(): void {
		add_filter(
			'debloater_dashboard_panels',
			static fn ( array $panels ): array => array_merge(
				$panels,
				array(
					array(
						'title' => 'From an extension',
						'rows'  => array(
							array(
								'label' => 'Something',
								'value' => 'happened',
							),
						),
					),
				)
			)
		);

		$panels = $this->panels();

		$this->assertCount( 1, $panels );
		$this->assertSame( 'From an extension', $panels[0]['title'] );
		$this->assertSame( 'Something', $panels[0]['rows'][0]['label'] );
		$this->assertSame( 'happened', $panels[0]['rows'][0]['value'] );
	}

	/**
	 * Markup from an extension does not survive.
	 *
	 * @return void
	 */
	public function test_dashboard_panels_cannot_carry_markup(): void {
		add_filter(
			'debloater_dashboard_panels',
			static fn (): array => array(
				array(
					'title' => '<script>alert(1)</script>Title',
					'rows'  => array(
						array(
							'label' => '<img src=x onerror=alert(1)>',
							'value' => '<b>bold</b>',
						),
					),
				),
			)
		);

		$panels = $this->panels();

		$this->assertCount( 1, $panels );

		$encoded = (string) wp_json_encode( $panels );

		foreach ( array( '<script', '<img', '<b>', 'onerror' ) as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$encoded,
				'An extension must not be able to put markup on our screen — found ' . $fragment
			);
		}

		// The text survives; only the markup goes.
		$this->assertStringContainsString( 'Title', $panels[0]['title'] );
	}

	/**
	 * A malformed panel is dropped rather than rendered badly.
	 *
	 * @return void
	 */
	public function test_malformed_panels_are_dropped(): void {
		add_filter(
			'debloater_dashboard_panels',
			static fn (): array => array(
				'not an array',
				array( 'no title' => true ),
				array(
					'title' => 'Kept',
					'rows'  => array(
						'not a row',
						array(
							'label' => 'ok',
							'value' => 'ok',
						),
					),
				),
			)
		);

		$panels = $this->panels();

		$this->assertCount( 1, $panels );
		$this->assertSame( 'Kept', $panels[0]['title'] );
		$this->assertCount( 1, $panels[0]['rows'] );
	}

	/**
	 * A filter returning nonsense cannot break the panel list.
	 *
	 * @return void
	 */
	public function test_a_filter_returning_nonsense_is_survivable(): void {
		add_filter( 'debloater_dashboard_panels', static fn (): string => 'nonsense' );

		$this->assertSame( array(), $this->panels() );
	}

	/**
	 * At most five panels are shown.
	 *
	 * @return void
	 */
	public function test_panels_are_capped(): void {
		add_filter(
			'debloater_dashboard_panels',
			static function (): array {
				$panels = array();

				for ( $index = 0; $index < 12; $index++ ) {
					$panels[] = array(
						'title' => 'Panel ' . $index,
						'rows'  => array(),
					);
				}

				return $panels;
			}
		);

		$this->assertCount( 5, $this->panels() );
	}

	/**
	 * The registry origin filter changes where updates come from.
	 *
	 * @return void
	 */
	public function test_registry_origin_can_be_redirected(): void {
		$elsewhere = 'https://raw.githubusercontent.com/scornik/somewhere-else';

		add_filter( 'debloater_registry_origin', static fn (): string => $elsewhere );

		$this->plugin->resetServices();

		$this->assertStringStartsWith(
			$elsewhere,
			$this->plugin->registryUpdater()->originBase()
		);
	}

	/**
	 * An origin the free plugin would refuse falls back rather than breaking.
	 *
	 * @return void
	 */
	public function test_an_unusable_origin_falls_back(): void {
		foreach ( array( 'http://insecure.example.com', 'not a url at all', '' ) as $bad ) {
			remove_all_filters( 'debloater_registry_origin' );

			add_filter( 'debloater_registry_origin', static fn (): string => $bad );

			$this->plugin->resetServices();

			$this->assertSame(
				RegistryOrigin::DEFAULT_BASE,
				$this->plugin->registryUpdater()->originBase(),
				sprintf( 'An origin of "%s" should fall back to the shipped one.', $bad )
			);
		}
	}

	/**
	 * The origin filter cannot switch updates on.
	 *
	 * @return void
	 */
	public function test_redirecting_the_origin_does_not_enable_updates(): void {
		add_filter(
			'debloater_registry_origin',
			static fn (): string => 'https://raw.githubusercontent.com/scornik/somewhere-else'
		);

		$this->plugin->resetServices();

		$this->assertFalse(
			$this->plugin->registryUpdater()->enabled(),
			'Pointing at a different channel must not opt a site into fetching from it.'
		);
	}

	/**
	 * The panels the screen would put in its bootstrap payload.
	 *
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string}>}>
	 */
	private function panels(): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$screen = $this->plugin->adminScreen();

		$bootstrap = new \ReflectionMethod( $screen, 'bootstrap' );
		$bootstrap->setAccessible( true );

		$data = $bootstrap->invoke( $screen );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'panels', $data );

		return $data['panels'];
	}
}
