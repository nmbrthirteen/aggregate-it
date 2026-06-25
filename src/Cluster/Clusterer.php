<?php

namespace AggregateIt\Cluster;

use AggregateIt\Ai\FactsGuard;
use AggregateIt\Settings;
use AggregateIt\Support\EventLog;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an item belongs to an existing story cluster. Conservative by design:
 * a false merge corrupts a live post, a false split only leaves a duplicate. Requires
 * THREE signals to merge — embedding similarity, an in-window cluster, and at least one
 * shared salient fact (the pre-rewrite stand-in for "shared primary entity"). Borderline
 * matches are logged and treated as new clusters.
 */
final class Clusterer {

	public function __construct(
		private VectorStore $vectors,
		private ClusterRepository $clusters,
		private FactsGuard $facts,
		private Settings $settings
	) {}

	/**
	 * @param float[] $vector
	 * @return int|null matched cluster id, or null to start a new cluster
	 */
	public function match( array $vector, string $content ): ?int {
		$live = $this->clusters->live_ids();
		if ( ! $live || ! $vector ) {
			return null;
		}

		$candidates = $this->vectors->similar( 'cluster', $vector, $live, 3 );
		$threshold  = $this->settings->similarity_threshold();
		$item_facts = $this->facts->salient( $content );

		foreach ( $candidates as $candidate ) {
			if ( $candidate['score'] < $threshold ) {
				return null; // sorted desc — nothing below the top will clear it either
			}

			$shared = array_intersect( $item_facts, $this->clusters->fact_set( $candidate['owner_id'] ) );
			if ( count( $shared ) >= 1 ) {
				return $candidate['owner_id'];
			}

			EventLog::info(
				sprintf(
					'Close call: this looked %.3f similar to story #%d, but they did not share a key fact, so we started a new story.',
					$candidate['score'],
					$candidate['owner_id']
				)
			);
		}

		return null;
	}
}
