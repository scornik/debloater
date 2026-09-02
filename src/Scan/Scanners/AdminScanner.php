<?php
/**
 * Facts about the admin screens.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;

/**
 * Collects the `admin.*` facts (BUILD-SPEC §5).
 *
 * These three numbers can only be seen from inside an admin request: notices,
 * dashboard widgets and menu items are all built up by plugins hooking
 * `admin_init`, `wp_dashboard_setup` and `admin_menu`, and none of that has
 * happened on a front-end request or in WP-CLI.
 *
 * So the counts are captured on our own admin screen load and cached in the
 * scan payload, rather than fabricated from somewhere they are not visible.
 * When a scan runs outside the admin the keys are absent, which an analyzer
 * rule reads as "not observed" rather than "zero". Phase 12 extends this to
 * attribute each notice and widget to the plugin that registered it.
 */
final class AdminScanner extends AbstractScanner {

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

		return array(
			'admin.notices.count'           => $this->noticeCount(),
			'admin.dashboard_widgets.count' => $this->dashboardWidgetCount(),
			'admin.menu_items.count'        => $this->menuItemCount(),
		);
	}

	/**
	 * How many callbacks are attached to the notice hooks.
	 *
	 * A callback is not the same thing as a visible notice — plenty check a
	 * condition and print nothing — so this is reported as what it is: the
	 * number of things that will run to decide whether to show a notice.
	 *
	 * @return int
	 */
	private function noticeCount(): int {
		$total = 0;

		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			$total += $this->callbackCount( $hook );
		}

		return $total;
	}

	/**
	 * How many dashboard widgets are registered.
	 *
	 * @return int
	 */
	private function dashboardWidgetCount(): int {
		global $wp_meta_boxes;

		if ( ! is_array( $wp_meta_boxes ) || ! isset( $wp_meta_boxes['dashboard'] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( (array) $wp_meta_boxes['dashboard'] as $context_boxes ) {
			if ( ! is_array( $context_boxes ) ) {
				continue;
			}

			foreach ( $context_boxes as $priority_boxes ) {
				if ( is_array( $priority_boxes ) ) {
					$count += count( $priority_boxes );
				}
			}
		}

		return $count;
	}

	/**
	 * How many top-level admin menu items exist.
	 *
	 * Separators are excluded: they are menu entries in the data structure but
	 * not things anyone can click, and counting them would inflate the figure.
	 *
	 * @return int
	 */
	private function menuItemCount(): int {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$class = isset( $item[4] ) ? (string) $item[4] : '';

			if ( false !== strpos( $class, 'wp-menu-separator' ) ) {
				continue;
			}

			++$count;
		}

		return $count;
	}

	/**
	 * How many callbacks are attached to a hook, across all priorities.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function callbackCount( string $hook ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}
}
