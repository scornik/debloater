<?php
/**
 * The widget catalogue, read from Elementor itself.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Elementor;

use Throwable;

/**
 * Asks Elementor what it has registered (BUILD-SPEC §17 Phase 14).
 *
 * The only file in Debloater that names an Elementor class. Everything it does
 * is guarded, because this is somebody else's code and the version on any given
 * site is not one we chose:
 *
 * - the constant, the class and the method are each checked before use;
 * - the whole call is wrapped, because asking Elementor for its widget types
 *   makes it instantiate every one of them, and one broken addon should cost a
 *   fact rather than the scan;
 * - a failure returns "not available", which the facts record as an absence
 *   rather than as an empty catalogue.
 *
 * The distinction in that last point is the whole reason for the guarding. An
 * empty catalogue would mean "Elementor has no widgets", which would make every
 * widget on the site look unaccounted for.
 */
final class LiveWidgetCatalog implements WidgetCatalog {

	/**
	 * Cached widget types, so a scan asks Elementor once.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $widgets = null;

	/**
	 * Whether the last read succeeded.
	 *
	 * @var bool
	 */
	private bool $available = false;

	/**
	 * Whether the catalogue can be read.
	 *
	 * @return bool
	 */
	public function available(): bool {
		$this->read();

		return $this->available;
	}

	/**
	 * Registered widget types, as type name to implementing class.
	 *
	 * @return array<string,string>
	 */
	public function widgets(): array {
		$this->read();

		return $this->widgets ?? array();
	}

	/**
	 * Ask Elementor once.
	 *
	 * @return void
	 */
	private function read(): void {
		if ( null !== $this->widgets ) {
			return;
		}

		$this->widgets = array();

		if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		try {
			$plugin = \Elementor\Plugin::$instance;

			if ( ! is_object( $plugin ) || ! isset( $plugin->widgets_manager ) ) {
				return;
			}

			$manager = $plugin->widgets_manager;

			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
				return;
			}

			$types = $manager->get_widget_types();

			if ( ! is_array( $types ) ) {
				return;
			}

			foreach ( $types as $name => $widget ) {
				$this->widgets[ (string) $name ] = is_object( $widget ) ? get_class( $widget ) : (string) $widget;
			}

			ksort( $this->widgets, SORT_STRING );

			$this->available = true;
		} catch ( Throwable $error ) {
			// One addon that throws on instantiation should cost this fact, not
			// the scan. The absence is reported; nothing is guessed.
			unset( $error );

			$this->widgets   = array();
			$this->available = false;
		}
	}
}
