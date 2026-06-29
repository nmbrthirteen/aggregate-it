<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Ai\ProviderFactory;
use AggregateIt\Cost\CostMeter;
use AggregateIt\Database\Schema;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `extracted` → `embedded`: computes the item's embedding and stores it for
 * clustering. Paid (embeddings cost money, however little).
 */
final class EmbedStage implements PaidStage {

	public function __construct(
		private ProviderFactory $providers,
		private VectorStore $vectors,
		private CostMeter $cost
	) {}

	public function handles(): string {
		return Schema::STATE_EXTRACTED;
	}

	public function process( Item $item ): string {
		if ( ! empty( $item->flags['passthrough'] ) ) {
			return Schema::STATE_EMBEDDED;
		}

		$result = $this->providers->get()->embed( (string) $item->raw_content );
		$this->vectors->put( 'item', $item->id, $result['vector'] );
		$this->cost->record( Schema::STATE_EXTRACTED, $result['tokens'], $result['cost_usd'], $item->id );

		return Schema::STATE_EMBEDDED;
	}
}
