<?php
/**
 * Which pages to look at.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan;

/**
 * Chooses a small, representative set of URLs to fetch (BUILD-SPEC §17
 * Phase 13).
 *
 * A site can have a hundred thousand URLs and the scan has ten seconds. The
 * sample is therefore deliberately small and deliberately shaped: the home page,
 * plus the most recently published entry of each public post type.
 *
 * Post type rather than random pages, because that is where the differences
 * are. Two blog posts load the same assets as each other; a blog post and a
 * WooCommerce product do not. Sampling twenty posts would cost twenty requests
 * to learn what one request already said.
 *
 * The consequence is worth naming, because it limits every finding built on
 * this: **a page nobody sampled was not measured.** "Contact Form 7 loads on
 * every page we looked at" is a claim about the sample. Rules that read these
 * facts say "on N pages sampled" rather than "on every page", and the sample
 * size travels with the facts so they can.
 */
final class PageSample {

	/**
	 * How many URLs one scan will fetch, including the home page.
	 */
	public const MAX_URLS = 10;

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * The URLs to sample, home page first.
	 *
	 * @param string $home_url The site's home URL.
	 * @return array<int,array{url:string,post_type:string}>
	 */
	public static function urls( string $home_url ): array {
		$sample = array(
			array(
				'url'       => $home_url,
				'post_type' => 'home',
			),
		);

		foreach ( self::publicPostTypes() as $post_type ) {
			if ( count( $sample ) >= self::MAX_URLS ) {
				break;
			}

			$url = self::representative( $post_type );

			if ( null === $url ) {
				continue;
			}

			$sample[] = array(
				'url'       => $url,
				'post_type' => $post_type,
			);
		}

		return $sample;
	}

	/**
	 * Public post types, in a fixed order.
	 *
	 * Attachments are excluded. An attachment page is a wrapper around a media
	 * file, most themes do not have a template for one, and what it loads says
	 * nothing about what the site loads.
	 *
	 * @return array<int,string>
	 */
	public static function publicPostTypes(): array {
		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			),
			'names'
		);

		if ( ! is_array( $types ) ) {
			return array();
		}

		$names = array_values( array_diff( array_map( 'strval', $types ), array( 'attachment' ) ) );

		// 'page' is queryable but not publicly_queryable, so it is asked for by
		// name — a site's pages are usually where the forms and the builders
		// are, which is exactly what this phase is looking at.
		if ( post_type_exists( 'page' ) && ! in_array( 'page', $names, true ) ) {
			$names[] = 'page';
		}

		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * The most recently published entry of a post type, or null when there is
	 * none.
	 *
	 * @param string $post_type Post type name.
	 * @return string|null
	 */
	private static function representative( string $post_type ): ?string {
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( array() === $posts ) {
			return null;
		}

		$permalink = get_permalink( $posts[0] );

		return is_string( $permalink ) && '' !== $permalink ? $permalink : null;
	}
}
