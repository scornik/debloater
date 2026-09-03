<?php
/**
 * Which of core's optional output features are switched on.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;

/**
 * Collects the feature half of the `wp.*` facts (BUILD-SPEC §5).
 *
 * Each of these is measured by asking whether core's own callback is still
 * attached to the hook that emits it. That is the same question the tweak will
 * change, so the fact and the change are talking about exactly the same thing —
 * and a feature already removed by a theme, another plugin, or a previous WP
 * Debloat run reports as off rather than being recommended a second time.
 *
 * Two facts cannot be answered this way and are deliberately omitted rather
 * than guessed:
 *
 * - `wp.dashicons_frontend` — core only enqueues dashicons on the front end for
 *   the admin bar, and a theme may enqueue it for guests. Neither is visible
 *   from the request this scanner runs in.
 * - `wp.emojis_enabled` is answerable, because the emoji script is attached to
 *   `wp_head` on every request including this one.
 *
 * Phase 13's asset scan fetches real pages and can answer the first properly.
 * Until then the key is absent, and an analyzer rule that needs it reports that
 * it could not evaluate rather than assuming a default.
 */
final class CoreFeatureScanner extends AbstractScanner {

	/**
	 * Fact key to the core callback that produces the feature.
	 */
	private const HEAD_FEATURES = array(
		'wp.emojis_enabled' => array( 'wp_head', 'print_emoji_detection_script' ),
		'wp.embeds_enabled' => array( 'wp_head', 'wp_oembed_add_discovery_links' ),
		'wp.rss_enabled'    => array( 'wp_head', 'feed_links' ),
		'wp.generator_tag'  => array( 'wp_head', 'wp_generator' ),
		'wp.rsd_link'       => array( 'wp_head', 'rsd_link' ),
		'wp.shortlink'      => array( 'wp_head', 'wp_shortlink_wp_head' ),
	);

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'wp';
	}

	/**
	 * Collect feature facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		$facts = array();

		foreach ( self::HEAD_FEATURES as $key => $hook ) {
			$facts[ $key ] = false !== has_action( $hook[0], $hook[1] );
		}

		$facts['wp.self_pingbacks'] = $this->selfPingbacksEnabled();
		$facts['wp.jquery_migrate'] = $this->jqueryMigrateLoaded();

		$dashicons = $this->dashiconsOnFrontend();

		if ( null !== $dashicons ) {
			$facts['wp.dashicons_frontend'] = $dashicons;
		}

		return $facts;
	}

	/**
	 * Whether the site will still ping itself when a post links internally.
	 *
	 * Core has no switch for this; it happens unless something filters the ping
	 * list. So the observation is whether anything is listening on `pre_ping`.
	 *
	 * @return bool
	 */
	private function selfPingbacksEnabled(): bool {
		return false === has_action( 'pre_ping' );
	}

	/**
	 * Whether jQuery Migrate will load with jQuery.
	 *
	 * Core registers the `jquery` bundle with `jquery-core` and `jquery-migrate`
	 * as its dependencies. Removing Migrate means re-registering `jquery`
	 * without it, so inspecting the registered dependencies answers the question
	 * for whatever the site currently does.
	 *
	 * @return bool
	 */
	private function jqueryMigrateLoaded(): bool {
		$scripts = wp_scripts();

		if ( ! isset( $scripts->registered['jquery'] ) ) {
			return false;
		}

		$dependencies = $scripts->registered['jquery']->deps;

		return is_array( $dependencies ) && in_array( 'jquery-migrate', $dependencies, true );
	}

	/**
	 * Whether dashicons loads on the front end, or null when it cannot be told
	 * from this request.
	 *
	 * A registered front-end style declaring dashicons as a dependency is a
	 * definite yes. Anything short of that is genuinely unknown until a page is
	 * fetched, and reporting a guess as a fact is exactly what this layer exists
	 * not to do.
	 *
	 * @return bool|null
	 */
	private function dashiconsOnFrontend(): ?bool {
		if ( is_admin() ) {
			// Every admin request enqueues dashicons, so nothing observed here
			// says anything about the front end.
			return null;
		}

		$styles = wp_styles();

		foreach ( $styles->registered as $handle => $style ) {
			if ( 'dashicons' === $handle ) {
				continue;
			}

			if ( is_array( $style->deps ) && in_array( 'dashicons', $style->deps, true ) ) {
				return true;
			}
		}

		return wp_style_is( 'dashicons', 'enqueued' );
	}
}
