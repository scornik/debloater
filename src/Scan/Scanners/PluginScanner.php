<?php
/**
 * Facts about installed plugins.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;
use WPDebloat\Registry\Detector;
use WPDebloat\Registry\Registry;

/**
 * Collects the `plugins.*` facts and applies the registry detectors
 * (BUILD-SPEC §5, §7.5).
 *
 * Detection reports both outcomes, not just the positive one. A rule that needs
 * to know WooCommerce is absent must be able to tell that from "we never
 * looked", and the only way to do that is to write the negative fact too.
 *
 * The wp.org API is deliberately not consulted here. `last_updated` is the one
 * piece of plugin metadata that would need it, and BUILD-SPEC §13 rule 9 makes
 * outbound HTTP opt-in; Phase 11 adds that behind the opt-in with a low-confidence
 * local fallback. Until then the key is simply absent.
 */
final class PluginScanner extends AbstractScanner {

	/**
	 * The registry the detectors come from.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Registry holding the detectors.
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

		return array(
			'plugins.active'   => $active,
			'plugins.inactive' => $inactive,
			'plugins.meta'     => $this->meta( $installed ),
			'plugins.detected' => $this->detect( $active ),
		);
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
	 * @param array<string,array<string,mixed>> $installed Output of get_plugins().
	 * @return array<string,array<string,mixed>>
	 */
	private function meta( array $installed ): array {
		$meta = array();

		foreach ( $installed as $plugin_file => $headers ) {
			$meta[ $plugin_file ] = array(
				'name'    => isset( $headers['Name'] ) ? (string) $headers['Name'] : '',
				'version' => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			);
		}

		ksort( $meta, SORT_STRING );

		return $meta;
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
				$sentinel = '__wpdebloat_absent__';
				$stored   = get_option( $value, $sentinel );

				return $sentinel !== $stored;

			default:
				return false;
		}
	}
}
