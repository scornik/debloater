<?php
/**
 * A widget catalogue standing in for Elementor's.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration\Support;

use WPDebloat\Scan\AssetParser;
use WPDebloat\Scan\Elementor\WidgetCatalog;
use WPDebloat\Scan\PageSample;
use WPDebloat\Scan\Sources;

/**
 * Three widget types, from three different files.
 *
 * The classes are real, and are this plugin's own, so that source attribution
 * has something genuine to resolve. A fake returning invented class names would
 * exercise the reflection path without ever proving it works.
 */
final class FakeWidgetCatalogue implements WidgetCatalog {

	/**
	 * Readable.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return true;
	}

	/**
	 * The widget types, as name to implementing class.
	 *
	 * @return array<string,string>
	 */
	public function widgets(): array {
		return array(
			'button'  => Sources::class,
			'heading' => AssetParser::class,
			'image'   => PageSample::class,
		);
	}
}
