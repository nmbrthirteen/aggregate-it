<?php

namespace AggregateIt\Source;

use AggregateIt\Queue\ItemStore;
use AggregateIt\Settings;
use AggregateIt\Support\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls feeds and enqueues new items in the `fetched` state. Import stays lightweight —
 * it only parses the feed and stores the feed-provided content; the heavier per-article
 * extraction happens later in ExtractStage, where it gets retry/backoff/rate-limiting.
 */
final class Importer {

	private const HOOK         = 'aggregate_it_import';
	private const MAX_PER_FEED = 50;
	private const MAX_SOURCES  = 10;

	public function __construct(
		private SourceRepository $sources,
		private ItemStore $items,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'aggregate_it_import_now', [ $this, 'import_one' ] );
		// Register the interval here too so scheduling never depends on QueueWorker
		// having added it first.
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'aggregate_it_minute', self::HOOK );
		}
	}

	public function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['aggregate_it_minute'] ) ) {
			$schedules['aggregate_it_minute'] = [
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (Aggregate It)', 'aggregate-it' ),
			];
		}
		return $schedules;
	}

	public function run(): void {
		$due = $this->sources->due( $this->settings->import_interval_minutes(), self::MAX_SOURCES );
		foreach ( $due as $source ) {
			$this->import( $source );
		}
		if ( $due ) {
			do_action( 'aggregate_it_dispatch_queue' );
		}
	}

	public function import_one( int $source_id ): void {
		$source = $this->sources->get( $source_id );
		if ( $source ) {
			$this->import( $source );
			do_action( 'aggregate_it_dispatch_queue' );
		}
	}

	public function import( Source $source ): int {
		$imported = 0;
		try {
			$entries  = $this->parse( $source->url );
			$imported = $this->ingest( $source, $entries );

			$this->sources->mark_checked(
				$source->id,
				[
					'last_ok'           => gmdate( 'Y-m-d H:i:s' ),
					'last_imported'     => $imported,
					'last_error'        => '',
					'consecutive_fails' => 0,
				]
			);

			if ( $source->status === 'dead' ) {
				$this->sources->update( $source->id, [ 'status' => 'active' ] );
			}
		} catch ( \Throwable $e ) {
			$fails = (int) ( $source->health['consecutive_fails'] ?? 0 ) + 1;

			$this->sources->mark_checked(
				$source->id,
				[
					'last_error'        => $e->getMessage(),
					'failed_at'         => gmdate( 'Y-m-d H:i:s' ),
					'consecutive_fails' => $fails,
				]
			);

			if ( $fails >= $this->settings->feed_dead_after() && $source->status === 'active' ) {
				$this->sources->update( $source->id, [ 'status' => 'dead' ] );
				EventLog::error( sprintf( 'Feed "%s" stopped working after %d failed tries.', $source->title ?: $source->url, $fails ) );
			} else {
				EventLog::warning( sprintf( 'Could not check feed "%s": %s', $source->title ?: $source->url, $e->getMessage() ) );
			}
		}

		return $imported;
	}

	/**
	 * @param array<int,array{guid:string,url:string,title:string,content:string}> $entries
	 */
	private function ingest( Source $source, array $entries ): int {
		$include  = $source->include_keywords();
		$exclude  = $source->exclude_keywords();
		$imported = 0;

		foreach ( $entries as $entry ) {
			if ( $imported >= self::MAX_PER_FEED ) {
				break;
			}
			if ( $entry['guid'] === '' || $this->items->exists_guid( $source->id, $entry['guid'] ) ) {
				continue;
			}
			if ( $this->items->exists_hash( hash( 'sha256', $entry['content'] ) ) ) {
				continue;
			}
			if ( ! $this->keywords_allow( $entry, $include, $exclude ) ) {
				continue;
			}

			$this->items->enqueue(
				$source->id,
				$entry['guid'],
				$entry['url'],
				$entry['content'],
				[
					'title'                 => $entry['title'],
					'image'                 => $entry['image'],
					'full_content_threshold' => $source->full_content_threshold(),
				]
			);
			$imported++;
		}
		return $imported;
	}

	/**
	 * @return array<int,array{guid:string,url:string,title:string,content:string}>
	 */
	private function parse( string $url ): array {
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/feed.php';
		}

		$feed = fetch_feed( $url );
		if ( is_wp_error( $feed ) ) {
			return $this->parse_json_feed( $url );
		}

		$entries = [];
		foreach ( $feed->get_items() as $item ) {
			$entries[] = [
				'guid'    => (string) ( $item->get_id() ?: $item->get_permalink() ),
				'url'     => (string) $item->get_permalink(),
				'title'   => (string) $item->get_title(),
				'content' => (string) ( $item->get_content() ?: $item->get_description() ),
				'image'   => $this->item_image( $item ),
			];
		}
		return $entries;
	}

	/**
	 * Minimal JSON Feed (jsonfeed.org) fallback for sources that aren't RSS/Atom.
	 *
	 * @return array<int,array{guid:string,url:string,title:string,content:string}>
	 */
	private function parse_json_feed( string $url ): array {
		$response = wp_remote_get( $url, [ 'timeout' => 15, 'user-agent' => 'AggregateIt/0.1' ] );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['items'] ) ) {
			throw new \RuntimeException( 'Unrecognized feed format' );
		}

		$entries = [];
		foreach ( $data['items'] as $item ) {
			$entries[] = [
				'guid'    => (string) ( $item['id'] ?? $item['url'] ?? '' ),
				'url'     => (string) ( $item['url'] ?? '' ),
				'title'   => (string) ( $item['title'] ?? '' ),
				'content' => (string) ( $item['content_html'] ?? $item['content_text'] ?? $item['summary'] ?? '' ),
				'image'   => (string) ( $item['image'] ?? '' ),
			];
		}
		return $entries;
	}

	/**
	 * @param array{title:string,content:string} $entry
	 * @param string[]                            $include
	 * @param string[]                            $exclude
	 */
	private function keywords_allow( array $entry, array $include, array $exclude ): bool {
		if ( ! $include && ! $exclude ) {
			return true;
		}
		$haystack = strtolower( wp_strip_all_tags( $entry['title'] . ' ' . $entry['content'] ) );

		foreach ( $exclude as $word ) {
			if ( $word !== '' && strpos( $haystack, $word ) !== false ) {
				return false;
			}
		}
		if ( $include ) {
			foreach ( $include as $word ) {
				if ( $word !== '' && strpos( $haystack, $word ) !== false ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	private function item_image( \SimplePie_Item $item ): string {
		$enclosure = $item->get_enclosure();
		if ( ! $enclosure ) {
			return '';
		}
		return (string) ( $enclosure->get_thumbnail() ?: ( $enclosure->get_link() ?: '' ) );
	}
}
