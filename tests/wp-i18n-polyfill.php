<?php
/**
 * Minimal stand-ins for the WordPress functions the unit suite needs.
 *
 * The unit suite runs with no WordPress loaded, by design: it is what keeps the
 * contracts, the registry and the analyzer honest about not depending on it.
 * But user-visible strings must still be translatable (CONVENTIONS.md), so the
 * analyzer legitimately calls __(), _n() and number_format_i18n().
 *
 * These stand-ins return the untranslated string, which is exactly what
 * WordPress does when no translation is loaded — so a unit test sees the same
 * text an English-language site does. Nothing here is loaded when WordPress is
 * present: every definition is guarded, and the integration suite boots real
 * WordPress before the plugin.
 *
 * This file deliberately covers only what is actually used. A missing function
 * should fail loudly in a test rather than be quietly stubbed, because it means
 * code that should not depend on WordPress has started to.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * 60 * 60 );
}

if ( ! function_exists( 'debloater_polyfill_note' ) ) {

	/**
	 * Marker so a test can assert it is running against the stand-ins.
	 *
	 * @return bool
	 */
	function debloater_polyfill_note(): bool {
		return true;
	}
}

if ( ! function_exists( '__' ) ) {

	/**
	 * Return the string untranslated.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stand-in for the WordPress function of the same name.
		unset( $domain );

		return (string) $text;
	}
}

if ( ! function_exists( '_x' ) ) {

	/**
	 * Return the string untranslated, ignoring the context.
	 *
	 * @param string $text    Text to translate.
	 * @param string $context Disambiguating context.
	 * @param string $domain  Text domain.
	 * @return string
	 */
	function _x( $text, $context, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- As above.
		unset( $context, $domain );

		return (string) $text;
	}
}

if ( ! function_exists( '_n' ) ) {

	/**
	 * Choose singular or plural by English rules.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count deciding which to use.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- As above.
		unset( $domain );

		return 1 === (int) $number ? (string) $single : (string) $plural;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {

	/**
	 * Return the string untranslated and unescaped.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- As above.
		unset( $domain );

		return (string) $text;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {

	/**
	 * Format a number with thousands separators.
	 *
	 * @param float|int $number   Number to format.
	 * @param int       $decimals Decimal places.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- As above.
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'size_format' ) ) {

	/**
	 * Format a byte count for people.
	 *
	 * Used by the rule that reports how much data is loaded on every request.
	 * The unit suite runs without WordPress, and a rule that could only be
	 * tested with WordPress loaded would be a rule outside the pipeline's own
	 * boundaries.
	 *
	 * @param float|int $bytes    Number of bytes.
	 * @param int       $decimals Decimal places.
	 * @return string
	 */
	function size_format( $bytes, $decimals = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- As above.
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$last  = count( $units ) - 1;
		$index = 0;

		while ( $bytes >= 1024 && $index < $last ) {
			$bytes /= 1024;
			++$index;
		}

		return number_format( $bytes, (int) $decimals ) . ' ' . $units[ $index ];
	}
}
