<?php

namespace AggregateIt\Admin;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the original source text, source citations, and any flagged invented figures on
 * the editor for generated posts — the "what did the AI change" view.
 */
final class PostMetaBox {

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ] );
	}

	public function add(): void {
		add_meta_box(
			'aggregate-it-source',
			__( 'Aggregate It — where this came from', 'aggregate-it' ),
			[ $this, 'render' ],
			$this->settings->target_post_type(),
			'normal',
			'default'
		);
	}

	public function render( \WP_Post $post ): void {
		$cluster_id = get_post_meta( $post->ID, '_ai_cluster_id', true );
		if ( ! $cluster_id ) {
			echo '<p>' . esc_html__( 'Aggregate It did not create this post.', 'aggregate-it' ) . '</p>';
			return;
		}

		$original = (string) get_post_meta( $post->ID, '_ai_original', true );
		$urls     = (array) get_post_meta( $post->ID, '_ai_source_urls', true );
		$invented = (array) get_post_meta( $post->ID, '_ai_invented', true );

		echo '<p><strong>' . esc_html__( 'Story:', 'aggregate-it' ) . '</strong> #' . (int) $cluster_id . '</p>';

		if ( $urls ) {
			echo '<p><strong>' . esc_html__( 'Where this came from:', 'aggregate-it' ) . '</strong></p><ul>';
			foreach ( $urls as $url ) {
				echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></li>';
			}
			echo '</ul>';
		}

		$hub_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $post->ID, '_ai_entity', false ) ) ) ) );
		if ( $hub_ids ) {
			echo '<p><strong>' . esc_html__( 'Topic hubs linked from this article:', 'aggregate-it' ) . '</strong></p><ul>';
			foreach ( $hub_ids as $hub_id ) {
				$title = get_the_title( $hub_id );
				$edit  = get_edit_post_link( $hub_id );
				$label = $title !== '' ? $title : '#' . $hub_id;
				echo '<li>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $invented ) {
			echo '<p><span class="ai-state ai-state--dead_letter">' . esc_html__( 'Possible made-up numbers', 'aggregate-it' ) . '</span> <span class="ai-muted">' . esc_html( implode( ', ', $invented ) ) . '</span></p>';
		}

		echo '<p><strong>' . esc_html__( 'Original article text:', 'aggregate-it' ) . '</strong></p>';
		echo '<textarea readonly rows="10" class="large-text code">' . esc_textarea( $original ) . '</textarea>';
	}
}
