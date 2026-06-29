<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Cluster\Clusterer;
use AggregateIt\Database\Schema;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `embedded` → `clustered`: matches the item to an existing story cluster, or
 * marks it as starting a new one. New-cluster creation is deferred to ComposeStage,
 * which has the rewrite's primary keyword. Free (no AI call).
 */
final class ClusterStage implements Stage {

	public function __construct(
		private Clusterer $clusterer,
		private VectorStore $vectors,
		private ItemStore $items
	) {}

	public function handles(): string {
		return Schema::STATE_EMBEDDED;
	}

	public function process( Item $item ): string {
		if ( ! empty( $item->flags['passthrough'] ) ) {
			$item->flags['cluster_new'] = false;
			return Schema::STATE_CLUSTERED;
		}

		$vector  = $this->vectors->get( 'item', $item->id );
		$matched = $this->clusterer->match( $vector, (string) $item->raw_content );

		if ( $matched !== null ) {
			$this->items->set_cluster( $item->id, $matched );
			$item->cluster_id            = $matched;
			$item->flags['cluster_new']  = false;
		} else {
			$item->flags['cluster_new'] = true;
		}

		return Schema::STATE_CLUSTERED;
	}
}
