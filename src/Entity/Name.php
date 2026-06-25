<?php

namespace AggregateIt\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Canonicalizes entity names for matching: drops corporate suffixes and punctuation,
 * lowercases, and collapses whitespace. "Acme Corp., Inc." and "acme corp" normalize
 * to the same key.
 */
final class Name {

	private const SUFFIXES = [ 'inc', 'inc.', 'llc', 'ltd', 'ltd.', 'corp', 'corp.', 'co', 'co.', 'plc', 'gmbh', 'sa', 'ag', 'the' ];

	public static function normalize( string $name ): string {
		$name  = strtolower( wp_strip_all_tags( $name ) );
		$name  = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $name );
		$words = array_filter( preg_split( '/\s+/', (string) $name ) ?: [] );

		$kept = [];
		foreach ( $words as $word ) {
			if ( ! in_array( $word, self::SUFFIXES, true ) ) {
				$kept[] = $word;
			}
		}

		return trim( implode( ' ', $kept ) );
	}
}
