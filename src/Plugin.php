<?php

namespace AggregateIt;

use AggregateIt\Admin\Admin;
use AggregateIt\Admin\PostMetaBox;
use AggregateIt\Admin\RestController;
use AggregateIt\Admin\Stats;
use AggregateIt\Ai\ProviderFactory;
use AggregateIt\Ai\FactsGuard;
use AggregateIt\Ai\Rewriter;
use AggregateIt\Cluster\ClusterRepository;
use AggregateIt\Cluster\Clusterer;
use AggregateIt\Cluster\Deduplicator;
use AggregateIt\Cost\CostMeter;
use AggregateIt\Cost\SpendCap;
use AggregateIt\Entity\DelegationRules;
use AggregateIt\Entity\EntityLinker;
use AggregateIt\Entity\EntityRegistrar;
use AggregateIt\Entity\EntityRepository;
use AggregateIt\Entity\EntityResearcher;
use AggregateIt\Entity\EntityResolver;
use AggregateIt\Entity\HubRenderer;
use AggregateIt\Keyword\KeywordStrategy;
use AggregateIt\Pipeline\ClusterStage;
use AggregateIt\Pipeline\ComposeStage;
use AggregateIt\Pipeline\EmbedStage;
use AggregateIt\Pipeline\EntityStage;
use AggregateIt\Pipeline\ExtractStage;
use AggregateIt\Pipeline\Pipeline;
use AggregateIt\Publish\CategoryResolver;
use AggregateIt\Publish\ImageImporter;
use AggregateIt\Publish\PostFactory;
use AggregateIt\Publish\RelatedArticles;
use AggregateIt\Publish\Reprocessor;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Queue\QueueWorker;
use AggregateIt\Seo\IndexNow;
use AggregateIt\Seo\SchemaGraph;
use AggregateIt\Seo\Seo;
use AggregateIt\Seo\SlugGenerator;
use AggregateIt\Source\ContentExtractor;
use AggregateIt\Source\HttpFetcher;
use AggregateIt\Source\Importer;
use AggregateIt\Source\SourceRepository;
use AggregateIt\Vector\VectorStore;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private ItemStore $items;
	private Pipeline $pipeline;
	private ProviderFactory $providers;
	private CostMeter $cost;
	private SpendCap $cap;
	private SourceRepository $sources;
	private ContentExtractor $extractor;
	private DelegationRules $rules;
	private EntityRepository $entities;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new Settings();
		$this->items     = new ItemStore();
		$this->pipeline  = new Pipeline();
		$this->providers = new ProviderFactory( $this->settings );
		$this->cost      = new CostMeter();
		$this->cap       = new SpendCap( $this->settings, $this->cost );
		$this->sources   = new SourceRepository();
		$this->extractor = new ContentExtractor( new HttpFetcher() );
		$this->rules     = new DelegationRules();
		$this->entities  = new EntityRepository();
	}

	public function boot(): void {
		load_plugin_textdomain( 'aggregate-it', false, dirname( plugin_basename( AGGREGATE_IT_FILE ) ) . '/languages' );

		Database\Schema::maybe_upgrade();

		$vectors      = new VectorStore();
		$facts        = new FactsGuard();
		$cluster_repo = new ClusterRepository();
		$clusterer    = new Clusterer( $vectors, $cluster_repo, $facts, $this->settings );
		$rewriter     = $this->rewriter();
		$keywords     = new KeywordStrategy( $this->settings );
		$categories   = new CategoryResolver( $this->settings );
		$post_factory = new PostFactory( $this->settings, new SlugGenerator(), $this->sources, $categories );
		$related      = new RelatedArticles( $vectors, $cluster_repo, $this->settings );
		$seo          = $this->seo();

		$this->pipeline->register( new ExtractStage( $this->extractor, $this->items, $this->settings ) );
		$this->pipeline->register( new EmbedStage( $this->providers, $vectors, $this->cost ) );
		$this->pipeline->register( new ClusterStage( $clusterer, $vectors, $this->items ) );
		$this->pipeline->register(
			new ComposeStage( $rewriter, $facts, $keywords, $cluster_repo, $post_factory, $this->imageImporter(), $related, $seo, $vectors, $this->items, $this->cost, $this->settings, $categories, $clusterer )
		);
		$this->pipeline->register(
			new EntityStage(
				$this->rules,
				new EntityResolver( $this->entities ),
				new EntityResearcher( $this->settings, $this->providers ),
				new EntityLinker(),
				$this->entities
			)
		);
		$this->pipeline->register_passthroughs();

		$seo->register();
		$related->register();
		( new IndexNow( $this->settings ) )->register();
		( new EntityRegistrar( $this->rules ) )->register();
		( new HubRenderer( $this->rules, $this->settings ) )->register();

		if ( $this->settings->wikipedia_research() ) {
			add_filter( 'aggregate_it_research_provider', static fn () => new \AggregateIt\Research\WikipediaProvider() );
		}

		( new Importer( $this->sources, $this->items, $this->settings, new \AggregateIt\Source\Parser\ScraperParser( new HttpFetcher() ) ) )->register();
		( new QueueWorker( $this->items, $this->pipeline, $this->cost, $this->cap, $this->settings ) )->register();
		( new Maintenance\Retention( $this->items, $this->settings ) )->register();
		( new RestController( $this ) )->register();

		if ( is_admin() ) {
			( new Admin( $this ) )->register();
			( new PostMetaBox( $this->settings ) )->register();
		}

		do_action( 'aggregate_it_booted', $this );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'aggregate_it_process_queue' );
		wp_clear_scheduled_hook( 'aggregate_it_import' );
		wp_clear_scheduled_hook( 'aggregate_it_retention' );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function items(): ItemStore {
		return $this->items;
	}

	public function pipeline(): Pipeline {
		return $this->pipeline;
	}

	public function providers(): ProviderFactory {
		return $this->providers;
	}

	public function cost(): CostMeter {
		return $this->cost;
	}

	public function cap(): SpendCap {
		return $this->cap;
	}

	public function sources(): SourceRepository {
		return $this->sources;
	}

	public function extractor(): ContentExtractor {
		return $this->extractor;
	}

	public function rules(): DelegationRules {
		return $this->rules;
	}

	public function entities(): EntityRepository {
		return $this->entities;
	}

	public function stats(): Stats {
		return new Stats( $this->items, $this->cost, $this->cap, $this->settings );
	}

	public function rewriter(): Rewriter {
		return new Rewriter( $this->providers, $this->settings );
	}

	public function seo(): Seo {
		return new Seo( $this->settings, new SchemaGraph() );
	}

	public function reprocessor(): Reprocessor {
		return new Reprocessor( $this->rewriter(), $this->seo() );
	}

	public function imageImporter(): ImageImporter {
		return new ImageImporter( $this->settings );
	}

	public function deduplicator(): Deduplicator {
		return new Deduplicator( $this->settings );
	}

	public function seed_enabled(): bool {
		return (bool) apply_filters( 'aggregate_it_enable_seed', defined( 'WP_DEBUG' ) && WP_DEBUG );
	}
}
