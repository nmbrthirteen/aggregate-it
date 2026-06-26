<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Ai\FactsGuard;
use AggregateIt\Ai\Rewriter;
use AggregateIt\Cluster\ClusterRepository;
use AggregateIt\Cost\CostMeter;
use AggregateIt\Database\Schema;
use AggregateIt\Keyword\KeywordStrategy;
use AggregateIt\Publish\ImageImporter;
use AggregateIt\Publish\PostFactory;
use AggregateIt\Publish\RelatedArticles;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Seo\Seo;
use AggregateIt\Settings;
use AggregateIt\Support\EventLog;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `clustered` -> `entity_linked`, the paid AI step. New clusters get a faithful
 * rewrite and a fresh living post; matched clusters pass through the novelty gate and
 * either append a dated update or are suppressed. Thin items and strategic-mode misses
 * are suppressed rather than published.
 */
final class ComposeStage implements PaidStage {

	public function __construct(
		private Rewriter $rewriter,
		private FactsGuard $facts,
		private KeywordStrategy $keywords,
		private ClusterRepository $clusters,
		private PostFactory $posts,
		private ImageImporter $images,
		private RelatedArticles $related,
		private Seo $seo,
		private VectorStore $vectors,
		private ItemStore $items,
		private CostMeter $cost,
		private Settings $settings
	) {}

	public function handles(): string {
		return Schema::STATE_CLUSTERED;
	}

	/** Per-feed article-length override, or null to use the global setting. */
	private function length( Item $item ): ?string {
		$len = (string) ( $item->flags['article_length'] ?? '' );
		return $len !== '' ? $len : null;
	}

	public function process( Item $item ): string {
		if ( ! empty( $item->flags['thin'] ) ) {
			$item->flags['suppressed'] = 'thin';
			return Schema::STATE_ENTITY_LINKED;
		}

		$is_update = $item->cluster_id !== null && empty( $item->flags['cluster_new'] );

		return $is_update ? $this->update( $item ) : $this->create( $item );
	}

	private function create( Item $item ): string {
		$content = (string) $item->raw_content;
		$title   = (string) ( $item->flags['title'] ?? '' );

		$rewrite    = $this->rewriter->rewrite( $title, $content, null, $this->length( $item ) );
		$structured = $rewrite['result'];
		$this->cost->record( Schema::STATE_CLUSTERED, $rewrite['tokens'], $rewrite['cost_usd'], $item->id );

		$decision = $this->keywords->resolve( (string) ( $structured['primary_keyword'] ?? '' ), $content );
		if ( $decision['skip'] ) {
			$item->flags['suppressed'] = 'no-keyword-match';
			return Schema::STATE_ENTITY_LINKED;
		}
		$keyword = $decision['keyword'];

		$cluster_id = $this->clusters->create( $keyword, $this->facts->salient( $content ), $this->settings->cluster_window_days() );
		$vector     = $this->vectors->get( 'item', $item->id );
		$this->vectors->put( 'cluster', $cluster_id, $vector );
		$this->items->set_cluster( $item->id, $cluster_id );
		$item->cluster_id = $cluster_id;

		$item->flags['entities'] = (array) ( $structured['entities'] ?? [] );

		$post_id = $this->posts->create( $cluster_id, $item, $structured, $keyword, [ $item->url ] );

		$invented = $this->facts->invented( $content, (string) ( $structured['rewritten_body'] ?? '' ) );
		if ( $invented ) {
			$item->flags['invented'] = $invented;
			update_post_meta( $post_id, '_ai_invented', $invented );
			EventLog::warning( sprintf( 'Post #%d: possible made-up numbers: %s', $post_id, implode( ', ', $invented ) ) );
		}

		$this->images->maybe_import( $post_id, (string) ( $item->flags['image'] ?? '' ), (string) ( $structured['seo_title'] ?? '' ) );

		$this->seo->write_meta(
			$post_id,
			[
				'title'         => (string) ( $structured['seo_title'] ?? '' ),
				'description'   => (string) ( $structured['meta_description'] ?? '' ),
				'focus_keyword' => $keyword,
			]
		);

		$this->clusters->set_canonical_post( $cluster_id, $post_id );
		$this->items->set_post( $item->id, $post_id );
		$item->flags['post_id'] = $post_id;

		$this->related->build( $post_id, $cluster_id, $vector );

		return Schema::STATE_ENTITY_LINKED;
	}

	private function update( Item $item ): string {
		$content   = (string) $item->raw_content;
		$cluster   = $this->clusters->get( (int) $item->cluster_id );
		$known     = $this->clusters->fact_set( (int) $item->cluster_id );
		$novel     = $this->facts->novel( $content, $known );

		if ( ! $novel || ! $cluster || ! $cluster->canonical_post_id ) {
			$item->flags['suppressed'] = 'no-novelty';
			return Schema::STATE_ENTITY_LINKED;
		}

		$rewrite    = $this->rewriter->rewrite( (string) ( $item->flags['title'] ?? '' ), $content, null, $this->length( $item ) );
		$structured = $rewrite['result'];
		$this->cost->record( Schema::STATE_CLUSTERED, $rewrite['tokens'], $rewrite['cost_usd'], $item->id );

		$post_id = (int) $cluster->canonical_post_id;
		$this->posts->append_update( $post_id, (string) ( $structured['rewritten_body'] ?? '' ), $item->url );
		$this->clusters->merge_facts( (int) $item->cluster_id, $novel );
		$this->items->set_post( $item->id, $post_id );

		$item->flags['updated_post'] = $post_id;
		EventLog::info( sprintf( 'Post #%d updated with %d new fact(s).', $post_id, count( $novel ) ) );

		return Schema::STATE_ENTITY_LINKED;
	}
}
