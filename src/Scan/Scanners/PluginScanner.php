<?php
/**
 * Facts about installed plugins.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;
use Debloater\Registry\Detector;
use Debloater\Registry\HostOptimizer;
use Debloater\Registry\Registry;
use Debloater\Scan\HostVendor;
use Debloater\Scan\WpOrgUpdates;

/**
 * Collects the `plugins.*` facts and applies the registry detectors
 * (BUILD-SPEC §5, §7.5).
 *
 * Detection reports both outcomes, not just the positive one. A rule that needs
 * to know WooCommerce is absent must be able to tell that from "we never
 * looked", and the only way to do that is to write the negative fact too.
 *
 * The wp.org API is consulted only when the user asked for it on this scan
 * (BUILD-SPEC §13 rule 9). Off — which is the default — nothing here touches the
 * network, and `last_updated` is simply absent from the metadata. Absent means
 * "we did not look", never "it was never updated"; the fallback is the plugin
 * file's own modification time, which answers a narrower question and is
 * recorded as such in `plugins.update_source`.
 */
final class PluginScanner extends AbstractScanner {

	/**
	 * The registry the detectors come from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * The wp.org lookup, off unless the user asked for it.
	 *
	 * @var WpOrgUpdates
	 */
	private WpOrgUpdates $updates;

