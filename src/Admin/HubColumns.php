<?php

namespace AggregateIt\Admin;

use AggregateIt\Source\ScraperPostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "Origin" column to the list table of every post type Aggregate It writes to, so
 * scraped items and AI-created topic hubs are distinguishable at a glance.
 */
final class HubColumns {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'hook_columns' ] );
	}

	public function hook_columns(): void {
		$entity_types = (array) apply_filters( 'aggregate_it_entity_post_types', [] );
		$types        = array_unique( array_merge( $entity_types, ScraperPostTypes::all() ) );

		foreach ( $types as $type ) {
			if ( ! post_type_exists( (string) $type ) ) {
				continue;
			}
			add_filter( "manage_{$type}_posts_columns", [ $this, 'columns' ] );
			add_action( "manage_{$type}_posts_custom_column", [ $this, 'render' ], 10, 2 );
		}
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( $key === 'title' ) {
				$out['ai_origin'] = __( 'Origin', 'aggregate-it' );
			}
		}
		if ( ! isset( $out['ai_origin'] ) ) {
			$out['ai_origin'] = __( 'Origin', 'aggregate-it' );
		}
		return $out;
	}

	public function render( string $column, int $post_id ): void {
		if ( $column !== 'ai_origin' ) {
			return;
		}

		// Inline styles: this column renders on the native edit.php list, which is outside the
		// plugin's CSS scope.
		$base = 'display:inline-block;padding:1px 8px;border-radius:3px;font-size:11px;';

		if ( get_post_meta( $post_id, '_ai_scraped', true ) ) {
			printf( '<span style="%sbackground:#d7f0dd;color:#0a6c2e;">%s</span>', esc_attr( $base ), esc_html__( 'Scraped', 'aggregate-it' ) );
		} elseif ( get_post_meta( $post_id, '_ai_canonical_name', true ) ) {
			printf( '<span style="%sbackground:#e7e7ea;color:#3c434a;">%s</span>', esc_attr( $base ), esc_html__( 'Topic hub', 'aggregate-it' ) );
		} else {
			echo '<span style="color:#646970;">—</span>';
		}
	}
}
