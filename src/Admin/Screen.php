<?php
/**
 * The one admin screen.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Admin;

use Debloater\Brand;
use Debloater\Plugin;
use Debloater\Rest\Controller;
use Debloater\Security\Capabilities;

/**
 * Debloater's entire presence in wp-admin (BUILD-SPEC §17 Phase 8).
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
	public const HANDLE = 'debloater-admin';

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
			sprintf( 'window.debloater = %s;', wp_json_encode( $this->bootstrap() ) ),
			'before'
		);
	}

	/**
	 * The bootstrap payload, after extensions have added to it.
	 *
	 * Split from bootstrapData() so the filter cannot be reached without the
	 * sanitising step below it, and so what the free plugin puts there stays
	 * readable next to what an extension may add.
	 *
	 * @return array<string,mixed>
	 */
	private function bootstrap(): array {
		$data = $this->bootstrapData();

		/**
		 * Extra panels for the dashboard.
		 *
		 * An extension returns a list of panels, each with a title and rows of
		 * label/value text. Deliberately not markup: the screen renders these
		 * as text, so nothing an extension supplies can introduce an element,
		 * a script or a style. An extension that needs its own interface should
		 * have its own screen, where it is responsible for its own escaping.
		 *
		 * Panels are rendered in the order returned, after the plugin's own
		 * content, and a malformed one is dropped rather than rendered badly.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int,array{title:string,rows:array<int,array{label:string,value:string}>}> $panels Panels to add.
		 */
		$panels = apply_filters( 'debloater_dashboard_panels', array() );

		$data['panels'] = $this->sanitisePanels( is_array( $panels ) ? $panels : array() );

		return $data;
	}

	/**
	 * Reduce whatever an extension returned to titles and text.
	 *
	 * Anything not matching the documented shape is discarded. An extension
	 * that returns markup gets its markup escaped rather than rendered, and an
	 * extension that returns nonsense gets nothing — neither can put an element
	 * on this screen, which is the only property that matters here
	 * (BUILD-SPEC §13 rule 4).
	 *
	 * @param array<int,mixed> $panels Whatever the filter produced.
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string}>}>
	 */
	private function sanitisePanels( array $panels ): array {
		$clean = array();

		foreach ( $panels as $panel ) {
			if ( ! is_array( $panel ) || ! isset( $panel['title'] ) || ! is_string( $panel['title'] ) ) {
				continue;
			}

			$rows = array();

			foreach ( is_array( $panel['rows'] ?? null ) ? $panel['rows'] : array() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['label'], $row['value'] ) ) {
					continue;
				}

				if ( ! is_scalar( $row['label'] ) || ! is_scalar( $row['value'] ) ) {
					continue;
				}

				$rows[] = array(
					'label' => wp_strip_all_tags( (string) $row['label'] ),
					'value' => wp_strip_all_tags( (string) $row['value'] ),
				);
			}

			$clean[] = array(
				'title' => wp_strip_all_tags( $panel['title'] ),
				'rows'  => $rows,
			);

			// A dashboard is a place to look, not a place to scroll. An
			// extension with more than this to say needs its own screen.
			if ( count( $clean ) >= 5 ) {
				break;
			}
		}

		return $clean;
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
			wp_die( esc_html__( 'You do not have permission to manage Debloater on this site.', 'debloater' ) );
		}

		printf(
			'<div class="wrap"><h1 class="screen-reader-text">%s</h1><div id="debloater-root">%s</div></div>',
			esc_html( Brand::NAME ),
			esc_html__( 'Loading Debloater…', 'debloater' )
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
			// The *bare* REST root, not our namespace appended to it. On a site
			// with plain permalinks — WordPress's default — rest_url() returns
			// a query-string URL like `/index.php?rest_route=/`, and a
			// namespaced root cannot be concatenated with a path without
			// producing a URL that matches no route. The namespace travels
			// separately and the client composes the two properly
			// (docs/DECISIONS.md D-0041).
			'root'          => esc_url_raw( rest_url() ),
			'namespace'     => Controller::NAMESPACE,
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
