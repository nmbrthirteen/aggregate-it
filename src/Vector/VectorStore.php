<?php

namespace AggregateIt\Vector;

use AggregateIt\Database\Schema;
use AggregateIt\Support\Vector;

defined( 'ABSPATH' ) || exit;

/**
 * Stores packed float32 embeddings and finds nearest neighbours by brute-force cosine.
 * The live set is thousands of rows at single-site scale, so a full scan inside a queue
 * job is cheap — and it keeps the plugin free of an external vector database.
 */
final class VectorStore {

	private function table(): string {
		return Schema::table( 'vectors' );
	}

	/** @param float[] $vector */
	public function put( string $owner_type, int $owner_id, array $vector ): void {
		global $wpdb;
		$table = $this->table();
		$wpdb->delete( $table, [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ] );
		$wpdb->insert(
			$table,
			[
				'owner_type' => $owner_type,
				'owner_id'   => $owner_id,
				'vector'     => Vector::pack( $vector ),
				'dims'       => count( $vector ),
			]
		);
	}

	/** @return float[] */
	public function get( string $owner_type, int $owner_id ): array {
		global $wpdb;
		$blob = $wpdb->get_var(
			$wpdb->prepare( "SELECT vector FROM {$this->table()} WHERE owner_type = %s AND owner_id = %d", $owner_type, $owner_id )
		);
		return $blob ? Vector::unpack( (string) $blob ) : [];
	}

	/**
	 * Best matches for a query vector among rows of $owner_type, scored by cosine.
	 *
	 * @param float[] $query
	 * @param int[]   $only_ids restrict the search to these owner ids (empty = all)
	 * @return array<int,array{owner_id:int,score:float}> sorted by score desc
	 */
	public function similar( string $owner_type, array $query, array $only_ids = [], int $limit = 5 ): array {
		global $wpdb;
		$table = $this->table();

		if ( $only_ids ) {
			$ids          = array_map( 'intval', $only_ids );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT owner_id, vector FROM {$table} WHERE owner_type = %s AND owner_id IN ( {$placeholders} )",
					array_merge( [ $owner_type ], $ids )
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT owner_id, vector FROM {$table} WHERE owner_type = %s", $owner_type )
			);
		}

		$scored = [];
		foreach ( (array) $rows as $row ) {
			$scored[] = [
				'owner_id' => (int) $row->owner_id,
				'score'    => Vector::cosine( $query, Vector::unpack( (string) $row->vector ) ),
			];
		}

		usort( $scored, static fn ( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $scored, 0, $limit );
	}
}
