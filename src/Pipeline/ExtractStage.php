<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Database\Schema;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Source\ContentExtractor;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `fetched` → `extracted`: obtains the full article body (feed content if full
 * enough, else readability over the fetched page), writes it back to the item, and flags
 * items that stay below the minimum length as thin (phase 2 declines to publish those).
 */
final class ExtractStage implements Stage {

	private const MIN_CONTENT = 600;

	public function __construct(
		private ContentExtractor $extractor,
		private ItemStore $items
	) {}

	public function handles(): string {
		return Schema::STATE_FETCHED;
	}

	public function process( Item $item ): string {
		$threshold = (int) ( $item->flags['full_content_threshold'] ?? 1200 );
		$result    = $this->extractor->extract( $item->url, (string) $item->raw_content, $threshold );

		$this->items->update_content( $item->id, $result['content'] );

		$item->flags['extract_source'] = $result['source'];
		$item->flags['content_length'] = mb_strlen( $result['content'] );
		$item->flags['thin']           = mb_strlen( $result['content'] ) < self::MIN_CONTENT;

		if ( empty( $item->flags['image'] ) && ! empty( $result['image'] ) ) {
			$item->flags['image'] = $result['image'];
		}

		return Schema::STATE_EXTRACTED;
	}
}
