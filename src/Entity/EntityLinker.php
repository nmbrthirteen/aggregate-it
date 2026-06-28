<?php

namespace AggregateIt\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Injects a single contextual link on the first mention of each entity (capped per post)
 * and records the bidirectional relationship as `_ai_entity` meta — which powers the
 * hub's auto-growing related-posts list and the article's schema about/mentions.
 */
final class EntityLinker {

	/**
	 * @param array<int,array{id:int,name:string,url:string}> $entities
	 */
	/** @return int number of first-mention links inserted into the post body */
	public function link( int $post_id, array $entities, int $cap ): int {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}

		$content  = $post->post_content;
		$linked   = 0;
		$ids      = [];

		// Clear prior relationships so re-runs stay idempotent.
		delete_post_meta( $post_id, '_ai_entity' );

		foreach ( $entities as $entity ) {
			if ( ! $entity['id'] || in_array( $entity['id'], $ids, true ) ) {
				continue;
			}
			$ids[] = $entity['id'];
			add_post_meta( $post_id, '_ai_entity', $entity['id'] );

			if ( $linked < $cap && $entity['name'] !== '' ) {
				$replaced = $this->link_first_mention( $content, $entity['name'], $entity['url'] );
				if ( $replaced !== $content ) {
					$content = $replaced;
					$linked++;
				}
			}
		}

		update_post_meta( $post_id, '_ai_entities', $ids );

		if ( $content !== $post->post_content ) {
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
		}

		return $linked;
	}

	private function link_first_mention( string $content, string $name, string $url ): string {
		$pattern = '/\b(' . preg_quote( $name, '/' ) . ')\b/';
		$anchor  = '<a href="' . esc_url( $url ) . '">$1</a>';
		$result  = preg_replace( $pattern, $anchor, $content, 1 );
		return is_string( $result ) ? $result : $content;
	}
}
