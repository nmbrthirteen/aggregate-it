<?php

namespace AggregateIt\Admin;

use AggregateIt\Plugin;

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
		add_action( 'admin_post_aggregate_it_save_rule', [ $this, 'handle_save_rule' ] );
		add_action( 'admin_post_aggregate_it_delete_rule', [ $this, 'handle_delete_rule' ] );
		add_action( 'admin_post_aggregate_it_merge_entities', [ $this, 'handle_merge_entities' ] );
		add_action( 'admin_post_aggregate_it_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_aggregate_it_retry_article', [ $this, 'handle_retry_article' ] );
		add_action( 'admin_post_aggregate_it_retry_failed', [ $this, 'handle_retry_failed' ] );
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
		$brand = $this->plugin->settings()->brand_name();
		require AGGREGATE_IT_PATH . 'src/Admin/views/dashboard.php';
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

		if ( $url === '' ) {
			$this->redirect( self::SLUG . '-sources', 'invalid' );
		}

		$settings = [
			'interval_minutes' => $interval,
			'categories'       => $categories,
			'tags'             => $tags,
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
				'author_id'              => max( 0, (int) ( $_POST['author_id'] ?? 0 ) ),
				'daily_spend_cap_usd'    => max( 0, (float) ( $_POST['daily_spend_cap_usd'] ?? 5 ) ),
				'image_mode'             => sanitize_key( wp_unslash( $_POST['image_mode'] ?? 'import' ) ),
				'image_source'           => sanitize_key( wp_unslash( $_POST['image_source'] ?? 'share' ) ),
				'indexnow_enabled'       => isset( $_POST['indexnow_enabled'] ),
				'strategic_mode'         => isset( $_POST['strategic_mode'] ),
				'similarity_threshold'   => min( 1, max( 0, (float) ( $_POST['similarity_threshold'] ?? 0.82 ) ) ),
				'cluster_window_days'    => max( 1, (int) ( $_POST['cluster_window_days'] ?? 7 ) ),
				'import_interval_minutes' => max( 1, (int) ( $_POST['import_interval_minutes'] ?? 30 ) ),
				'processing_enabled'     => isset( $_POST['processing_enabled'] ),
				'processing_interval_minutes' => max( 1, (int) ( $_POST['processing_interval_minutes'] ?? 1 ) ),
				'feed_dead_after'        => max( 1, (int) ( $_POST['feed_dead_after'] ?? 5 ) ),
				'disclosure'             => sanitize_text_field( wp_unslash( $_POST['disclosure'] ?? '' ) ),
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
