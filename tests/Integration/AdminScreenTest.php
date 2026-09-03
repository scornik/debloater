<?php
/**
 * The one admin screen, and its refusal to appear anywhere else.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Admin\Screen;
use Debloater\Brand;

/**
 * BUILD-SPEC §17 Phase 8: one bundle, on our screen, and no admin notices
 * anywhere.
 *
 * The interesting assertions here are the negative ones. A plugin that loads a
 * few kilobytes on every admin page, or drops a notice on somebody's posts
 * list, has quietly become the thing it was written to remove.
 */
final class AdminScreenTest extends IntegrationTestCase {

	/**
	 * Admin screens the assets must never load on.
	 *
	 * @var array<int,string>
	 */
	private const OTHER_SCREENS = array(
		'index.php',
		'edit.php',
		'post.php',
		'post-new.php',
		'upload.php',
		'edit-tags.php',
		'plugins.php',
		'users.php',
		'tools.php',
		'options-general.php',
		'themes.php',
		'widgets.php',
		'site-health.php',
		'update-core.php',
		'toplevel_page_something-else',
		'settings_page_another-plugin',
	);

	/**
	 * Act as an administrator, with the menu registered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->plugin->resetServices();
		$this->plugin->adminScreen()->registerMenu();
	}

	/**
	 * Put the enqueue state back.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_dequeue_script( Screen::HANDLE );
		wp_dequeue_style( Screen::HANDLE );
		wp_deregister_script( Screen::HANDLE );
		wp_deregister_style( Screen::HANDLE );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The menu item exists, under our own slug and capability.
	 *
	 * @return void
	 */
	public function test_the_menu_is_registered_once(): void {
		global $menu;

		$ours = array();

		foreach ( is_array( $menu ) ? $menu : array() as $item ) {
			if ( isset( $item[2] ) && Brand::MENU_SLUG === $item[2] ) {
				$ours[] = $item;
			}
		}

		$this->assertCount( 1, $ours, 'Debloater should add exactly one menu item.' );
		$this->assertSame( Brand::NAME, $ours[0][0] );
		$this->assertSame( \Debloater\Security\Capabilities::MANAGE, $ours[0][1] );
	}

	/**
	 * The bundle loads on our screen.
	 *
	 * @return void
	 */
	public function test_the_bundle_loads_on_our_screen(): void {
		$screen = $this->plugin->adminScreen();

		$this->assertNotSame( '', $screen->hook() );

		$screen->enqueue( $screen->hook() );

		if ( ! file_exists( dirname( $this->plugin->file() ) . '/build/index.asset.php' ) ) {
			$this->markTestSkipped( 'The admin bundle has not been built; run `npm run build`.' );
		}

		$this->assertTrue( wp_script_is( Screen::HANDLE, 'enqueued' ) );
	}

	/**
	 * The bundle loads on no other screen.
	 *
	 * @return void
	 */
	public function test_the_bundle_loads_nowhere_else(): void {
		$screen = $this->plugin->adminScreen();

		foreach ( self::OTHER_SCREENS as $hook ) {
			$screen->enqueue( $hook );

			$this->assertFalse(
				wp_script_is( Screen::HANDLE, 'enqueued' ),
				sprintf( 'The Debloater bundle must not be enqueued on %s.', $hook )
			);

			$this->assertFalse(
				wp_style_is( Screen::HANDLE, 'enqueued' ),
				sprintf( 'The Debloater stylesheet must not be enqueued on %s.', $hook )
			);
		}
	}

	/**
	 * The screen renders a mount point and nothing that pretends to be content.
	 *
	 * @return void
	 */
	public function test_the_screen_renders_a_mount_point(): void {
		ob_start();

		$this->plugin->adminScreen()->render();

		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="debloater-root"', $html );
		$this->assertStringContainsString( 'screen-reader-text', $html );
	}

	/**
	 * Debloater adds no admin notices, on any screen.
	 *
	 * @return void
	 */
	public function test_no_admin_notices_are_registered(): void {
		global $wp_filter;

		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( array_keys( $callbacks ) as $identifier ) {
					$this->assertStringNotContainsStringIgnoringCase(
						'debloater',
						(string) $identifier,
						sprintf( 'Debloater must not add anything to %s.', $hook )
					);
				}
			}
		}
	}

	/**
	 * Nothing is hooked onto the front end either.
	 *
	 * The generated runtime registers hooks on the front end; the plugin's own
	 * admin code must not.
	 *
	 * @return void
	 */
	public function test_the_screen_hooks_nothing_on_the_front_end(): void {
		global $wp_filter;

		foreach ( array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_dashboard_setup' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( array_keys( $callbacks ) as $identifier ) {
					$this->assertStringNotContainsStringIgnoringCase(
						'Debloater\\Admin',
						(string) $identifier,
						sprintf( 'The admin screen must not hook %s.', $hook )
					);
				}
			}
		}
	}
}
