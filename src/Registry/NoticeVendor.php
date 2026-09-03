<?php
/**
 * A plugin whose admin notices a site owner may choose to hide.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Registry;

use RuntimeException;

/**
 * One entry of the admin-notice allowlist (BUILD-SPEC §17 Phase 12).
 *
 * The list is an allowlist and not a convenience. A plugin absent from it
 * cannot have its notices hidden by WP Debloat whatever a user types, because
 * the tweak's parameter schema accepts only the directory slugs these entries
 * name — which is what stops a selection screen from turning into a way to
 * silence arbitrary code.
 *
 * `notes` is the part that matters most. WP Debloat cannot tell a vendor's
 * marketing apart from its warnings: they come from the same hook, and on these
 * plugins they genuinely do. So every entry says what selecting it silences,
 * including what a person might miss, and that sentence is shown on the tweak
 * rather than buried somewhere.
 */
final class NoticeVendor {

	/**
	 * What the user selects.
	 *
	 * @var string
	 */
	public readonly string $slug;

	/**
	 * What the vendor calls itself.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * Plugin directory slugs whose notice callbacks this entry covers, sorted.
	 *
	 * @var array<int,string>
	 */
	public readonly array $sources;

	/**
	 * What selecting this actually hides.
	 *
	 * @var string
	 */
	public readonly string $notes;

	/**
	 * Constructor.
	 *
	 * @param string            $slug    Vendor slug.
	 * @param string            $name    Display name.
	 * @param array<int,string> $sources Plugin directory slugs.
	 * @param string            $notes   What selecting this hides.
	 * @throws RuntimeException When the entry covers nothing or explains nothing.
	 */
	public function __construct( string $slug, string $name, array $sources, string $notes ) {
		if ( array() === $sources ) {
			throw new RuntimeException(
				sprintf( 'Notice vendor "%s" names no source directories, so selecting it would hide nothing.', $slug )
			);
		}

		if ( '' === trim( $notes ) ) {
			throw new RuntimeException(
				sprintf(
					'Notice vendor "%s" has no notes. Every entry must say what selecting it hides, because this cannot tell marketing from warnings.',
					$slug
				)
			);
		}

		$sorted = array_values( array_unique( array_map( 'strval', $sources ) ) );
		sort( $sorted, SORT_STRING );

		$this->slug    = $slug;
		$this->name    = $name;
		$this->sources = $sorted;
		$this->notes   = $notes;
	}

	/**
	 * Build from one entry of registry/admin-notices.json.
	 *
	 * @param array<string,mixed> $entry Decoded entry.
	 * @return self
	 */
	public static function fromArray( array $entry ): self {
		$sources = $entry['sources'] ?? array();

		return new self(
			(string) ( $entry['slug'] ?? '' ),
			(string) ( $entry['name'] ?? '' ),
			is_array( $sources ) ? $sources : array(),
			(string) ( $entry['notes'] ?? '' )
		);
	}

	/**
	 * Whether a plugin directory slug belongs to this vendor.
	 *
	 * @param string $source Plugin directory slug.
	 * @return bool
	 */
	public function covers( string $source ): bool {
		return in_array( $source, $this->sources, true );
	}

	/**
	 * The canonical form, for the registry hash.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'slug'    => $this->slug,
			'name'    => $this->name,
			'sources' => $this->sources,
			'notes'   => $this->notes,
		);
	}
}
