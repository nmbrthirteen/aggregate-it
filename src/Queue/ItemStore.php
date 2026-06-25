<?php

namespace AggregateIt\Queue;

use AggregateIt\Database\Schema;
use AggregateIt\Pipeline\Item;
use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * The work queue, backed by the ai_items table. Items are claimed with an atomic
 * UPDATE…LIMIT (claim_token) and advanced one stage per claim. Mirrors crm-connect's
 * QueueStore claiming + backoff + stale-recovery.
 */
final class ItemStore {

	private const STALE_CLAIM_MINUTES = 5;

	private function table(): string {
		return Schema::table( 'items' );
	}

	/** @param array<string,mixed> $data */
	public function enqueue( int $source_id, string $guid, string $url, string $raw_content, array $flags = [] ): int {
		global $wpdb;
		$now = $this->utc_now();

		$wpdb->insert(
			$this->table(),
			[
				'source_id'       => $source_id,
				'guid'            => $guid,
				'url'             => $url,
				'raw_content'     => $raw_content,
				'content_hash'    => hash( 'sha256', $raw_content ),
				'state'           => Schema::STATE_FETCHED,
				'flags'           => Json::encode( $flags ),
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);

		return (int) $wpdb->insert_id;
	}

	public function exists_guid( int $source_id, string $guid ): bool {
		global $wpdb;
		$table = $this->table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE source_id = %d AND guid = %s LIMIT 1", $source_id, $guid )
		);
	}

	public function exists_hash( string $content_hash ): bool {
		global $wpdb;
		$table = $this->table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $content_hash )
		);
	}

	public function update_content( int $id, string $content ): void {
		$this->update(
			$id,
			[
				'raw_content'  => $content,
				'content_hash' => hash( 'sha256', $content ),
			]
		);
	}

	/** Push an item's next attempt into the future without consuming a retry. */
	public function defer( int $id, int $seconds ): void {
		$this->update(
			$id,
			[
				'claim_token'     => null,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, $seconds ) ),
			]
		);
	}

	public function set_cluster( int $id, int $cluster_id ): void {
		$this->update( $id, [ 'cluster_id' => $cluster_id ] );
	}

	public function set_post( int $id, int $post_id ): void {
		$this->update( $id, [ 'post_id' => $post_id ] );
	}

	/** @return Item[] */
	public function claim_due( int $limit ): array {
		global $wpdb;
		$table = $this->table();
		$now   = $this->utc_now();
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * MINUTE_IN_SECONDS );
		$token = wp_generate_uuid4();

		$terminal     = Schema::TERMINAL;
		$placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

		$params = array_merge(
			[ $token, $now ],
			$terminal,
			[ $now, $stale, $limit ]
		);

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET claim_token = %s, updated_at = %s
				 WHERE state NOT IN ( {$placeholders} )
				   AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
				   AND ( claim_token IS NULL OR updated_at <= %s )
				 ORDER BY id ASC
				 LIMIT %d",
				$params
			)
		);

		if ( ! $claimed ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE claim_token = %s ORDER BY id ASC", $token )
		);

		return array_map( [ Item::class, 'from_row' ], $rows ?: [] );
	}

	public function advance( int $id, string $next_state, array $flags ): void {
		$this->update(
			$id,
			[
				'state'           => $next_state,
				'flags'           => Json::encode( $flags ),
				'attempts'        => 0,
				'last_error'      => null,
				'claim_token'     => null,
				'next_attempt_at' => $this->utc_now(),
			]
		);
	}

	public function retry( int $id, int $attempts, string $error, string $next_attempt_at ): void {
		$this->update(
			$id,
			[
				'attempts'        => $attempts,
				'last_error'      => $error,
				'claim_token'     => null,
				'next_attempt_at' => $next_attempt_at,
			]
		);
	}

	public function dead_letter( int $id, int $attempts, string $error ): void {
		$this->update(
			$id,
			[
				'state'       => Schema::STATE_DEAD_LETTER,
				'attempts'    => $attempts,
				'last_error'  => $error,
				'claim_token' => null,
			]
		);
	}

	public function requeue( int $id ): void {
		$this->update(
			$id,
			[
				'state'           => Schema::STATE_FETCHED,
				'attempts'        => 0,
				'last_error'      => null,
				'claim_token'     => null,
				'next_attempt_at' => $this->utc_now(),
			]
		);
	}

	/** @return array<string,int> state => count */
	public function state_counts(): array {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", ARRAY_A );

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['state'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public function total(): int {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Items reaching a state per day for the last $days days (throughput chart).
	 *
	 * @return array<int,array{date:string,count:int}>
	 */
	public function daily_published( int $days = 14 ): array {
		global $wpdb;
		$table = $this->table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(updated_at) AS d, COUNT(*) AS total
				 FROM {$table} WHERE state = %s AND updated_at >= %s GROUP BY DATE(updated_at)",
				Schema::STATE_PUBLISHED,
				$since
			),
			ARRAY_A
		);

		$by_date = [];
		foreach ( (array) $rows as $row ) {
			$by_date[ (string) $row['d'] ] = (int) $row['total'];
		}

		$series = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[] = [
				'date'  => $date,
				'count' => $by_date[ $date ] ?? 0,
			];
		}
		return $series;
	}

	/** @return object[] */
	public function recent( int $limit = 20 ): array {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT id, source_id, url, state, attempts, last_error, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit )
		) ?: [];
	}

	private function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = $this->utc_now();
		$wpdb->update( $this->table(), $data, [ 'id' => $id ] );
	}

	private function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
