<?php

namespace AggregateIt\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a public CPT for each custom post type a scrape source targets, so users can
 * route scraped content (events, listings) to a brand-new post type without writing code.
 * The set is maintained as an option (updated when a source is saved) rather than scanned
 * from the sources table on every request.
 */
final class ScraperPostTypes {

	private const OPTION = 'aggregate_it_scrape_post_types';

	public function register(): void {
		foreach ( self::all() as $slug ) {
			if ( $slug === '' || post_type_exists( $slug ) ) {
				continue;
			}
			$label = ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
			register_post_type(
				$slug,
				[
					'labels'       => [ 'name' => $label, 'singular_name' => $label ],
					'public'       => true,
					'has_archive'  => true,
					'show_in_rest' => true,
					'menu_icon'    => 'dashicons-calendar-alt',
					'rewrite'      => [ 'slug' => $slug ],
					'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'excerpt' ],
				]
			);
		}
	}

	/** Record a slug so it is registered on the next request. Built-in types are ignored. */
	public static function remember( string $slug ): void {
		$slug = sanitize_key( $slug );
		if ( $slug === '' || in_array( $slug, [ 'post', 'page', 'attachment' ], true ) ) {
			return;
		}
		$types = self::all();
		if ( ! in_array( $slug, $types, true ) ) {
			$types[] = $slug;
			update_option( self::OPTION, array_values( array_unique( $types ) ), false );
		}
	}

	/** @return string[] */
	public static function all(): array {
		$types = get_option( self::OPTION, [] );
		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : [];
	}
}
