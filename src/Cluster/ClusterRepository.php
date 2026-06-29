<?php

namespace AggregateIt\Cluster;

use AggregateIt\Database\Schema;
use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

final class ClusterRepository {

	private function table(): string {
		return Schema::table( 'clusters' );
	}

	/** @param string[] $fact_set */
	public function create( string $primary_keyword, array $fact_set, int $window_days ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->table(),
			[
				'primary_keyword' => $primary_keyword,
				'primary_entities' => Json::encode( [] ),
				'fact_set'        => Json::encode( array_values( $fact_set ) ),
				'status'          => 'live',
				'window_until'    => gmdate( 'Y-m-d H:i:s', time() + $window_days * DAY_IN_SECONDS ),
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);
		return (int) $wpdb->insert_id;
	}

	public function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) ?: null;
	}

	/** @return object[] clusters that published a post, oldest first (id, canonical_post_id, fact_set) */
	public function with_posts(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT id, canonical_post_id, fact_set FROM {$this->table()}
			 WHERE canonical_post_id IS NOT NULL AND canonical_post_id > 0
			 ORDER BY id ASC"
		) ?: [];
	}

	/** @return int[] ids of live clusters still inside their time window */
	public function live_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE status = %s AND ( window_until IS NULL OR window_until >= %s )",
				'live',
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return array_map( 'intval', $ids ?: [] );
	}

	public function set_canonical_post( int $id, int $post_id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), [ 'canonical_post_id' => $post_id, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
	}

	/** @return string[] */
	public function fact_set( int $id ): array {
		$cluster = $this->get( $id );
		return $cluster ? (array) Json::decode( $cluster->fact_set ?? null, [] ) : [];
	}

	/** @param string[] $new_facts */
	public function merge_facts( int $id, array $new_facts ): void {
		global $wpdb;
		$merged = array_values( array_unique( array_merge( $this->fact_set( $id ), $new_facts ) ) );
		$wpdb->update(
			$this->table(),
			[ 'fact_set' => Json::encode( $merged ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $id ]
		);
	}
}
