<?php

namespace AggregateIt\Admin;

use AggregateIt\Cost\CostMeter;
use AggregateIt\Cost\SpendCap;
use AggregateIt\Database\Schema;
use AggregateIt\Pipeline\Pipeline;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Settings;
use AggregateIt\Support\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates everything the dashboard renders: headline stat cards, the chart series
 * (pipeline state breakdown, daily throughput, daily cost), and recent activity.
 */
final class Stats {

	public function __construct(
		private ItemStore $items,
		private CostMeter $cost,
		private SpendCap $cap,
		private Settings $settings
	) {}

	public function payload(): array {
		$counts = $this->items->state_counts();

		$published = $counts[ Schema::STATE_PUBLISHED ] ?? 0;
		$dead      = $counts[ Schema::STATE_DEAD_LETTER ] ?? 0;
		$total     = array_sum( $counts );
		$in_flight = $total - $published - $dead;

		return [
			'cards'  => [
				'total_items'  => $total,
				'published'    => $published,
				'in_pipeline'  => $in_flight,
				'dead_letter'  => $dead,
				'sources'      => $this->count_table( 'sources' ),
				'clusters'     => $this->count_table( 'clusters' ),
				'entities'     => $this->count_entities(),
				'spend_today'  => round( $this->cost->spent_today(), 4 ),
				'spend_month'  => round( $this->cost->spent_this_month(), 4 ),
				'spend_cap'    => $this->settings->daily_spend_cap_usd(),
				'paused'       => $this->cap->is_paused(),
			],
			'states' => $this->states_series( $counts ),
			'throughput' => $this->items->daily_published( 14 ),
			'cost'   => $this->cost->daily( 14 ),
			'recent' => $this->items->recent( 15 ),
			'events' => ActivityLog::recent( 15 ),
		];
	}

	/** Ordered state breakdown for the doughnut, including zero-count states. */
	private function states_series( array $counts ): array {
		$series = [];
		foreach ( array_merge( Pipeline::default_order(), [ Schema::STATE_DEAD_LETTER ] ) as $state ) {
			$series[] = [
				'state' => $state,
				'label' => ucwords( str_replace( '_', ' ', $state ) ),
				'count' => $counts[ $state ] ?? 0,
			];
		}
		return $series;
	}

	private function count_table( string $name ): int {
		global $wpdb;
		$table = Schema::table( $name );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private function count_entities(): int {
		$types = apply_filters( 'aggregate_it_entity_post_types', [] );
		if ( ! $types ) {
			return 0;
		}
		$total = 0;
		foreach ( (array) $types as $type ) {
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $total;
	}
}
