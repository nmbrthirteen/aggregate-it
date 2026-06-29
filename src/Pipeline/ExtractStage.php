<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Database\Schema;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Settings;
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
		private ItemStore $items,
		private Settings $settings
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

		// Prefer the publisher's share image (og:image) over the feed enclosure — the
		// enclosure is often a generic or sub-topic image, not the article's hero. A
		// transient fetch failure is rethrown for the first couple of attempts so the queue
		// retries (and the rate-limit gap clears) rather than freezing the post image-less.
		$enclosure = (string) ( $item->flags['image'] ?? '' );
		$share     = '';
		if ( $this->settings->image_source() === 'share' ) {
			$share = (string) ( $result['image'] ?? '' );
			if ( $share === '' && $item->url !== '' ) {
				$share = $this->extractor->share_image( $item->url, $item->attempts + 1 < 3 );
			}
		}
		$image = $share ?: ( $enclosure ?: (string) ( $result['image'] ?? '' ) );

		if ( $image !== '' ) {
			$item->flags['image'] = $image;
		}

		return Schema::STATE_EXTRACTED;
	}
}
