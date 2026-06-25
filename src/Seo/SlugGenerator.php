<?php

namespace AggregateIt\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Flat, keyword-based slugs — no dates (living posts get updated; a date would make them
 * read as stale and dilute the keyword). Derived from the target keyword, not the
 * clickbait source headline. Uniqueness is left to wp_insert_post.
 */
final class SlugGenerator {

	public function generate( string $keyword, string $fallback_title ): string {
		$slug = sanitize_title( $keyword !== '' ? $keyword : $fallback_title );
		return $slug !== '' ? $slug : sanitize_title( wp_generate_password( 8, false ) );
	}
}
