<?php

namespace AggregateIt\Source;

use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

final class Source {

	public function __construct(
		public int $id,
		public string $url,
		public string $title,
		public string $status,
		public array $settings,
		public array $health,
		public ?string $last_checked
	) {}

	public static function from_row( object $row ): self {
		return new self(
			(int) $row->id,
			(string) $row->url,
			(string) $row->title,
			(string) $row->status,
			(array) Json::decode( $row->settings ?? null, [] ),
			(array) Json::decode( $row->health ?? null, [] ),
			$row->last_checked !== null ? (string) $row->last_checked : null
		);
	}

	public function interval_minutes( int $default ): int {
		return max( 1, (int) ( $this->settings['interval_minutes'] ?? $default ) );
	}

	public function full_content_threshold(): int {
		return max( 0, (int) ( $this->settings['full_content_threshold'] ?? 1200 ) );
	}

	public function publish_status( string $default ): string {
		$status = (string) ( $this->settings['publish_status'] ?? 'default' );
		return in_array( $status, [ 'publish', 'draft', 'pending' ], true ) ? $status : $default;
	}

	/** Returns one of auto|short|medium|long|match, or '' to use the global default. */
	public function article_length(): string {
		$v = (string) ( $this->settings['article_length'] ?? 'default' );
		return in_array( $v, [ 'auto', 'short', 'medium', 'long', 'match' ], true ) ? $v : '';
	}

	/** @return int[] category term IDs to assign to posts from this feed */
	public function categories(): array {
		return array_values( array_filter( array_map( 'intval', (array) ( $this->settings['categories'] ?? [] ) ) ) );
	}

	/** @return string[] tag names to assign to posts from this feed */
	public function tags(): array {
		return array_values( array_filter( array_map( 'trim', (array) ( $this->settings['tags'] ?? [] ) ) ) );
	}

	/** @return string[] only import items mentioning one of these (empty = no filter) */
	public function include_keywords(): array {
		return $this->keyword_list( 'include_keywords' );
	}

	/** @return string[] skip items mentioning any of these */
	public function exclude_keywords(): array {
		return $this->keyword_list( 'exclude_keywords' );
	}

	/** @return string[] */
	private function keyword_list( string $key ): array {
		$raw = $this->settings[ $key ] ?? [];
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		return array_values( array_filter( array_map( static fn ( $w ) => strtolower( trim( (string) $w ) ), (array) $raw ) ) );
	}

	public function is_active(): bool {
		return $this->status === 'active';
	}
}
