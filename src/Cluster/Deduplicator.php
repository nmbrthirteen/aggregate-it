<?php

namespace AggregateIt\Cluster;

use AggregateIt\Ai\FactsGuard;
use AggregateIt\Settings;
use AggregateIt\Support\EventLog;
use AggregateIt\Support\Json;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup for posts that were duplicated before same-story re-matching existed. Groups
 * near-identical story clusters by embedding similarity (plus a shared salient fact, the
 * same gate live clustering uses) and trashes the newer copies, keeping the oldest post of
 * each group. Trash, never delete — fully reversible.
 */
final class Deduplicator {

	public function __construct(
		private ClusterRepository $clusters,
		private VectorStore $vectors,
		private FactsGuard $facts,
		private Settings $settings
	) {}

	/** @return int number of duplicate posts trashed */
	public function run(): int {
		$rows = $this->clusters->with_posts();
		if ( count( $rows ) < 2 ) {
			return 0;
		}

		$threshold = $this->settings->similarity_threshold();
		$ids       = array_map( static fn ( $r ) => (int) $r->id, $rows );
		$handled   = [];
		$trashed   = 0;

		foreach ( $rows as $keeper ) {
			$keeper_id = (int) $keeper->id;
			if ( isset( $handled[ $keeper_id ] ) ) {
				continue;
			}
			$handled[ $keeper_id ] = true;

			$vector = $this->vectors->get( 'cluster', $keeper_id );
			if ( ! $vector ) {
				continue;
			}

			$others = array_values( array_filter( $ids, static fn ( $id ) => $id > $keeper_id && ! isset( $handled[ $id ] ) ) );
			if ( ! $others ) {
				continue;
			}

			$keeper_facts = (array) Json::decode( $keeper->fact_set ?? null, [] );

			foreach ( $this->vectors->similar( 'cluster', $vector, $others, count( $others ) ) as $match ) {
				if ( $match['score'] < $threshold ) {
					break; // sorted desc — nothing further will clear it
				}

				$dup = $this->clusters->get( (int) $match['owner_id'] );
				if ( ! $dup ) {
					continue;
				}

				$dup_facts = (array) Json::decode( $dup->fact_set ?? null, [] );
				if ( ! array_intersect( $keeper_facts, $dup_facts ) ) {
					continue;
				}

				$handled[ (int) $dup->id ] = true;
				$post_id                   = (int) $dup->canonical_post_id;
				$status                    = $post_id ? get_post_status( $post_id ) : false;
				if ( $status && $status !== 'trash' ) {
					wp_trash_post( $post_id );
					$this->clusters->merge_facts( $keeper_id, $dup_facts );
					$trashed++;
				}
			}
		}

		if ( $trashed > 0 ) {
			EventLog::info( sprintf( 'Cleanup: trashed %d duplicate post(s), keeping the original of each story.', $trashed ) );
		}
		return $trashed;
	}
}
