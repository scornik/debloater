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
 * and a feature already removed by a theme, another plugin, or a previous
 * Debloater run reports as off rather than being recommended a second time.
 * `TweakEffectTest` holds that claim to every config tweak: apply it, scan, and
 * the finding that recommended it must be gone.
 *
 * `wp.dashicons_frontend` is not collected. Whether visitors download the icon
 * font is decided while a front-end page is built, and a scan never runs in
 * one: it runs over REST or WP-CLI, where core has registered a dozen styles
 * that depend on dashicons whatever any visitor loads. Until 0.5.0 this scanner
 * reported that as "dashicons loads on the front end" — true on every site
 * (`D-0079`). `DashiconsFrontendRule` now reads the pages the asset scan fetched
 * as a logged-out visitor.
 */
final class CoreFeatureScanner extends AbstractScanner {

	/**
	 * Fact key to the core callback that produces the feature, and the priority
	 * whose presence means "on".
	 *
	 * A null priority means any priority. Embeds is the one that cannot use it:
	 * WordPress registers `wp_oembed_add_discovery_links` at 4 *and* at 10, and
	 * the function returns early on `wp_head` when it is no longer attached at 10
	 * (`wp-includes/embed.php`, "short-circuit if a plugin has removed the action
	 * at the original priority"). Removing it at 10 is how core documents turning
	 * it off, and the attachment at 4 survives that. Asking "at any priority"
	 * therefore reported embeds as on for ever, on a site where no discovery link
	 * was being printed.
	 */
	private const HEAD_FEATURES = array(
		'wp.emojis_enabled' => array( 'wp_head', 'print_emoji_detection_script', null ),
		'wp.embeds_enabled' => array( 'wp_head', 'wp_oembed_add_discovery_links', 10 ),
		'wp.rss_enabled'    => array( 'wp_head', 'feed_links', null ),
		'wp.generator_tag'  => array( 'wp_head', 'wp_generator', null ),
		'wp.rsd_link'       => array( 'wp_head', 'rsd_link', null ),
		'wp.shortlink'      => array( 'wp_head', 'wp_shortlink_wp_head', null ),
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
			$facts[ $key ] = null === $hook[2]
				? false !== has_action( $hook[0], $hook[1] )
				: has_action( $hook[0], $hook[1], $hook[2] );
		}

		$facts['wp.self_pingbacks'] = $this->selfPingbacksEnabled();
		$facts['wp.jquery_migrate'] = $this->jqueryMigrateLoaded();

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
}