	/**
	 * Constructor.
	 *
	 * @param Registry          $registry Registry holding the detectors.
	 * @param WpOrgUpdates|null $updates  Release-date lookup; off when omitted.
	 */
	public function __construct( Registry $registry, ?WpOrgUpdates $updates = null ) {
		$this->registry = $registry;
		$this->updates  = $updates ?? new WpOrgUpdates( false );
	}

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'plugins';
	}

	/**
	 * Collect plugin facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$active    = $this->activePlugins();
		$inactive  = array_values( array_diff( array_keys( $installed ), $active ) );

		sort( $active, SORT_STRING );
		sort( $inactive, SORT_STRING );

		$detected = $this->detect( $active );
		$slugs    = array_map( array( self::class, 'slugOf' ), $active );

		return array(
			'plugins.active'          => $active,
			'plugins.inactive'        => $inactive,
			'plugins.meta'            => $this->meta( $installed, $slugs ),
			'plugins.detected'        => $detected,
			'plugins.categories'      => $this->registry->pluginCategories()->rows( $slugs ),
			'plugins.update_source'   => $this->updates->enabled() ? 'wp_org' : 'file_mtime',
			'plugins.host_optimizers' => $this->optimizers( $detected ),
		);
	}

	/**
	 * The directory slug of a plugin file.
	 *
	 * A single-file plugin has no directory, so its own file name is the slug —
	 * which is also how wordpress.org names it.
	 *
	 * @param string $plugin_file Plugin file, e.g. "wordpress-seo/wp-seo.php".
	 * @return string
	 */
	public static function slugOf( string $plugin_file ): string {
		$position = strpos( $plugin_file, '/' );

		if ( false === $position ) {
			return basename( $plugin_file, '.php' );
		}

		return substr( $plugin_file, 0, $position );
	}

	/**
	 * One row per optimizer on this site and finding it has a setting for.
	 *
	 * A row per pair, rather than an optimizer with a list of findings inside
	 * it, because a fact value may nest exactly one level
	 * (Fact::assertValueShape) — and because the pair is the thing anything
	 * downstream actually looks up.
	 *
	 * The host signal is read through HostVendor rather than through
	 * `env.host_vendor`, because a scanner cannot see another scanner's facts.
	 * Both answers come from the same code, so they cannot drift apart.
	 *
	 * @param array<string,scalar|null> $detected Detector results.
	 * @return array<int,array<string,string>>
	 */
	private function optimizers( array $detected ): array {
		$host = HostVendor::identify();
		$rows = array();

		foreach ( $this->registry->hostOptimizers() as $optimizer ) {
			$matched = HostOptimizer::SIGNAL_HOST_VENDOR === $optimizer->signal_type
				? $host === $optimizer->signal_value
				: ! empty( $detected[ $optimizer->signal_value ] );

			if ( ! $matched ) {
				continue;
			}

			foreach ( $optimizer->covers as $finding_id ) {
				$rows[] = array(
					'id'      => $optimizer->id,
					'name'    => $optimizer->name,
					'finding' => $finding_id,
				);
			}
		}

		return $rows;
	}

	/**
	 * Active plugin files.
	 *
	 * Read from the option rather than through is_plugin_active() per plugin,
	 * which would be one option read each.
	 *
	 * @return array<int,string>
	 */
	private function activePlugins(): array {
		$active = get_option( 'active_plugins', array() );

		if ( ! is_array( $active ) ) {
			return array();
		}

		$files = array();

		foreach ( $active as $plugin_file ) {
			if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
				$files[] = $plugin_file;
			}
		}

		return $files;
	}

	/**
	 * Per-plugin metadata, keyed by plugin file.
	 *
	 * @param array<string,array<string,mixed>> $installed     Output of get_plugins().
	 * @param array<int,string>                 $active_slugs  Slugs of the active plugins.
	 * @return array<string,array<string,mixed>>
	 */
	private function meta( array $installed, array $active_slugs ): array {
		$released = $this->updates->lastUpdated( $active_slugs );
		$meta     = array();

		foreach ( $installed as $plugin_file => $headers ) {
			$slug  = self::slugOf( (string) $plugin_file );
			$entry = array(
				'name'       => isset( $headers['Name'] ) ? (string) $headers['Name'] : '',
				'version'    => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
				'file_mtime' => $this->modifiedAt( (string) $plugin_file ),
			);

			// Written only when it was actually looked up. A null here would be
			// indistinguishable from "wordpress.org says never", and the two
			// mean opposite things.
			if ( array_key_exists( $slug, $released ) ) {
				$entry['last_updated'] = $released[ $slug ];
			}

			$meta[ $plugin_file ] = $entry;
		}

		ksort( $meta, SORT_STRING );

		return $meta;
	}

	/**
	 * When a plugin's main file was last written on this server.
	 *
	 * This is not a release date and must never be presented as one. It says
	 * when the file last changed *here*, which a copy-based migration resets and
	 * an upstream release the site never installed does not affect at all.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return int|null
	 */
	private function modifiedAt( string $plugin_file ): ?int {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;

		if ( ! is_file( $path ) ) {
			return null;
		}

		$mtime = filemtime( $path );

		return false === $mtime ? null : (int) $mtime;
	}

	/**
	 * Run every detector, recording both matches and non-matches.
	 *
	 * @param array<int,string> $active Active plugin files.
	 * @return array<string,scalar|null>
	 */
	private function detect( array $active ): array {
		$detected = array();

		foreach ( $this->registry->detectors() as $detector ) {
			$facts = $this->matches( $detector, $active ) ? $detector->sets : $detector->negativeFacts();

			foreach ( $facts as $path => $value ) {
				$detected[ substr( $path, strlen( Detector::FACT_PREFIX ) ) ] = $value;
			}
		}

		ksort( $detected, SORT_STRING );

		return $detected;
	}

	/**
	 * Whether any of a detector's signals is present.
	 *
	 * Signals are ORed. Plugins get renamed, forked and bundled into others, and
	 * a detector that insisted on every signal would quietly stop recognising
	 * things the moment one of them changed.
	 *
	 * @param Detector          $detector Detector to evaluate.
	 * @param array<int,string> $active   Active plugin files.
	 * @return bool
	 */
	private function matches( Detector $detector, array $active ): bool {
		foreach ( $detector->signals() as $signal ) {
			if ( $this->signalPresent( $signal['type'], $signal['value'], $active ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one signal is present on this site.
	 *
	 * @param string            $type   Signal type.
	 * @param string            $value  Signal value.
	 * @param array<int,string> $active Active plugin files.
	 * @return bool
	 */
	private function signalPresent( string $type, string $value, array $active ): bool {
		switch ( $type ) {
			case 'plugin_file':
				return in_array( $value, $active, true );

			case 'constant':
				return defined( $value );

			case 'class':
				// Without autoloading: a detector should recognise a plugin that
				// is loaded, not cause its classes to load.
				return class_exists( $value, false );

			case 'function':
				return function_exists( $value );

			case 'theme':
				$stylesheet = (string) get_stylesheet();
				$template   = (string) get_template();

				return $value === $stylesheet || $value === $template;

			case 'option':
				$sentinel = '__debloater_absent__';
				$stored   = get_option( $value, $sentinel );

				return $sentinel !== $stored;

			default:
				return false;
		}
	}
}
