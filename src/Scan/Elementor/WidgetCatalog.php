<?php
/**
 * What Elementor knows how to build.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Elementor;

/**
 * The list of widget types Elementor and its addons have registered
 * (BUILD-SPEC §17 Phase 14).
 *
 * This exists as an interface for one reason: it is the only part of the
 * Elementor scan that has to talk to Elementor. Everything else — which widgets
 * a page actually uses, which experiments are on, which fonts are referenced —
 * is read out of the database and needs no third-party code loaded at all.
 *
 * Keeping the coupling in one small place means the rest of the scan is testable
 * without installing Elementor, and it means that when Elementor changes its
 * internals there is exactly one file to look at.
 */
interface WidgetCatalog {

	/**
	 * Whether the catalogue can be read at all.
	 *
	 * False when Elementor is not installed, not active, or too old to have the
	 * API this asks for. A false here means the widget counts are absent from
	 * the facts, which a rule reads as "not observed" — never as zero.
	 *
	 * @return bool
	 */
	public function available(): bool;

	/**
	 * Registered widget types, as type name to the class that implements it.
	 *
	 * The class name is what makes attribution possible: asking where that class
	 * is defined says which plugin registered the widget, without a list of
	 * addons anyone has to maintain.
	 *
	 * @return array<string,string>
	 */
	public function widgets(): array;
}
