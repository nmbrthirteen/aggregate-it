<?php

namespace AggregateIt\Publish;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps an AI-suggested category name to a real category term, creating it on first use.
 * Matching is exact by name/slug — substring matching sends unrelated posts to the wrong
 * category. Generic buckets are hidden from the AI's options so it classifies by topic.
 */
final class CategoryResolver {

	private const GENERIC = [ 'uncategorized', 'general', 'blog', 'news', 'other', 'misc' ];

	public function __construct( private Settings $settings ) {}

	/** @return string[] existing category names, to steer the AI toward reusing them */
	public function existing_names(): array {
		if ( ! $this->enabled() ) {
			return [];
		}

		$names = [];
		foreach ( get_categories( [ 'hide_empty' => false, 'number' => 200 ] ) as $term ) {
			$name = trim( (string) $term->name );
			if ( $name !== '' && ! in_array( strtolower( $name ), self::GENERIC, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/** Resolve a category name to a term id, creating the category if it doesn't exist. 0 = none. */
	public function resolve( string $name ): int {
		$name = trim( $name );
		if ( $name === '' || ! $this->enabled() ) {
			return 0;
		}

		$existing = get_term_by( 'name', $name, 'category' );
		if ( ! $existing ) {
			$existing = get_term_by( 'slug', sanitize_title( $name ), 'category' );
		}
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( $name, 'category' );
		if ( is_wp_error( $created ) ) {
			return 0;
		}
		return (int) ( $created['term_id'] ?? 0 );
	}

	private function enabled(): bool {
		return in_array( 'category', get_object_taxonomies( $this->settings->target_post_type() ), true );
	}
}
