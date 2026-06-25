<?php

namespace AggregateIt\Publish;

use AggregateIt\Pipeline\Item;
use AggregateIt\Seo\SlugGenerator;
use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

final class PostFactory {

	public function __construct(
		private Settings $settings,
		private SlugGenerator $slugs
	) {}

	/**
	 * @param array<string,mixed> $structured
	 * @param string[]            $source_urls
	 */
	public function create( int $cluster_id, Item $item, array $structured, string $keyword, array $source_urls ): int {
		$title = (string) ( $structured['seo_title'] ?? $item->flags['title'] ?? 'Untitled' );
		$slug  = (string) ( $structured['slug'] ?? '' );
		$slug  = $this->slugs->generate( $slug !== '' ? $slug : $keyword, $title );

		$post_id = wp_insert_post(
			[
				'post_type'    => $this->settings->target_post_type(),
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $this->body( (string) ( $structured['rewritten_body'] ?? '' ) ),
				'post_author'  => $this->settings->author_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'wp_insert_post failed: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_ai_cluster_id', $cluster_id );
		update_post_meta( $post_id, '_ai_source_urls', array_values( array_filter( $source_urls ) ) );
		update_post_meta( $post_id, '_ai_original', (string) $item->raw_content );
		update_post_meta( $post_id, '_ai_facts', (array) ( $structured['facts'] ?? [] ) );
		update_post_meta( $post_id, '_ai_schema_type', $item->flags['schema_type'] ?? 'Article' );
		update_post_meta( $post_id, '_ai_prompt_version', AGGREGATE_IT_VERSION );

		return (int) $post_id;
	}

	public function append_update( int $post_id, string $update_body, string $source_url ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$heading = sprintf(
			/* translators: %s: human-readable date */
			__( 'Update — %s', 'aggregate-it' ),
			date_i18n( get_option( 'date_format' ) )
		);

		$section = "\n\n<!-- aggregate-it-update -->\n<h3>" . esc_html( $heading ) . "</h3>\n" . $this->body( $update_body );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $post->post_content . $section,
			]
		);

		$urls   = get_post_meta( $post_id, '_ai_source_urls', true );
		$urls   = is_array( $urls ) ? $urls : [];
		$urls[] = $source_url;
		update_post_meta( $post_id, '_ai_source_urls', array_values( array_unique( array_filter( $urls ) ) ) );
	}

	private function body( string $text ): string {
		$paragraphs = preg_split( '/\n{2,}/', trim( $text ) ) ?: [];
		$html       = '';
		foreach ( $paragraphs as $p ) {
			$p = trim( $p );
			if ( $p !== '' ) {
				$html .= '<p>' . esc_html( $p ) . "</p>\n";
			}
		}
		return $html;
	}
}
