<?php

namespace AggregateIt\Publish;

use AggregateIt\Pipeline\Item;
use AggregateIt\Seo\SlugGenerator;
use AggregateIt\Settings;
use AggregateIt\Source\SourceRepository;

defined( 'ABSPATH' ) || exit;

final class PostFactory {

	public function __construct(
		private Settings $settings,
		private SlugGenerator $slugs,
		private SourceRepository $sources,
		private CategoryResolver $categories
	) {}

	/**
	 * @param array<string,mixed> $structured
	 * @param string[]            $source_urls
	 */
	public function create( int $cluster_id, Item $item, array $structured, string $keyword, array $source_urls ): int {
		$title = (string) ( $structured['seo_title'] ?? $item->flags['title'] ?? 'Untitled' );
		$slug  = (string) ( $structured['slug'] ?? '' );
		$slug  = $this->slugs->generate( $slug !== '' ? $slug : $keyword, $title );

		$source = $item->source_id ? $this->sources->get( $item->source_id ) : null;
		$status = $source ? $source->publish_status( $this->settings->publish_status() ) : $this->settings->publish_status();

		$post_id = wp_insert_post(
			[
				'post_type'    => $this->settings->target_post_type(),
				'post_status'  => $status,
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

		$this->assign_terms( (int) $post_id, $item->source_id, (string) ( $structured['category'] ?? '' ) );

		return (int) $post_id;
	}

	/**
	 * Categorize by the AI's per-article topic (created on first use), falling back to the
	 * feed's configured categories when the AI gives nothing. Tags come from the feed.
	 */
	private function assign_terms( int $post_id, int $source_id, string $ai_category ): void {
		$source = $source_id ? $this->sources->get( $source_id ) : null;
		$taxes  = get_object_taxonomies( $this->settings->target_post_type() );

		if ( in_array( 'category', $taxes, true ) ) {
			$ids = [];
			if ( $this->settings->ai_categorize() && $ai_category !== '' ) {
				$term_id = $this->categories->resolve( $ai_category );
				if ( $term_id ) {
					$ids[] = $term_id;
				}
			}
			if ( ! $ids && $source ) {
				$ids = $source->categories();
			}
			if ( $ids ) {
				wp_set_post_terms( $post_id, $ids, 'category', false );
			}
		}

		$tags = $source ? $source->tags() : [];
		if ( $tags && in_array( 'post_tag', $taxes, true ) ) {
			wp_set_post_terms( $post_id, $tags, 'post_tag', false );
		}
	}

	/**
	 * Publish a scraped/passthrough item verbatim: map its fields onto the source's chosen
	 * post type with no AI rewrite, so structured data (dates, venues) is never invented.
	 */
	public function create_mapped( Item $item ): int {
		$source    = $item->source_id ? $this->sources->get( $item->source_id ) : null;
		$post_type = $source && $source->post_type_connection() !== '' ? $source->post_type_connection() : $this->settings->target_post_type();
		$status    = $source ? $source->publish_status( $this->settings->publish_status() ) : $this->settings->publish_status();

		$values = $this->mapped_values( $item );
		$mapped = FieldMapper::map( $values, $source ? $source->field_map() : [] );

		$title = (string) ( $mapped['post']['post_title'] ?? ( $item->flags['title'] ?? 'Untitled' ) );
		$body  = (string) ( $mapped['post']['post_content'] ?? '' );

		$postarr = [
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_name'    => $this->slugs->generate( $title, $title ),
			'post_content' => str_contains( $body, '<' ) ? $body : $this->body( $body ),
			'post_author'  => $this->settings->author_id(),
		];
		if ( ! empty( $mapped['post']['post_excerpt'] ) ) {
			$postarr['post_excerpt'] = (string) $mapped['post']['post_excerpt'];
		}
		if ( ! empty( $mapped['post']['post_date'] ) ) {
			$postarr['post_date'] = (string) $mapped['post']['post_date'];
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'wp_insert_post failed: ' . $post_id->get_error_message() );
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, '_ai_source_urls', array_values( array_filter( [ $item->url ] ) ) );
		update_post_meta( $post_id, '_ai_scraped', 1 );
		update_post_meta( $post_id, '_ai_prompt_version', AGGREGATE_IT_VERSION );

		foreach ( $mapped['meta'] as $key => $value ) {
			update_post_meta( $post_id, sanitize_key( (string) $key ), $value );
		}
		foreach ( $mapped['terms'] as $taxonomy => $names ) {
			if ( taxonomy_exists( (string) $taxonomy ) ) {
				wp_set_object_terms( $post_id, $names, (string) $taxonomy, false );
			}
		}

		$rules = $source ? $source->rules() : [];
		if ( $rules ) {
			foreach ( Rules::apply( $values, $rules, time() ) as $key => $value ) {
				update_post_meta( $post_id, sanitize_key( (string) $key ), $value );
			}
			update_post_meta( $post_id, '_ai_rule_values', wp_json_encode( $values ) );
			update_post_meta( $post_id, '_ai_source_id', (int) $item->source_id );
		}

		return $post_id;
	}

	/** @return array<string,string> field name => value, assembled from the item flags */
	private function mapped_values( Item $item ): array {
		$values = [
			'title'   => (string) ( $item->flags['title'] ?? '' ),
			'content' => (string) $item->raw_content,
			'image'   => (string) ( $item->flags['image'] ?? '' ),
			'url'     => (string) $item->url,
		];
		if ( ! empty( $item->flags['published_at'] ) ) {
			$values['date'] = gmdate( 'Y-m-d H:i:s', (int) $item->flags['published_at'] );
		}

		foreach ( (array) ( $item->flags['fields'] ?? [] ) as $name => $value ) {
			$values[ (string) $name ] = (string) $value;
		}

		return $values;
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
