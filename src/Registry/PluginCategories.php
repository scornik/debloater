<?php
/**
 * What kind of thing each known plugin is.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

use RuntimeException;

/**
 * The plugin category table (BUILD-SPEC §17 Phase 11).
 *
 * The rest of the registry is one document per object: one tweak, one detector,
 * one compatibility rule per file. This is a table instead, because that is what
 * it is — a lookup from slug to category, where a file per plugin would be forty
 * files each holding a single word.
 *
 * Membership says what a plugin is *for*. It never says a plugin should not be
 * there. Two SEO plugins is a fact about a site, and quite often a deliberate
 * one: a migration in progress, a legacy plugin kept for its redirects. The
 * finding that reads this table reports and stops.
 *
 * A slug not in the table is not "uncategorised" in any meaningful sense — it is
 * simply a plugin nobody has classified, which is the normal case, since there
 * are sixty thousand of them. Absence is never evidence of anything.
 */
final class PluginCategories {

	/**
	 * Category id to its label, in sorted id order.
	 *
	 * @var array<string,array{label:string}>
	 */
	private readonly array $categories;

	/**
	 * Plugin slug to category id, in sorted slug order.
	 *
	 * @var array<string,string>
	 */
	private readonly array $plugins;

	/**
	 * Constructor.
	 *
	 * @param array<string,array{label:string}> $categories Categories keyed by id.
	 * @param array<string,string>              $plugins    Plugin slug to category id.
	 * @throws RuntimeException When a plugin claims a category that does not exist.
	 */
	public function __construct( array $categories = array(), array $plugins = array() ) {
		foreach ( $plugins as $slug => $category ) {
			if ( ! array_key_exists( $category, $categories ) ) {
				throw new RuntimeException(
					sprintf( 'Plugin "%s" is in category "%s", which is not defined.', $slug, $category )
				);
			}
		}

		ksort( $categories, SORT_STRING );
		ksort( $plugins, SORT_STRING );

		$this->categories = $categories;
		$this->plugins    = $plugins;
	}

	/**
	 * Build from the decoded registry/plugin-categories.json.
	 *
	 * @param array<string,mixed> $document Decoded document.
	 * @return self
	 */
	public static function fromArray( array $document ): self {
		$categories = array();
		$plugins    = array();

		$raw_categories = $document['categories'] ?? array();
		$raw_plugins    = $document['plugins'] ?? array();

		if ( is_array( $raw_categories ) ) {
			foreach ( $raw_categories as $id => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$categories[ (string) $id ] = array( 'label' => (string) ( $entry['label'] ?? '' ) );
			}
		}

		if ( is_array( $raw_plugins ) ) {
			foreach ( $raw_plugins as $slug => $category ) {
				$plugins[ (string) $slug ] = (string) $category;
			}
		}

		return new self( $categories, $plugins );
	}

	/**
	 * The category of a plugin slug, or null when it is not classified.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return string|null
	 */
	public function categoryOf( string $slug ): ?string {
		return $this->plugins[ $slug ] ?? null;
	}

	/**
	 * The label for a category, falling back to its id.
	 *
	 * @param string $category Category id.
	 * @return string
	 */
	public function label( string $category ): string {
		return $this->categories[ $category ]['label'] ?? $category;
	}

	/**
	 * Every category id, sorted.
	 *
	 * @return array<int,string>
	 */
	public function categoryIds(): array {
		return array_keys( $this->categories );
	}

	/**
	 * One row per classified plugin: which category it is in, and what that
	 * category is called.
	 *
	 * A row rather than a nested group because a fact value may nest exactly one
	 * level (Fact::assertValueShape), and because rows are what this actually is
	 * — a small relation, which the rule that reads it groups for itself.
	 *
	 * A label and nothing more. What running two of a kind costs is reasoning,
	 * and reasoning is the analyzer's job; a fact set full of explanatory prose
	 * would also stop being diffable, which is half of what facts are for.
	 *
	 * Plugins nobody has classified are dropped. Absence is not evidence: there
	 * are sixty thousand plugins and this table knows a few dozen.
	 *
	 * @param array<int,string> $slugs Plugin directory slugs.
	 * @return array<int,array<string,string>>
	 */
	public function rows( array $slugs ): array {
		$seen = array();
		$rows = array();

		foreach ( $slugs as $slug ) {
			$category = $this->categoryOf( $slug );

			if ( null === $category || isset( $seen[ $slug ] ) ) {
				continue;
			}

			$seen[ $slug ] = true;

			$rows[] = array(
				'plugin'   => $slug,
				'category' => $category,
				'label'    => $this->label( $category ),
			);
		}

		usort(
			$rows,
			static fn ( array $left, array $right ): int => array( $left['category'], $left['plugin'] )
				<=> array( $right['category'], $right['plugin'] )
		);

		return $rows;
	}

	/**
	 * How many plugins are classified.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->plugins );
	}

	/**
	 * The canonical form, for the registry hash.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'categories' => $this->categories,
			'plugins'    => $this->plugins,
		);
	}
}
