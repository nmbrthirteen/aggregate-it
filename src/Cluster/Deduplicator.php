<?php

namespace AggregateIt\Cluster;

use AggregateIt\Settings;
use AggregateIt\Support\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Finds (and optionally trashes) duplicate published posts by comparing their text
 * directly — bag-of-words cosine over the title (weighted) and body. Deliberately
 * independent of the embedding/vector store, which can silently degrade and is what made
 * the earlier vector-based cleanup a no-op. Keeps the oldest post of each group; trashes,
 * never deletes. A dry run reports what it would do without changing anything.
 */
final class Deduplicator {

	private const MAX_POSTS = 3000;

	public function __construct( private Settings $settings ) {}

	/**
	 * @return array{groups:int,duplicates:int,examples:string[]}
	 */
	public function run( bool $dry_run = false, float $threshold = 0.40 ): array {
		$ids   = $this->post_ids();
		$vecs  = [];
		$norms = [];
		foreach ( $ids as $id ) {
			$tokens      = $this->tokens( get_the_title( $id ), (string) get_post_field( 'post_content', $id ) );
			$vecs[ $id ] = $tokens;
			$norms[ $id ] = sqrt( array_sum( array_map( static fn ( $v ) => $v * $v, $tokens ) ) );
		}

		$assigned = [];
		$groups   = [];
		$count    = count( $ids );
		for ( $i = 0; $i < $count; $i++ ) {
			$a = $ids[ $i ];
			if ( isset( $assigned[ $a ] ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$b = $ids[ $j ];
				if ( isset( $assigned[ $b ] ) ) {
					continue;
				}
				if ( $this->cosine( $vecs[ $a ], $norms[ $a ], $vecs[ $b ], $norms[ $b ] ) >= $threshold ) {
					$assigned[ $b ]  = $a;
					$groups[ $a ][] = $b;
				}
			}
		}

		$duplicates = 0;
		$examples   = [];
		foreach ( $groups as $keeper => $dups ) {
			$duplicates += count( $dups );
			if ( count( $examples ) < 10 ) {
				$examples[] = sprintf( '"%s" +%d', get_the_title( $keeper ), count( $dups ) );
			}
			if ( ! $dry_run ) {
				foreach ( $dups as $dup_id ) {
					wp_trash_post( $dup_id );
				}
			}
		}

		$this->log( $dry_run, $duplicates, count( $groups ), $examples );

		return [ 'groups' => count( $groups ), 'duplicates' => $duplicates, 'examples' => $examples ];
	}

	/** @return int[] published plugin posts, oldest first */
	private function post_ids(): array {
		$query = new \WP_Query(
			[
				'post_type'      => $this->settings->target_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_POSTS,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [ [ 'key' => '_ai_cluster_id', 'compare' => 'EXISTS' ] ],
			]
		);
		return array_map( 'intval', $query->posts );
	}

	/** @return array<string,int> token => count, title weighted so headlines drive the match */
	private function tokens( string $title, string $content ): array {
		$text  = strtolower( wp_strip_all_tags( $title . ' ' . $title . ' ' . $title . ' ' . $content ) );
		$words = preg_split( '/[^a-z0-9]+/', $text ) ?: [];

		$counts = [];
		foreach ( $words as $word ) {
			if ( strlen( $word ) < 4 ) {
				continue;
			}
			$counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * @param array<string,int> $a
	 * @param array<string,int> $b
	 */
	private function cosine( array $a, float $na, array $b, float $nb ): float {
		if ( $na === 0.0 || $nb === 0.0 ) {
			return 0.0;
		}
		if ( count( $b ) < count( $a ) ) {
			[ $a, $b ] = [ $b, $a ];
		}
		$dot = 0.0;
		foreach ( $a as $token => $count ) {
			if ( isset( $b[ $token ] ) ) {
				$dot += $count * $b[ $token ];
			}
		}
		return $dot / ( $na * $nb );
	}

	/** @param string[] $examples */
	private function log( bool $dry_run, int $duplicates, int $groups, array $examples ): void {
		if ( $duplicates === 0 ) {
			return;
		}
		if ( $dry_run ) {
			EventLog::info( sprintf( 'Duplicate preview: %d duplicate post(s) across %d stories. Examples: %s', $duplicates, $groups, implode( '; ', $examples ) ) );
		} else {
			EventLog::info( sprintf( 'Cleanup: trashed %d duplicate post(s) across %d stories, keeping the oldest of each.', $duplicates, $groups ) );
		}
	}
}
