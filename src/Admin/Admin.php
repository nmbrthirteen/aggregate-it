<?php

namespace AggregateIt\Admin;

use AggregateIt\Ai\Rewriter;
use AggregateIt\Plugin;
use AggregateIt\Publish\ImageImporter;
use AggregateIt\Publish\Reprocessor;
use AggregateIt\Seo\SchemaGraph;
use AggregateIt\Seo\Seo;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SLUG = 'aggregate-it';

	/** @var string[] page hook suffixes for our screens */
	private array $hooks = [];

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_aggregate_it_save_source', [ $this, 'handle_save_source' ] );
		add_action( 'admin_post_aggregate_it_delete_source', [ $this, 'handle_delete_source' ] );
		add_action( 'admin_post_aggregate_it_import_now', [ $this, 'handle_import_now' ] );
		add_action( 'admin_post_aggregate_it_import_opml', [ $this, 'handle_import_opml' ] );
		add_action( 'admin_post_aggregate_it_save_rule', [ $this, 'handle_save_rule' ] );
		add_action( 'admin_post_aggregate_it_save_fields', [ $this, 'handle_save_fields' ] );
		add_action( 'admin_post_aggregate_it_delete_rule', [ $this, 'handle_delete_rule' ] );
		add_action( 'admin_post_aggregate_it_merge_entities', [ $this, 'handle_merge_entities' ] );
		add_action( 'admin_post_aggregate_it_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_aggregate_it_dismiss_setup', [ $this, 'handle_dismiss_setup' ] );
		add_action( 'admin_post_aggregate_it_retry_article', [ $this, 'handle_retry_article' ] );
		add_action( 'admin_post_aggregate_it_retry_failed', [ $this, 'handle_retry_failed' ] );
		add_action( 'admin_post_aggregate_it_delete_article', [ $this, 'handle_delete_article' ] );
		add_action( 'admin_post_aggregate_it_refresh_image', [ $this, 'handle_refresh_image' ] );
		add_action( 'admin_post_aggregate_it_rewrite_article', [ $this, 'handle_rewrite_article' ] );
		add_action( 'admin_post_aggregate_it_bulk_articles', [ $this, 'handle_bulk_articles' ] );
		add_action( 'admin_notices', [ $this, 'feed_health_notice' ] );
	}

	public function feed_health_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dead = array_filter( $this->plugin->sources()->all(), static fn ( $s ) => $s->status === 'dead' );
		if ( ! $dead ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of feeds that stopped working */
					_n( 'Aggregate It: %d feed has stopped working.', 'Aggregate It: %d feeds have stopped working.', count( $dead ), 'aggregate-it' ),
					count( $dead )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-sources' ) ),
			esc_html__( 'Check your feeds', 'aggregate-it' )
		);
	}

	public function menu(): void {
		$brand = $this->plugin->settings()->brand_name();

		$this->hooks[] = add_menu_page(
			$brand,
			$brand,
			'manage_options',
			self::SLUG,
			[ $this, 'render_dashboard' ],
			'dashicons-rss',
			58
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'aggregate-it' ),
			__( 'Dashboard', 'aggregate-it' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render_dashboard' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Articles', 'aggregate-it' ),
			__( 'Articles', 'aggregate-it' ),
			'manage_options',
			self::SLUG . '-articles',
			[ $this, 'render_articles' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Feeds', 'aggregate-it' ),
			__( 'Feeds', 'aggregate-it' ),
			'manage_options',
			self::SLUG . '-sources',
			[ $this, 'render_sources' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Linked Pages', 'aggregate-it' ),
			__( 'Linked Pages', 'aggregate-it' ),
			'manage_options',
			self::SLUG . '-entities',
			[ $this, 'render_entities' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Settings', 'aggregate-it' ),
			__( 'Settings', 'aggregate-it' ),
			'manage_options',
			self::SLUG . '-settings',
			[ $this, 'render_settings' ]
		);
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) && strpos( $hook, self::SLUG ) === false ) {
			return;
		}

		wp_enqueue_style( 'aggregate-it-admin', AGGREGATE_IT_URL . 'assets/css/admin.css', [], $this->asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'aggregate-it-charts', AGGREGATE_IT_URL . 'assets/js/charts.js', [], $this->asset_version( 'assets/js/charts.js' ), true );
		wp_enqueue_script( 'aggregate-it-admin', AGGREGATE_IT_URL . 'assets/js/admin.js', [ 'aggregate-it-charts' ], $this->asset_version( 'assets/js/admin.js' ), true );

		wp_localize_script(
			'aggregate-it-admin',
			'AggregateItAdmin',
			[
				'root'     => esc_url_raw( rest_url( 'aggregate-it/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'provider' => $this->plugin->settings()->provider_key(),
				'i18n'     => [
					'refreshing' => __( 'Refreshing…', 'aggregate-it' ),
					'seeded'     => __( 'Sample articles added.', 'aggregate-it' ),
					'running'    => __( 'Working on it…', 'aggregate-it' ),
					'resumed'    => __( 'Back up and running.', 'aggregate-it' ),
					'failed'     => __( 'Something went wrong. Please try again.', 'aggregate-it' ),
				],
			]
		);
	}

	public function render_dashboard(): void {
		$s     = $this->plugin->settings();
		$brand = $s->brand_name();

		$setup = [
			'provider' => $s->provider_key() !== 'mock' && $s->api_key() !== '',
			'feeds'    => (bool) $this->plugin->sources()->all(),
			'types'    => (bool) $this->plugin->rules()->post_types(),
		];
		$show_setup = ! get_option( 'aggregate_it_setup_dismissed' ) && ( ! $setup['provider'] || ! $setup['feeds'] );

		require AGGREGATE_IT_PATH . 'src/Admin/views/dashboard.php';
	}

	public function handle_dismiss_setup(): void {
		$this->guard( 'aggregate_it_dismiss_setup' );
		update_option( 'aggregate_it_setup_dismissed', 1, false );
		$this->redirect( self::SLUG, '' );
	}

	public function render_articles(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$per_page = 30;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$items = $this->plugin->items();
		$rows  = $items->list_detailed( $status ?: null, $per_page, ( $paged - 1 ) * $per_page );
		$total = $items->count_filtered( $status ?: null );

		$counts = [
			''           => $items->count_filtered( null ),
			'published'  => $items->count_filtered( 'published' ),
			'processing' => $items->count_filtered( 'processing' ),
			'skipped'    => $items->count_filtered( 'skipped' ),
			'failed'     => $items->count_filtered( 'failed' ),
		];

		$feeds = [];
		foreach ( $this->plugin->sources()->all() as $source ) {
			$feeds[ $source->id ] = $source->title ?: $source->url;
		}

		require AGGREGATE_IT_PATH . 'src/Admin/views/articles.php';
	}

	public function handle_retry_article(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_retry_article_' . $id );

		$this->plugin->items()->requeue( $id );
		set_transient( 'aggregate_it_force_run', 1, 60 );
		do_action( 'aggregate_it_dispatch_queue' );

		$this->redirect( self::SLUG . '-articles', 'retried' );
	}

	public function handle_retry_failed(): void {
		$this->guard( 'aggregate_it_retry_failed' );

		$this->plugin->items()->requeue_failed();
		set_transient( 'aggregate_it_force_run', 1, 60 );
		do_action( 'aggregate_it_dispatch_queue' );

		$this->redirect( self::SLUG . '-articles', 'retried' );
	}

	public function handle_delete_article(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_delete_article_' . $id );

		$item = $this->plugin->items()->find( $id );
		if ( $item ) {
			if ( ! empty( $item->post_id ) ) {
				wp_delete_post( (int) $item->post_id ); // to trash (recoverable)
			}
			$this->plugin->items()->delete( $id );
		}

		$this->redirect( self::SLUG . '-articles', 'deleted' );
	}

	public function handle_refresh_image(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_refresh_image_' . $id );

		$item = $this->plugin->items()->find( $id );
		if ( $item && ! empty( $item->post_id ) && $item->url ) {
			$image = $this->plugin->extractor()->share_image( (string) $item->url );
			$alt   = get_the_title( (int) $item->post_id );
			( new ImageImporter( $this->plugin->settings() ) )->maybe_import( (int) $item->post_id, $image, $alt, true );
		}

		$this->redirect( self::SLUG . '-articles', 'image_refreshed' );
	}

	public function handle_rewrite_article(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_rewrite_article_' . $id );

		$item = $this->plugin->items()->find( $id );
		if ( $item && ! empty( $item->post_id ) ) {
			$rewriter = new Rewriter( $this->plugin->providers(), $this->plugin->settings() );
			$seo      = new Seo( $this->plugin->settings(), new SchemaGraph() );
			( new Reprocessor( $rewriter, $seo ) )->reprocess( (int) $item->post_id );
		}

		$this->redirect( self::SLUG . '-articles', 'rewritten' );
	}

	public function handle_bulk_articles(): void {
		$this->guard( 'aggregate_it_bulk_articles' );

		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$ids    = array_slice( array_values( array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) ) ) ), 0, 30 );

		if ( ! $ids || ! in_array( $action, [ 'delete', 'publish', 'draft', 'rewrite' ], true ) ) {
			$this->redirect( self::SLUG . '-articles', '' );
		}

		$items  = $this->plugin->items();
		$reproc = $action === 'rewrite'
			? new Reprocessor( new Rewriter( $this->plugin->providers(), $this->plugin->settings() ), new Seo( $this->plugin->settings(), new SchemaGraph() ) )
			: null;

		foreach ( $ids as $id ) {
			$item = $items->find( $id );
			if ( ! $item ) {
				continue;
			}
			$post_id = (int) ( $item->post_id ?? 0 );

			switch ( $action ) {
				case 'delete':
					if ( $post_id ) {
						wp_delete_post( $post_id );
					}
					$items->delete( $id );
					break;
				case 'publish':
					if ( $post_id ) {
						wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
					}
					break;
				case 'draft':
					if ( $post_id ) {
						wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
					}
					break;
				case 'rewrite':
					if ( $post_id && $reproc ) {
						$reproc->reprocess( $post_id );
					}
					break;
			}
		}

		$this->redirect( self::SLUG . '-articles', 'bulk_done' );
	}

	public function render_sources(): void {
		$sources = $this->plugin->sources()->all();
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$editing = $edit_id ? $this->plugin->sources()->get( $edit_id ) : null;
		$default_interval = $this->plugin->settings()->import_interval_minutes();
		require AGGREGATE_IT_PATH . 'src/Admin/views/sources.php';
	}

	public function handle_save_source(): void {
		$this->guard( 'aggregate_it_save_source' );

		$id       = (int) ( $_POST['id'] ?? 0 );
		$url      = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$interval = max( 1, (int) ( $_POST['interval_minutes'] ?? 30 ) );
		$status   = ( $_POST['status'] ?? 'active' ) === 'paused' ? 'paused' : 'active';

		$categories = array_values( array_filter( array_map( 'intval', (array) ( $_POST['categories'] ?? [] ) ) ) );
		$tags_raw   = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );
		$tags       = array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) );
		$publish    = sanitize_key( wp_unslash( $_POST['publish_status'] ?? 'default' ) );
		$include    = sanitize_text_field( wp_unslash( $_POST['include_keywords'] ?? '' ) );
		$exclude    = sanitize_text_field( wp_unslash( $_POST['exclude_keywords'] ?? '' ) );
		$length     = sanitize_key( wp_unslash( $_POST['article_length'] ?? 'default' ) );

		if ( $url === '' ) {
			$this->redirect( self::SLUG . '-sources', 'invalid' );
		}

		$settings = [
			'interval_minutes' => $interval,
			'categories'       => $categories,
			'tags'             => $tags,
			'publish_status'   => $publish,
			'include_keywords' => $include,
			'exclude_keywords' => $exclude,
			'article_length'   => $length,
		];

		$repo = $this->plugin->sources();
		if ( $id ) {
			$repo->update(
				$id,
				[
					'url'      => $url,
					'title'    => $title,
					'status'   => $status,
					'settings' => $settings,
				]
			);
		} else {
			$repo->create( $url, $title, $settings );
		}

		$this->redirect( self::SLUG . '-sources', 'saved' );
	}

	public function handle_delete_source(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_delete_source_' . $id );

		$this->plugin->sources()->delete( $id );
		$this->redirect( self::SLUG . '-sources', 'deleted' );
	}

	public function handle_import_now(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_import_now_' . $id );

		do_action( 'aggregate_it_import_now', $id );
		$this->redirect( self::SLUG . '-sources', 'imported' );
	}

	public function handle_import_opml(): void {
		$this->guard( 'aggregate_it_import_opml' );

		$opml  = (string) wp_unslash( $_POST['opml'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$repo  = $this->plugin->sources();
		$added = 0;

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $opml );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( $xml !== false ) {
			foreach ( $xml->xpath( '//outline[@xmlUrl]' ) ?: [] as $outline ) {
				$url = esc_url_raw( (string) $outline['xmlUrl'] );
				if ( $url === '' || $repo->exists_url( $url ) ) {
					continue;
				}
				$title = sanitize_text_field( (string) ( $outline['title'] ?? $outline['text'] ?? '' ) );
				$repo->create( $url, $title, [ 'interval_minutes' => $this->plugin->settings()->import_interval_minutes() ] );
				$added++;
			}
		}

		$this->redirect( self::SLUG . '-sources', $added > 0 ? 'opml_added' : 'opml_none' );
	}

	public function render_entities(): void {
		$rules    = $this->plugin->rules();
		$cpts     = $rules->post_types();
		require AGGREGATE_IT_PATH . 'src/Admin/views/entities.php';
	}

	public function handle_save_rule(): void {
		$this->guard( 'aggregate_it_save_rule' );

		// One field carries both the entity type the AI detects and the post type the
		// hubs live under — keep it dead simple: "Company" → type "company", CPT "company".
		$name        = sanitize_text_field( wp_unslash( $_POST['type_name'] ?? '' ) );
		$slug        = sanitize_key( $name !== '' ? $name : ( $_POST['target_cpt'] ?? '' ) );
		$entity_type = sanitize_key( wp_unslash( $_POST['entity_type'] ?? '' ) ) ?: $slug;
		$target_cpt  = sanitize_key( wp_unslash( $_POST['target_cpt'] ?? '' ) ) ?: $slug;
		$schema_type = $this->guess_schema( $entity_type );

		if ( $entity_type && $target_cpt ) {
			$this->plugin->rules()->add( $entity_type, $target_cpt, $schema_type );
			flush_rewrite_rules();
			$this->redirect( self::SLUG . '-entities', 'saved' );
		}

		$this->redirect( self::SLUG . '-entities', 'invalid' );
	}

	public function handle_save_fields(): void {
		$this->guard( 'aggregate_it_save_fields' );

		$index  = (int) ( $_POST['index'] ?? -1 );
		$raw    = sanitize_text_field( wp_unslash( $_POST['fields'] ?? '' ) );
		$fields = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

		$this->plugin->rules()->set_fields( $index, $fields );
		$this->redirect( self::SLUG . '-entities', 'saved' );
	}

	private function guess_schema( string $type ): string {
		$map = [
			'Organization' => [ 'company', 'companies', 'organization', 'org', 'business', 'brand', 'startup', 'vendor' ],
			'Person'       => [ 'person', 'people', 'author', 'founder', 'ceo', 'executive', 'player', 'artist' ],
			'Product'      => [ 'product', 'products', 'app', 'tool', 'device', 'gadget' ],
			'Place'        => [ 'place', 'location', 'city', 'country', 'venue', 'region' ],
		];
		foreach ( $map as $schema => $words ) {
			if ( in_array( $type, $words, true ) ) {
				return $schema;
			}
		}
		return 'Thing';
	}

	public function handle_delete_rule(): void {
		$index = (int) ( $_REQUEST['index'] ?? -1 );
		$this->guard( 'aggregate_it_delete_rule_' . $index );

		$this->plugin->rules()->remove( $index );
		$this->redirect( self::SLUG . '-entities', 'deleted' );
	}

	public function handle_merge_entities(): void {
		$this->guard( 'aggregate_it_merge_entities' );

		$source = (int) ( $_POST['source_id'] ?? 0 );
		$target = (int) ( $_POST['target_id'] ?? 0 );

		if ( $source && $target && $source !== $target ) {
			$this->plugin->entities()->merge( $source, $target );
		}

		$this->redirect( self::SLUG . '-entities', 'merged' );
	}

	public function render_settings(): void {
		$settings = $this->plugin->settings();
		require AGGREGATE_IT_PATH . 'src/Admin/views/settings.php';
	}

	public function handle_save_settings(): void {
		$this->guard( 'aggregate_it_save_settings' );

		$settings = $this->plugin->settings();
		$settings->update(
			[
				'brand_name'             => sanitize_text_field( wp_unslash( $_POST['brand_name'] ?? '' ) ),
				'provider'               => sanitize_key( wp_unslash( $_POST['provider'] ?? 'mock' ) ),
				'ai_model'               => sanitize_text_field( wp_unslash( $_POST['ai_model'] ?? '' ) ),
				'max_output_tokens'      => max( 1024, (int) ( $_POST['max_output_tokens'] ?? 8000 ) ),
				'target_post_type'       => sanitize_key( wp_unslash( $_POST['target_post_type'] ?? 'post' ) ),
				'publish_status'         => sanitize_key( wp_unslash( $_POST['publish_status'] ?? 'publish' ) ),
				'writing_instructions'   => sanitize_textarea_field( wp_unslash( $_POST['writing_instructions'] ?? '' ) ),
				'article_length'         => sanitize_key( wp_unslash( $_POST['article_length'] ?? 'auto' ) ),
				'retention_days'         => max( 0, (int) ( $_POST['retention_days'] ?? 90 ) ),
				'author_id'              => max( 0, (int) ( $_POST['author_id'] ?? 0 ) ),
				'daily_spend_cap_usd'    => max( 0, (float) ( $_POST['daily_spend_cap_usd'] ?? 5 ) ),
				'image_mode'             => sanitize_key( wp_unslash( $_POST['image_mode'] ?? 'import' ) ),
				'image_source'           => sanitize_key( wp_unslash( $_POST['image_source'] ?? 'share' ) ),
				'indexnow_enabled'       => isset( $_POST['indexnow_enabled'] ),
				'wikipedia_research'     => isset( $_POST['wikipedia_research'] ),
				'strategic_mode'         => isset( $_POST['strategic_mode'] ),
				'similarity_threshold'   => min( 1, max( 0, (float) ( $_POST['similarity_threshold'] ?? 0.82 ) ) ),
				'cluster_window_days'    => max( 1, (int) ( $_POST['cluster_window_days'] ?? 7 ) ),
				'import_interval_minutes' => max( 1, (int) ( $_POST['import_interval_minutes'] ?? 30 ) ),
				'processing_enabled'     => isset( $_POST['processing_enabled'] ),
				'processing_interval_minutes' => max( 1, (int) ( $_POST['processing_interval_minutes'] ?? 1 ) ),
				'feed_dead_after'        => max( 1, (int) ( $_POST['feed_dead_after'] ?? 5 ) ),
				'keyword_list'           => sanitize_textarea_field( wp_unslash( $_POST['keyword_list'] ?? '' ) ),
			]
		);

		$api_key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( $api_key !== '' ) {
			$settings->set_api_key( $api_key );
		}

		$voyage_key = trim( (string) wp_unslash( $_POST['voyage_api_key'] ?? '' ) );
		if ( $voyage_key !== '' ) {
			$settings->set_voyage_api_key( $voyage_key );
		}

		$this->redirect( self::SLUG . '-settings', 'saved' );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'aggregate-it' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $page, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => $page,
					'ai_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function asset_version( string $relative ): string {
		$path  = AGGREGATE_IT_PATH . $relative;
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		return $mtime ? (string) $mtime : AGGREGATE_IT_VERSION;
	}
}
