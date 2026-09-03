<?php
/**
 * Facts about the admin screens.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;
use WPDebloat\Registry\Registry;
use WPDebloat\Scan\AdminSources;
use WP_Hook;

/**
 * Collects the `admin.*` facts (BUILD-SPEC §5, §17 Phase 12).
 *
 * None of this can be seen from anywhere but inside an admin request. Notices,
 * dashboard widgets and menu items are built up by plugins hooking
 * `admin_init`, `wp_dashboard_setup` and `admin_menu`, and none of that has
 * happened on a front-end request or in WP-CLI.
 *
 * So everything here is captured on our own admin screen load. When a scan runs
 * outside the admin the keys are absent, which a rule reads as "not observed"
 * rather than "zero" — the two look identical in a count and mean opposite
 * things.
 *
 * Phase 12 adds attribution. A count says the admin is busy; a count *per
 * plugin* says which plugin is making it busy, which is the difference between
 * a number that alarms and one that can be acted on. Attribution that failed is
 * reported as `unknown` rather than guessed at.
 *
 * WP Debloat's own callbacks are excluded from nothing. If this plugin ever
 * registers an admin notice, it will appear in its own facts, which is the
 * point: the promise not to nag is easier to keep when breaking it is visible.
 */
final class AdminScanner extends AbstractScanner {

	/**
	 * The hooks a notice can be printed from.
	 */
	private const NOTICE_HOOKS = array(
		'admin_notices',
		'all_admin_notices',
		'network_admin_notices',
		'user_admin_notices',
	);

	/**
	 * The registry, for the notice allowlist.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Registry holding the notice allowlist.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'admin';
	}

	/**
	 * Collect admin facts, when they are visible at all.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		if ( ! is_admin() ) {
			// Reporting zeroes here would be worse than reporting nothing: a
			// rule cannot tell an honest zero from an unobserved one.
			return array();
		}

		$notices = $this->notices();
		$widgets = $this->dashboardWidgets();
		$menu    = $this->menuItems();
		$scripts = $this->assets( 'scripts' );
		$styles  = $this->assets( 'styles' );

		return array(
			'admin.notices.count'           => count( $notices ),
			'admin.notices'                 => $notices,
			'admin.dashboard_widgets.count' => count( $widgets ),
			'admin.dashboard_widgets'       => $widgets,
			'admin.menu_items.count'        => count( $menu ),
			'admin.menu_items'              => $menu,
			'admin.scripts.count'           => count( $scripts ),
			'admin.scripts'                 => $scripts,
			'admin.styles.count'            => count( $styles ),
			'admin.styles'                  => $styles,
			'admin.notice_vendors'          => $this->noticeVendors( $notices ),
			'admin.welcome_panel'           => $this->welcomePanelShown(),
			'admin.update_nag'              => $this->hasCallback( 'admin_notices', 'update_nag' )
				|| $this->hasCallback( 'network_admin_notices', 'update_nag' ),
		);
	}

	/**
	 * One row per notice callback, with who registered it.
	 *
	 * A callback is not the same thing as a visible notice — plenty check a
	 * condition and print nothing — so this is what it says it is: the things
	 * that will run to decide whether to show one.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function notices(): array {
		$rows = array();

		foreach ( self::NOTICE_HOOKS as $hook ) {
			foreach ( $this->callbacks( $hook ) as $callback ) {
				$rows[] = array(
					'hook'   => $hook,
					'source' => AdminSources::of( $callback ),
				);
			}
		}

		return $this->sorted( $rows, array( 'source', 'hook' ) );
	}

	/**
	 * One row per registered dashboard widget.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function dashboardWidgets(): array {
		global $wp_meta_boxes;

		if ( ! is_array( $wp_meta_boxes ) || ! isset( $wp_meta_boxes['dashboard'] ) ) {
			return array();
		}

		$rows = array();

		foreach ( (array) $wp_meta_boxes['dashboard'] as $context_boxes ) {
			if ( ! is_array( $context_boxes ) ) {
				continue;
			}

			foreach ( $context_boxes as $priority_boxes ) {
				if ( ! is_array( $priority_boxes ) ) {
					continue;
				}

				foreach ( $priority_boxes as $id => $box ) {
					// A widget WordPress has been asked to remove is left in the
					// array as `false`. It is not registered any more, and
					// counting it would report a widget nobody can see.
					if ( ! is_array( $box ) ) {
						continue;
					}

					$rows[] = array(
						'id'     => (string) $id,
						'source' => AdminSources::of( $box['callback'] ?? null ),
					);
				}
			}
		}

		return $this->sorted( $rows, array( 'source', 'id' ) );
	}

	/**
	 * One row per top-level admin menu item.
	 *
	 * Separators are excluded: they are entries in the data structure but not
	 * things anyone can click, and counting them would inflate the figure.
	 *
	 * Attribution goes through the page's own hook name, which is where
	 * `add_menu_page()` puts the callback it was given. A menu item added
	 * without one — a link to an existing file, for instance — cannot be
	 * attributed this way and is reported as `unknown`.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function menuItems(): array {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return array();
		}

		$rows = array();

		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$class = isset( $item[4] ) ? (string) $item[4] : '';

			if ( false !== strpos( $class, 'wp-menu-separator' ) ) {
				continue;
			}

			$slug = isset( $item[2] ) ? (string) $item[2] : '';

			$rows[] = array(
				'slug'   => $slug,
				'source' => $this->menuSource( $slug ),
			);
		}

		return $this->sorted( $rows, array( 'source', 'slug' ) );
	}

	/**
	 * Who registered the page behind a menu slug.
	 *
	 * @param string $slug Menu slug.
	 * @return string
	 */
	private function menuSource( string $slug ): string {
		if ( '' === $slug ) {
			return AdminSources::UNKNOWN;
		}

		// A slug that is a core admin file is core, and has no page callback to
		// reflect on.
		if ( false !== strpos( $slug, '.php' ) && file_exists( ABSPATH . 'wp-admin/' . $slug ) ) {
			return AdminSources::CORE;
		}

		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			return AdminSources::UNKNOWN;
		}

