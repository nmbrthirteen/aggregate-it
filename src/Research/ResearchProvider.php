<?php

namespace AggregateIt\Research;

defined( 'ABSPATH' ) || exit;

/**
 * Optional entity enrichment. Absent by default — entities are then created from
 * in-article context only. A premium add-on registers an implementation via the
 * `aggregate_it_research_provider` filter to supply cited external facts + sameAs URLs.
 */
interface ResearchProvider {

	public function key(): string;

	/**
	 * @return array{facts:array<int,array{value:string,source:string}>,sameas:string[],tokens:int,cost_usd:float}
	 */
	public function research( string $name, string $type, int $max_lookups = 3 ): array;
}
