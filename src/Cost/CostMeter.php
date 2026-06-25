<?php

namespace AggregateIt\Cost;

use AggregateIt\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CostMeter {

	public function record( string $stage, int $tokens, float $cost_usd, ?int $item_id = null, string $level = 'info', string $message = '' ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'log' ),
			[
				'item_id'    => $item_id,
				'stage'      => $stage,
				'level'      => $level,
				'message'    => $message,
				'tokens'     => max( 0, $tokens ),
				'cost_usd'   => max( 0, $cost_usd ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	public function spent_today(): float {
		return $this->spent_since( gmdate( 'Y-m-d 00:00:00' ) );
	}

	public function spent_this_month(): float {
		return $this->spent_since( gmdate( 'Y-m-01 00:00:00' ) );
	}

	public function spent_since( string $since ): float {
		global $wpdb;
		$table = Schema::table( 'log' );
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(cost_usd),0) FROM {$table} WHERE created_at >= %s", $since )
		);
	}

	/**
	 * Cost charted per day for the last $days days.
	 *
	 * @return array<int,array{date:string,cost:float,tokens:int}>
	 */
	public function daily( int $days = 14 ): array {
		global $wpdb;
		$table = Schema::table( 'log' );
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, SUM(cost_usd) AS cost, SUM(tokens) AS tokens
				 FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at)",
				$since
			),
			ARRAY_A
		);

		$by_date = [];
		foreach ( (array) $rows as $row ) {
			$by_date[ (string) $row['d'] ] = [
				'cost'   => (float) $row['cost'],
				'tokens' => (int) $row['tokens'],
			];
		}

		$series = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[] = [
				'date'   => $date,
				'cost'   => round( $by_date[ $date ]['cost'] ?? 0.0, 4 ),
				'tokens' => $by_date[ $date ]['tokens'] ?? 0,
			];
		}
		return $series;
	}
}