		foreach ( $this->callbacks( get_plugin_page_hookname( $slug, '' ) ) as $callback ) {
			$source = AdminSources::of( $callback );

			if ( AdminSources::UNKNOWN !== $source ) {
				return $source;
			}
		}

		return AdminSources::UNKNOWN;
	}

	/**
	 * One row per enqueued script or style, with who it belongs to.
	 *
	 * @param string $kind Either "scripts" or "styles".
	 * @return array<int,array<string,string>>
	 */
	private function assets( string $kind ): array {
		$registry = 'scripts' === $kind ? wp_scripts() : wp_styles();

		$rows = array();

		foreach ( $registry->queue as $handle ) {
			$item = $registry->registered[ $handle ] ?? null;

			if ( null === $item ) {
				continue;
			}

			// A registered handle's src is false when it is an alias — a bundle
			// of dependencies with no file of its own.
			$src = is_string( $item->src ) ? $item->src : '';

			$rows[] = array(
				'handle' => (string) $handle,
				'source' => $this->assetSource( $src ),
			);
		}

		return $this->sorted( $rows, array( 'source', 'handle' ) );
	}

	/**
	 * Who an asset URL belongs to.
	 *
	 * @param string $src Registered source, which may be a URL, a path, or empty.
	 * @return string
	 */
	private function assetSource( string $src ): string {
		if ( '' === $src ) {
			// A handle with no source of its own is an alias — a dependency
			// bundle. It belongs to whatever depends on it, which is not a
			// question this can answer.
			return AdminSources::UNKNOWN;
		}

		$content_url = content_url();

		if ( 0 === strpos( $src, $content_url ) ) {
			return AdminSources::fromPath( WP_CONTENT_DIR . substr( $src, strlen( $content_url ) ) );
		}

		if ( 0 === strpos( $src, includes_url() ) || 0 === strpos( $src, admin_url() ) || 0 === strpos( $src, '/wp-' ) ) {
			return AdminSources::CORE;
		}

		return AdminSources::UNKNOWN;
	}

	/**
	 * One row per allowlisted vendor that actually registered a notice here.
	 *
	 * The allowlist is registry knowledge and the notices are an observation;
	 * this is the intersection, which is the only part that is about this site.
	 * A vendor on the allowlist with nothing registered does not appear, and a
	 * plugin printing notices that is not on the allowlist does not appear
	 * either — it is still counted in `admin.notices`, it simply cannot be
	 * silenced by WP Debloat.
	 *
	 * @param array<int,array<string,string>> $notices Notice rows.
	 * @return array<int,array<string,string>>
	 */
	private function noticeVendors( array $notices ): array {
		$present = array();

		foreach ( $notices as $notice ) {
			$present[ $notice['source'] ] = true;
		}

		$rows = array();

		foreach ( $this->registry->noticeVendors() as $vendor ) {
			foreach ( $vendor->sources as $source ) {
				if ( isset( $present[ $source ] ) ) {
					$rows[] = array(
						'vendor' => $vendor->slug,
						'name'   => $vendor->name,
						'source' => $source,
					);
				}
			}
		}

		return $this->sorted( $rows, array( 'vendor', 'source' ) );
	}

	/**
	 * Whether the dashboard welcome panel is still being printed.
	 *
	 * @return bool
	 */
	private function welcomePanelShown(): bool {
		return $this->hasCallback( 'welcome_panel', 'wp_welcome_panel' );
	}

	/**
	 * Whether a named function is attached to a hook.
	 *
	 * @param string $hook Hook name.
	 * @param string $name Function name.
	 * @return bool
	 */
	private function hasCallback( string $hook, string $name ): bool {
		foreach ( $this->callbacks( $hook ) as $callback ) {
			if ( $callback === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every callback attached to a hook, across all priorities.
	 *
	 * @param string $hook Hook name.
	 * @return array<int,mixed>
	 */
	private function callbacks( string $hook ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return array();
		}

		$found = array();

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( isset( $registered['function'] ) ) {
					$found[] = $registered['function'];
				}
			}
		}

		return $found;
	}

	/**
	 * Rows in a fixed order, so two scans of an unchanged site match.
	 *
	 * @param array<int,array<string,string>> $rows   Rows to sort.
	 * @param array<int,string>               $fields Fields to sort by, in order.
	 * @return array<int,array<string,string>>
	 */
	private function sorted( array $rows, array $fields ): array {
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $fields ): int {
				foreach ( $fields as $field ) {
					$comparison = strcmp( $left[ $field ] ?? '', $right[ $field ] ?? '' );

					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}

				return 0;
			}
		);

		return $rows;
	}
}
