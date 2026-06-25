<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

final class Item {

	public function __construct(
		public int $id,
		public int $source_id,
		public string $guid,
		public string $url,
		public ?string $raw_content,
		public string $content_hash,
		public string $state,
		public ?int $cluster_id,
		public ?int $post_id,
		public array $flags,
		public int $attempts
	) {}

	public static function from_row( object $row ): self {
		return new self(
			(int) $row->id,
			(int) $row->source_id,
			(string) $row->guid,
			(string) $row->url,
			$row->raw_content !== null ? (string) $row->raw_content : null,
			(string) $row->content_hash,
			(string) $row->state,
			$row->cluster_id !== null ? (int) $row->cluster_id : null,
			$row->post_id !== null ? (int) $row->post_id : null,
			(array) Json::decode( $row->flags ?? null, [] ),
			(int) $row->attempts
		);
	}
}
