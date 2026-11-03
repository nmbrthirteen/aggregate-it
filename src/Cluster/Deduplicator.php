<?php

namespace AggregateIt\Cluster;

use AggregateIt\Settings;
use AggregateIt\Support\EventLog;
use AggregateIt\Support\Vector;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Finds (and optionally trashes) duplicate published posts by comparing the stored
 * embedding of each post's story against the others, at the same "Duplicate sensitivity"
 * the live clusterer uses. Keeps the oldest post of each group; trashes, never deletes.
 * A dry run reports what it would do without changing anything.
 *
 * The pairwise scan is quadratic, so each call works for a bounded time and saves its
 * position. Calling run() again continues until every post has been checked; only then
 * are duplicates trashed.
 */
final class Deduplicator {

	private const MAX_POSTS  = 3000;
	private const TIME_LIMIT = 15; // seconds per call
	private const STATE_KEY  = 'aggregate_it_dedup_state';

	public function __construct( private Settings $settings, private VectorStore $vectors ) {}

	/**
	 * @return array{groups:int,duplicates:int,examples:string[],complete:bool,checked:int,total:int}
	 */
	public function run( bool $dry_run = false, ?float $threshold = null ): array {
		$threshold = $threshold ?? $this->settings->similarity_threshold();

		$state = get_transient( self::STATE_KEY );
		if ( ! is_array( $state ) || ( $state['dry_run'] ?? null ) !== $dry_run || ( $state['threshold'] ?? null ) !== $threshold ) {
			$state = [
				'dry_run'   => $dry_run,
				'threshold' => $threshold,
				'ids'       => $this->post_ids(),
				'cursor'    => 0,
				'assigned'  => [],
			];
		}

		$ids        = array_map( 'intval', $state['ids'] );
		$embeddings = $this->embeddings( $ids );
		$assigned   = array_map( 'intval', $state['assigned'] );
		$cursor     = $this->scan( $ids, $embeddings, $threshold, (int) $state['cursor'], time() + self::TIME_LIMIT, $assigned );

		if ( $cursor < count( $ids ) ) {
			$state['cursor']   = $cursor;
			$state['assigned'] = $assigned;
			set_transient( self::STATE_KEY, $state, HOUR_IN_SECONDS );
			return [ 'groups' => 0, 'duplicates' => 0, 'examples' => [], 'complete' => false, 'checked' => $cursor, 'total' => count( $ids ) ];
		}

		delete_transient( self::STATE_KEY );

		$groups = [];
		foreach ( $assigned as $dup => $keeper ) {
			$groups[ $keeper ][] = $dup;
		}

		$examples = [];
		foreach ( $groups as $keeper => $dups ) {
			if ( count( $examples ) < 10 ) {
				$examples[] = sprintf( '"%s" +%d', get_the_title( $keeper ), count( $dups ) );
			}
			if ( ! $dry_run ) {
				foreach ( $dups as $dup_id ) {
					wp_trash_post( $dup_id );
				}
			}
		}

		$duplicates = count( $assigned );
		$this->log( $dry_run, $duplicates, count( $groups ), $examples );

		return [ 'groups' => count( $groups ), 'duplicates' => $duplicates, 'examples' => $examples, 'complete' => true, 'checked' => count( $ids ), 'total' => count( $ids ) ];
	}

	/**
	 * Compares each unassigned post, from $cursor on, against the later ones until done or
	 * the deadline passes. Posts without a stored embedding never match.
	 *
	 * @param int[]              $ids        post ids, oldest first (the oldest in a group is kept)
	 * @param array<int,float[]> $embeddings post id => embedding
	 * @param array<int,int>     $assigned   duplicate post id => keeper post id, updated in place
	 * @return int the cursor to resume from; equals count( $ids ) when finished
	 */
	public function scan( array $ids, array $embeddings, float $threshold, int $cursor, int $deadline, array &$assigned ): int {
		$count = count( $ids );
		for ( $i = $cursor; $i < $count; $i++ ) {
			if ( time() >= $deadline ) {
				return $i;
			}
			$a = $ids[ $i ];
			if ( isset( $assigned[ $a ] ) || empty( $embeddings[ $a ] ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$b = $ids[ $j ];
				if ( isset( $assigned[ $b ] ) || empty( $embeddings[ $b ] ) ) {
					continue;
				}
				if ( Vector::cosine( $embeddings[ $a ], $embeddings[ $b ] ) >= $threshold ) {
					$assigned[ $b ] = $a;
				}
			}
		}
		return $count;
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

	/**
	 * @param int[] $post_ids
	 * @return array<int,float[]> post id => embedding of the post's story
	 */
	private function embeddings( array $post_ids ): array {
		$cluster_of = [];
		foreach ( $post_ids as $post_id ) {
			$cluster_id = (int) get_post_meta( $post_id, '_ai_cluster_id', true );
			if ( $cluster_id > 0 ) {
				$cluster_of[ $post_id ] = $cluster_id;
			}
		}

		$by_cluster = $this->vectors->get_many( 'cluster', array_values( array_unique( $cluster_of ) ) );

		$embeddings = [];
		foreach ( $cluster_of as $post_id => $cluster_id ) {
			if ( isset( $by_cluster[ $cluster_id ] ) ) {
				$embeddings[ $post_id ] = $by_cluster[ $cluster_id ];
			}
		}
		return $embeddings;
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
