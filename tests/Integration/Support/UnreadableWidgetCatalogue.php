<?php
/**
 * A widget catalogue that could not be read.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration\Support;

use Debloater\Scan\Elementor\WidgetCatalog;

/**
 * The failure case: an Elementor too old for the API this asks for, or an addon
 * that throws when its widgets are instantiated.
 *
 * It returns an empty list, and the whole point of the test that uses it is that
 * nothing downstream may read that as "Elementor has no widgets". Absent is not
 * empty, and treating it as empty would report every widget on the site as
 * unaccounted for — the most alarming possible way to be wrong.
 */
final class UnreadableWidgetCatalogue implements WidgetCatalog {

	/**
	 * Not readable.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * Nothing.
	 *
	 * @return array<string,string>
	 */
	public function widgets(): array {
		return array();
	}
}
