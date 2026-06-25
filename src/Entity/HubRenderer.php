<?php

namespace AggregateIt\Entity;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders entity hub pages as pillar pages: Organization/Person/Product schema with
 * sameAs, plus an auto-growing "Related coverage" list of every post that mentions the
 * entity. Each new post strengthens the hub — the topical-authority + freshness flywheel.
 */
final class HubRenderer {

	public function __construct(
		private DelegationRules $rules,
		private Settings $settings
	) {}

	public function register(): void {
		add_filter( 'the_content', [ $this, 'append_related' ] );
		add_action( 'wp_head', [ $this, 'output_schema' ], 99 );
	}

	public function append_related( string $content ): string {
		if ( ! $this->is_hub() ) {
			return $content;
		}

		$posts = $this->related_posts( get_queried_object_id() );
		if ( ! $posts ) {
			return $content;
		}

		$list = '<section class="aggregate-it-related"><h2>' . esc_html__( 'Related coverage', 'aggregate-it' ) . '</h2><ul>';
		foreach ( $posts as $post_id ) {
			$list .= '<li><a href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a></li>';
		}
		$list .= '</ul></section>';

		return $content . $list;
	}

	public function output_schema(): void {
		if ( ! $this->is_hub() ) {
			return;
		}

		$id   = get_queried_object_id();
		$node = [
			'@context'    => 'https://schema.org',
			'@type'       => get_post_meta( $id, '_ai_schema_type', true ) ?: 'Thing',
			'@id'         => get_permalink( $id ) . '#entity',
			'name'        => get_post_meta( $id, '_ai_canonical_name', true ) ?: get_the_title( $id ),
			'url'         => get_permalink( $id ),
		];

		$description = wp_strip_all_tags( get_the_excerpt( $id ) );
		if ( $description ) {
			$node['description'] = $description;
		}

		$sameas = get_post_meta( $id, '_ai_sameas', true );
		if ( is_array( $sameas ) && $sameas ) {
			$node['sameAs'] = array_values( array_map( 'esc_url_raw', $sameas ) );
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $node ) . '</script>' . "\n";
	}

	/** @return int[] */
	private function related_posts( int $entity_id ): array {
		return get_posts(
			[
				'post_type'      => $this->settings->target_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [ [ 'key' => '_ai_entity', 'value' => $entity_id ] ],
			]
		);
	}

	private function is_hub(): bool {
		return is_singular( $this->rules->post_types() ) && in_the_loop() && is_main_query();
	}
}
