<?php
/**
 * The one admin screen.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Admin;

use WPDebloat\Brand;
use WPDebloat\Plugin;
use WPDebloat\Rest\Controller;
use WPDebloat\Security\Capabilities;

/**
 * WP Debloat's entire presence in wp-admin (BUILD-SPEC §17 Phase 8).
 *
 * One top-level menu item, one screen, one script bundle, and nothing else. No
 * admin notices, no dashboard widget, no pointers, no banner asking for a
 * review.
 *
 * That restraint is the product. A plugin whose stated purpose is removing
 * weight from other people's sites cannot then put its own weight on every
 * screen of the admin — and a plugin that nags on pages you did not ask it
 * about has already lost the argument it is trying to make.
 *
 * The assets load on this screen and no other, which an integration test
 * asserts by walking every other admin page.
 */
final class Screen {

	/**
	 * Handle for the script bundle.
	 */
	public const HANDLE = 'wp-debloat-admin';

	/**
	 * Where the built assets live, relative to the plugin directory.
	 */
	public const BUILD_DIR = 'build';

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * The screen id WordPress gives our page, once it has one.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook the screen up.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the menu item.
	 *
	 * @return void
	 */
	public function registerMenu(): void {
		$hook = add_menu_page(
			Brand::NAME,
			Brand::NAME,
			Capabilities::MANAGE,
			Brand::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-filter',
			// Below Settings, out of the way of the things people use daily.
			81
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Load the bundle, on this screen only.
	 *
	 * @param string $hook_suffix The screen being loaded.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		$asset = $this->assetManifest();

		if ( array() === $asset ) {
			return;
		}

		$base = plugin_dir_url( $this->plugin->file() ) . self::BUILD_DIR . '/';

		wp_enqueue_script(
			self::HANDLE,
			$base . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::HANDLE, Brand::TEXT_DOMAIN );

		// `@wordpress/scripts` emits the stylesheet as style-index.css, beside the
		// script rather than inside it, so a page that fails to run the bundle at
		// least does not also lose its styling.
		if ( file_exists( $this->buildPath( 'style-index.css' ) ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$base . 'style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_add_inline_script(
			self::HANDLE,
			sprintf( 'window.wpDebloat = %s;', wp_json_encode( $this->bootstrapData() ) ),
			'before'
		);
	}

	/**
	 * Render the mount point.
	 *
	 * Everything else is React. The heading exists so the page has one before
	 * the bundle runs, and so a screen reader announces something sensible if
	 * the bundle never arrives.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::currentUserCanManage() ) {
			wp_die( esc_html__( 'You do not have permission to manage WP Debloat on this site.', 'wp-debloat' ) );
		}

		printf(
			'<div class="wrap"><h1 class="screen-reader-text">%s</h1><div id="wpdebloat-root">%s</div></div>',
			esc_html( Brand::NAME ),
			esc_html__( 'Loading WP Debloat…', 'wp-debloat' )
		);
	}

	/**
	 * The screen id, for tests and for anything that needs to compare against it.
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->hook;
	}

	/**
	 * What the bundle needs to know before it can ask anything.
	 *
	 * @return array<string,mixed>
	 */
	private function bootstrapData(): array {
		return array(
			'root'          => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'pluginVersion' => $this->plugin->version(),
			'canManage'     => Capabilities::currentUserCanManage(),
			'screen'        => Brand::MENU_SLUG,
		);
	}

	/**
	 * The dependency and version manifest `@wordpress/scripts` writes.
	 *
	 * Without it the bundle would have to guess which WordPress packages it
	 * needs, and a guess that is wrong by one package is a blank screen.
	 *
	 * @return array{dependencies:array<int,string>,version:string}|array{}
	 */
	private function assetManifest(): array {
		$path = $this->buildPath( 'index.asset.php' );

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$asset = require $path;

		if ( ! is_array( $asset ) || ! is_array( $asset['dependencies'] ?? null ) ) {
			return array();
		}

		return array(
			'dependencies' => array_values( array_filter( $asset['dependencies'], 'is_string' ) ),
			'version'      => is_string( $asset['version'] ?? null ) ? $asset['version'] : $this->plugin->version(),
		);
	}

	/**
	 * Absolute path to a built file.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	private function buildPath( string $file ): string {
		return dirname( $this->plugin->file() ) . '/' . self::BUILD_DIR . '/' . $file;
	}
}
