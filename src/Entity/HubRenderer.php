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
		add_action( 'wp_enqueue_scripts', [ $this, 'styles' ] );
	}

	public function styles(): void {
		if ( is_singular( $this->rules->post_types() ) ) {
			wp_enqueue_style( 'aggregate-it-hub', AGGREGATE_IT_URL . 'assets/css/hub.css', [], AGGREGATE_IT_VERSION );
		}
	}

	public function append_related( string $content ): string {
		if ( ! $this->is_hub() ) {
			return $content;
		}

		$id      = get_queried_object_id();
		$content = $this->details_table( $id ) . $content;

		$timeline = $this->timeline_section( $id );
		if ( $timeline !== '' ) {
			return $content . $timeline;
		}

		// No timeline yet (older hub): fall back to a plain related-coverage list.
		$posts = $this->related_posts( $id );
		if ( $posts ) {
			$list = '<section class="aggregate-it-related"><h2>' . esc_html__( 'Related coverage', 'aggregate-it' ) . '</h2><ul>';
			foreach ( $posts as $post_id ) {
				$list .= '<li><a href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a></li>';
			}
			$list   .= '</ul></section>';
			$content = $content . $list;
		}

		return $content;
	}

	private function timeline_section( int $id ): string {
		$timeline = get_post_meta( $id, '_ai_timeline', true );
		if ( ! is_array( $timeline ) || ! $timeline ) {
			return '';
		}

		$items = '';
		foreach ( $timeline as $entry ) {
			$post_id = (int) ( $entry['post_id'] ?? 0 );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				continue;
			}
			$date = get_the_date( '', $post_id ) ?: gmdate( get_option( 'date_format' ), strtotime( (string) ( $entry['time'] ?? 'now' ) ) );
			$note = trim( (string) ( $entry['note'] ?? '' ) );

			$items .= '<li class="aggregate-it-news-item">'
				. '<span class="aggregate-it-news-date">' . esc_html( $date ) . '</span> '
				. '<a href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>'
				. ( $note !== '' ? '<div class="aggregate-it-news-note">' . esc_html( $note ) . '</div>' : '' )
				. '</li>';
		}

		if ( $items === '' ) {
			return '';
		}

		return '<section class="aggregate-it-timeline"><h2>' . esc_html__( 'In the news', 'aggregate-it' ) . '</h2><ul>' . $items . '</ul></section>';
	}

	private function details_table( int $id ): string {
		$fields = get_post_meta( $id, '_ai_fields', true );
		if ( ! is_array( $fields ) || ! $fields ) {
			return '';
		}

		$rows = '';
		foreach ( $fields as $label => $value ) {
			$rows .= '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}

		return '<table class="aggregate-it-details">' . $rows . '</table>';
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
