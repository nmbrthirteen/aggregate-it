<?php

namespace AggregateIt\Support;

use AggregateIt\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Durable activity feed backed by the `log` table — every pipeline decision and the
 * before/after of each transformation, queryable and filterable. Cost rows (written by
 * CostMeter into the same table) are activity too; they simply carry tokens/cost and an
 * empty message, so the human feed filters to rows that have a message.
 */
final class ActivityLog {

	private static bool $insert_warned = false;

	/**
	 * @param array{
	 *   item_id?:int|string|null, source_id?:int|null, post_id?:int|null,
	 *   type?:string, from_state?:string, to_state?:string, detail?:array
	 * } $ctx
	 */
	public static function record( string $level, string $message, array $ctx = [] ): void {
		global $wpdb;

		$detail = null;
		if ( isset( $ctx['detail'] ) && $ctx['detail'] !== [] ) {
			$encoded = wp_json_encode( $ctx['detail'] );
			$detail  = $encoded !== false ? $encoded : null;
		}

		$ok = $wpdb->insert(
			Schema::table( 'log' ),
			[
				'item_id'    => isset( $ctx['item_id'] ) ? (int) $ctx['item_id'] : null,
				'source_id'  => isset( $ctx['source_id'] ) ? (int) $ctx['source_id'] : null,
				'post_id'    => isset( $ctx['post_id'] ) ? (int) $ctx['post_id'] : null,
				'stage'      => isset( $ctx['type'] ) ? (string) $ctx['type'] : null,
				'from_state' => isset( $ctx['from_state'] ) ? (string) $ctx['from_state'] : null,
				'to_state'   => isset( $ctx['to_state'] ) ? (string) $ctx['to_state'] : null,
				'level'      => $level,
				'message'    => $message,
				'detail'     => $detail,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		if ( $ok === false && ! self::$insert_warned ) {
			self::$insert_warned = true;
			error_log( '[Aggregate It] activity log write failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * @param array{level?:string,type?:string,item_id?:int,source_id?:int,search?:string} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table             = Schema::table( 'log' );
		[ $where, $args ]  = self::where( $filters );

		$args[] = max( 1, $limit );
		$args[] = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, source_id, post_id, stage, from_state, to_state, level, message, detail, tokens, cost_usd, created_at
				 FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				$args
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'shape' ], (array) $rows );
	}

	/** @param array{level?:string,type?:string,item_id?:int,source_id?:int,search?:string} $filters */
	public static function count( array $filters = [] ): int {
		global $wpdb;
		$table            = Schema::table( 'log' );
		[ $where, $args ] = self::where( $filters );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
	}

	/** Legacy shape for the dashboard/Tools feed: newest first, message rows only. */
	public static function recent( int $limit = 200 ): array {
		$rows = self::query( [], $limit, 0 );
		return array_map(
			static fn ( array $r ) => [
				'time'    => $r['time'],
				'level'   => $r['level'],
				'message' => $r['message'],
			],
			$rows
		);
	}

	/**
	 * Clear the human activity feed without destroying cost history: cost rows always carry
	 * tokens or a dollar amount, so deleting only the zero-cost rows keeps the spend charts
	 * intact while wiping the info/warning/error entries.
	 */
	public static function clear(): void {
		global $wpdb;
		$table = Schema::table( 'log' );
		$wpdb->query( "DELETE FROM {$table} WHERE tokens = 0 AND cost_usd = 0" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array{level?:string,type?:string,item_id?:int,source_id?:int,search?:string} $filters
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function where( array $filters ): array {
		$clauses = [ "message <> ''" ];
		$args    = [];

		if ( ! empty( $filters['level'] ) ) {
			$clauses[] = 'level = %s';
			$args[]    = (string) $filters['level'];
		}
		if ( ! empty( $filters['type'] ) ) {
			$clauses[] = 'stage = %s';
			$args[]    = (string) $filters['type'];
		}
		if ( ! empty( $filters['item_id'] ) ) {
			$clauses[] = 'item_id = %d';
			$args[]    = (int) $filters['item_id'];
		}
		if ( ! empty( $filters['source_id'] ) ) {
			$clauses[] = 'source_id = %d';
			$args[]    = (int) $filters['source_id'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$clauses[] = 'message LIKE %s';
			$args[]    = '%' . $GLOBALS['wpdb']->esc_like( (string) $filters['search'] ) . '%';
		}
		if ( ! empty( $filters['since'] ) ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = (string) $filters['since'];
		}

		return [ implode( ' AND ', $clauses ), $args ];
	}

	/** @param array<string,mixed> $row */
	private static function shape( array $row ): array {
		$detail = null;
		if ( ! empty( $row['detail'] ) ) {
			$decoded = json_decode( (string) $row['detail'], true );
			$detail  = is_array( $decoded ) ? $decoded : null;
		}

		return [
			'id'         => (int) $row['id'],
			'item_id'    => $row['item_id'] !== null ? (int) $row['item_id'] : null,
			'source_id'  => $row['source_id'] !== null ? (int) $row['source_id'] : null,
			'post_id'    => $row['post_id'] !== null ? (int) $row['post_id'] : null,
			'type'       => (string) ( $row['stage'] ?? '' ),
			'from_state' => (string) ( $row['from_state'] ?? '' ),
			'to_state'   => (string) ( $row['to_state'] ?? '' ),
			'level'      => (string) $row['level'],
			'message'    => (string) $row['message'],
			'detail'     => $detail,
			'time'       => (string) $row['created_at'],
		];
	}
}
