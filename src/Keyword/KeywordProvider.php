<?php

namespace AggregateIt\Keyword;

defined( 'ABSPATH' ) || exit;

/**
 * Optional keyword volume/difficulty data (DataForSEO/Semrush/Ahrefs, BYO key). Absent
 * by default. A premium add-on registers via the `aggregate_it_keyword_provider` filter
 * to let the engine prioritize high-value clusters and skip zero-volume noise.
 */
interface KeywordProvider {

	public function key(): string;

	/**
	 * @param string[] $keywords
	 * @return array<string,array{volume:int,difficulty:int}>
	 */
	public function metrics( array $keywords ): array;
}
