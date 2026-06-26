<?php

namespace AggregateIt\Publish;

use AggregateIt\Cluster\ClusterRepository;
use AggregateIt\Settings;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic internal linking between articles. On publish, finds the most semantically
 * similar other stories and cross-links their posts — stored as `_ai_related` meta
 * (both directions, no content churn) and rendered live as a "Related articles" list.
 */
final class RelatedArticles {

	private const MIN_SCORE = 0.55;
	private const MAX_LINKS = 4;

	public function __construct(
		private VectorStore $vectors,
		private ClusterRepository $clusters,
		private Settings $settings
	) {}

	public function register(): void {
		add_filter( 'the_content', [ $this, 'append' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'styles' ] );
	}

	/**
	 * @param float[] $vector the new story's embedding
	 */
	public function build( int $post_id, int $cluster_id, array $vector ): void {
		if ( ! $this->settings->related_articles() || ! $vector ) {
			return;
		}

		$related = [];
		foreach ( $this->vectors->similar( 'cluster', $vector, [], self::MAX_LINKS + 4 ) as $match ) {
			if ( (int) $match['owner_id'] === $cluster_id || $match['score'] < self::MIN_SCORE ) {
				continue;
			}
			$cluster = $this->clusters->get( (int) $match['owner_id'] );
			$pid     = (int) ( $cluster->canonical_post_id ?? 0 );
			if ( $pid && $pid !== $post_id && get_post( $pid ) ) {
				$related[] = $pid;
			}
			if ( count( $related ) >= self::MAX_LINKS ) {
				break;
			}
		}

		if ( ! $related ) {
			return;
		}

		update_post_meta( $post_id, '_ai_related', $related );

		// Backlink: add this post to each related post's list (meta only, no content edit).
		foreach ( $related as $pid ) {
			$theirs = get_post_meta( $pid, '_ai_related', true );
			$theirs = is_array( $theirs ) ? $theirs : [];
			if ( ! in_array( $post_id, $theirs, true ) ) {
				$theirs[] = $post_id;
				update_post_meta( $pid, '_ai_related', array_slice( array_values( array_unique( $theirs ) ), -6 ) );
			}
		}
	}

	public function append( string $content ): string {
		if ( ! is_singular( $this->settings->target_post_type() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$related = get_post_meta( get_the_ID(), '_ai_related', true );
		if ( ! is_array( $related ) || ! $related ) {
			return $content;
		}

		$items = '';
		foreach ( $related as $pid ) {
			if ( get_post( (int) $pid ) && get_post_status( (int) $pid ) === 'publish' ) {
				$items .= '<li><a href="' . esc_url( get_permalink( (int) $pid ) ) . '">' . esc_html( get_the_title( (int) $pid ) ) . '</a></li>';
			}
		}

		if ( $items === '' ) {
			return $content;
		}

		return $content . '<section class="aggregate-it-related"><h2>' . esc_html__( 'Related articles', 'aggregate-it' ) . '</h2><ul>' . $items . '</ul></section>';
	}

	public function styles(): void {
		if ( is_singular( $this->settings->target_post_type() ) ) {
			wp_enqueue_style( 'aggregate-it-hub', AGGREGATE_IT_URL . 'assets/css/hub.css', [], AGGREGATE_IT_VERSION );
		}
	}
}
