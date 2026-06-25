<?php

namespace AggregateIt\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the native JSON-LD @graph for our posts: Article/NewsArticle with both
 * datePublished and dateModified (the freshness signal that makes living posts legible
 * to search), author, publisher, and image. Entity
 * about/mentions are layered in by the entity engine (phase 3).
 */
final class SchemaGraph {

	/** @return array<string,mixed> */
	public function build( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$permalink = get_permalink( $post );
		$type      = get_post_meta( $post_id, '_ai_schema_type', true ) ?: 'Article';

		$article = [
			'@type'            => $type,
			'@id'              => $permalink . '#article',
			'headline'         => get_the_title( $post ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => $permalink ],
			'author'           => $this->author( (int) $post->post_author ),
			'publisher'        => $this->publisher(),
		];

		$description = get_post_meta( $post_id, '_ai_meta_description', true );
		if ( $description ) {
			$article['description'] = $description;
		}

		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( $image ) {
			$article['image'] = $image;
		}

		$about = $this->about( $post_id );
		if ( $about ) {
			$article['about']    = $about;
			$article['mentions'] = $about;
		}

		$graph = [ '@context' => 'https://schema.org', '@graph' => [ $article ] ];

		return (array) apply_filters( 'aggregate_it_schema_graph', $graph, $post_id );
	}

	private function author( int $author_id ): array {
		return [
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', $author_id ) ?: get_bloginfo( 'name' ),
			'url'   => get_author_posts_url( $author_id ),
		];
	}

	private function publisher(): array {
		$publisher = [
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		];

		$logo = get_site_icon_url();
		if ( $logo ) {
			$publisher['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
		}
		return $publisher;
	}

	/** @return array<int,array<string,mixed>> entity hubs this post is about */
	private function about( int $post_id ): array {
		$entity_ids = get_post_meta( $post_id, '_ai_entities', true );
		if ( ! is_array( $entity_ids ) ) {
			return [];
		}

		$about = [];
		foreach ( $entity_ids as $entity_id ) {
			$entity = get_post( (int) $entity_id );
			if ( ! $entity ) {
				continue;
			}
			$node = [
				'@type' => get_post_meta( $entity_id, '_ai_schema_type', true ) ?: 'Thing',
				'@id'   => get_permalink( $entity_id ) . '#entity',
				'name'  => get_post_meta( $entity_id, '_ai_canonical_name', true ) ?: get_the_title( $entity ),
				'url'   => get_permalink( $entity_id ),
			];
			$sameas = get_post_meta( $entity_id, '_ai_sameas', true );
			if ( is_array( $sameas ) && $sameas ) {
				$node['sameAs'] = array_values( $sameas );
			}
			$about[] = $node;
		}
		return $about;
	}
}
