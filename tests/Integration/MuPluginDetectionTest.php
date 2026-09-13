<?php
/**
 * Noticing a site's own must-use plugins.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

/**
 * `Plugin::hasCustomMuPlugins()` lowers confidence when a site runs code in
 * mu-plugins that nothing here can inspect (docs/SCORING.md).
 *
 * It globbed `wp-content/mu-plugins` until wordpress.org's round 2 review of
 * filesystem paths, and asks core's `get_mu_plugins()` now. Nothing tested it
 * either way, so this pins the behaviour the change had to keep.
 */
final class MuPluginDetectionTest extends IntegrationTestCase {

	/**
	 * The probe file this test writes, when it wrote one.
	 *
	 * @var string
	 */
	private string $probe = '';

	/**
	 * Remove the probe whatever happened.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->probe && is_file( $this->probe ) ) {
			wp_delete_file( $this->probe );
		}

		parent::tear_down();
	}

	/**
	 * A mu-plugin appearing is noticed, and disappearing is too.
	 *
	 * @return void
	 */
	public function test_a_mu_plugin_is_noticed(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->assertSame(
			array(),
			get_mu_plugins(),
			'the test site should start with no mu-plugins, or this cannot tell the probe from what was there'
		);

		$this->assertFalse( $this->plugin->hasCustomMuPlugins() );

		wp_mkdir_p( WPMU_PLUGIN_DIR );

		$this->probe = WPMU_PLUGIN_DIR . '/debloater-detection-probe.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture in the disposable test container, removed in tear_down().
		file_put_contents( $this->probe, "<?php\n/**\n * Plugin Name: Detection probe\n */\n" );

		$this->assertTrue( $this->plugin->hasCustomMuPlugins(), 'a mu-plugin in WPMU_PLUGIN_DIR should be noticed' );

		wp_delete_file( $this->probe );

		$this->assertFalse( $this->plugin->hasCustomMuPlugins(), 'and forgotten once it is gone' );
	}
}
