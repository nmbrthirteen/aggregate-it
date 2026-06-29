<?php

namespace AggregateIt\Publish;

defined( 'ABSPATH' ) || exit;

/**
 * Routes extracted field values to WordPress post destinations. Each field maps to a
 * destination string — post_title / post_content / post_excerpt / post_date /
 * featured_image / taxonomy:<tax> / meta:<key> — so any scraped shape (events, jobs,
 * listings) lands on any post type without site-specific code. Unmapped fields fall back
 * to a sensible default (standard fields to their obvious slot, everything else to meta).
 */
final class FieldMapper {

	/**
	 * @param array<string,string>            $values name => value
	 * @param array<string,array{dest:string}> $map    name => { dest }
	 * @return array{post:array<string,string>,meta:array<string,string>,terms:array<string,string[]>,image:string}
	 */
	public static function map( array $values, array $map ): array {
		$post  = [];
		$meta  = [];
		$terms = [];
		$image = '';

		foreach ( $values as $name => $value ) {
			$value = (string) $value;
			$dest  = (string) ( $map[ $name ]['dest'] ?? self::default_dest( (string) $name ) );

			if ( $dest === '' || $value === '' ) {
				continue;
			}

			if ( $dest === 'featured_image' ) {
				$image = $value;
			} elseif ( in_array( $dest, [ 'post_title', 'post_content', 'post_excerpt', 'post_date' ], true ) ) {
				$post[ $dest ] = $value;
			} elseif ( str_starts_with( $dest, 'taxonomy:' ) ) {
				$tax             = substr( $dest, 9 );
				$terms[ $tax ][] = $value;
			} elseif ( str_starts_with( $dest, 'meta:' ) ) {
				$meta[ substr( $dest, 5 ) ] = $value;
			}
		}

		return [
			'post'  => $post,
			'meta'  => $meta,
			'terms' => $terms,
			'image' => $image,
		];
	}

	private static function default_dest( string $name ): string {
		switch ( $name ) {
			case 'title':
				return 'post_title';
			case 'content':
			case 'description':
				return 'post_content';
			case 'image':
				return 'featured_image';
			case 'date':
				return 'post_date';
			case 'url':
				return 'meta:source_url';
			case 'guid':
				return '';
			default:
				return 'meta:' . $name;
		}
	}
}
