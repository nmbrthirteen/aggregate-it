<?php

namespace AggregateIt\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Entities are CPT posts; this wraps their creation, lookup, alias/sameAs storage, and
 * merging. Normalized names and aliases are stored in meta for fast exact matching.
 */
final class EntityRepository {

	public function get( int $id ): ?\WP_Post {
		$post = get_post( $id );
		return $post instanceof \WP_Post ? $post : null;
	}

	public function find_by_name( string $cpt, string $normalized ): ?int {
		$query = new \WP_Query(
			[
				'post_type'      => $cpt,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					'relation' => 'OR',
					[ 'key' => '_ai_norm_name', 'value' => $normalized ],
					[ 'key' => '_ai_aliases', 'value' => '"' . $normalized . '"', 'compare' => 'LIKE' ],
				],
			]
		);
		return $query->posts ? (int) $query->posts[0] : null;
	}

	/** @return array<int,array{id:int,norm:string}> */
	public function all_of_type( string $cpt ): array {
		$ids = get_posts(
			[
				'post_type'      => $cpt,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$out = [];
		foreach ( $ids as $id ) {
			$out[] = [ 'id' => (int) $id, 'norm' => (string) get_post_meta( $id, '_ai_norm_name', true ) ];
		}
		return $out;
	}

	/**
	 * @param array{description?:string,sameas?:string[],citations?:string[],schema_type?:string,is_stub?:bool} $data
	 */
	public function create( string $cpt, string $name, array $data ): int {
		$post_id = wp_insert_post(
			[
				'post_type'    => $cpt,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => $this->stub_body( $data['description'] ?? '' ),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'entity create failed: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_ai_canonical_name', $name );
		update_post_meta( $post_id, '_ai_norm_name', Name::normalize( $name ) );
		update_post_meta( $post_id, '_ai_aliases', [] );
		update_post_meta( $post_id, '_ai_sameas', array_values( $data['sameas'] ?? [] ) );
		update_post_meta( $post_id, '_ai_citations', array_values( $data['citations'] ?? [] ) );
		update_post_meta( $post_id, '_ai_schema_type', $data['schema_type'] ?? 'Thing' );
		update_post_meta( $post_id, '_ai_is_stub', ! empty( $data['is_stub'] ) ? 1 : 0 );

		return (int) $post_id;
	}

	public function add_alias( int $id, string $normalized ): void {
		$aliases = (array) get_post_meta( $id, '_ai_aliases', true );
		if ( $normalized !== '' && ! in_array( $normalized, $aliases, true ) ) {
			$aliases[] = $normalized;
			update_post_meta( $id, '_ai_aliases', array_values( $aliases ) );
		}
	}

	/** Merge $source into $target: move post relationships + aliases, then delete source. */
	public function merge( int $source, int $target ): void {
		global $wpdb;

		// Repoint every post that mentions the source entity.
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => $target ],
			[ 'meta_key' => '_ai_entity', 'meta_value' => $source ]
		);

		$src = $this->get( $source );
		if ( $src ) {
			$this->add_alias( $target, Name::normalize( $src->post_title ) );
			foreach ( (array) get_post_meta( $source, '_ai_aliases', true ) as $alias ) {
				$this->add_alias( $target, (string) $alias );
			}
			wp_delete_post( $source, true );
		}
	}

	private function stub_body( string $description ): string {
		return $description !== '' ? '<p>' . esc_html( $description ) . '</p>' : '';
	}
}
