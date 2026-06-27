<?php

namespace AggregateIt\Admin;

use AggregateIt\Plugin;
use AggregateIt\Settings;
use AggregateIt\Support\EventLog;

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
		add_action( 'admin_post_aggregate_it_bulk_add_sources', [ $this, 'handle_bulk_add_sources' ] );
		add_action( 'admin_post_aggregate_it_save_blacklist', [ $this, 'handle_save_blacklist' ] );
		add_action( 'admin_post_aggregate_it_export_config', [ $this, 'handle_export_config' ] );
		add_action( 'admin_post_aggregate_it_import_config', [ $this, 'handle_import_config' ] );
		add_action( 'admin_post_aggregate_it_clear_logs', [ $this, 'handle_clear_logs' ] );
		add_action( 'admin_post_aggregate_it_reset', [ $this, 'handle_reset' ] );
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

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Tools', 'aggregate-it' ),
			__( 'Tools', 'aggregate-it' ),
			'manage_options',
			self::SLUG . '-tools',
			[ $this, 'render_tools' ]
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
				'canSeed'  => $this->plugin->seed_enabled(),
				'i18n'     => [
					'refreshing' => __( 'Refreshing…', 'aggregate-it' ),
					'seeded'     => __( 'Sample articles added.', 'aggregate-it' ),
					'running'    => __( 'Working on it…', 'aggregate-it' ),
					'started'    => __( 'Started — new posts will appear shortly.', 'aggregate-it' ),
					'resumed'    => __( 'Back up and running.', 'aggregate-it' ),
					'failed'     => __( 'Something went wrong. Please try again.', 'aggregate-it' ),
					'expired'    => __( 'Your session expired. Reload the page to continue.', 'aggregate-it' ),
					'testing'    => __( 'Testing the connection…', 'aggregate-it' ),
					'testOk'     => __( 'Connection works.', 'aggregate-it' ),
					'testFail'   => __( 'Connection failed:', 'aggregate-it' ),
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
		$can_seed   = $this->plugin->seed_enabled();

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
			$this->plugin->imageImporter()->maybe_import( (int) $item->post_id, $image, $alt, true );
		}

		$this->redirect( self::SLUG . '-articles', 'image_refreshed' );
	}

	public function handle_rewrite_article(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->guard( 'aggregate_it_rewrite_article_' . $id );

		$item = $this->plugin->items()->find( $id );
		if ( $item && ! empty( $item->post_id ) ) {
			$this->plugin->reprocessor()->reprocess( (int) $item->post_id );
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
		$reproc = $action === 'rewrite' ? $this->plugin->reprocessor() : null;

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
		if ( ! $categories ) {
			$categories = $this->infer_categories_from_feed_url( $url );
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
				$repo->create( $url, $title, $this->default_source_settings( $url ) );
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

		if ( ! $source || ! $target || $source === $target || ! $this->is_entity_post( $source ) || ! $this->is_entity_post( $target ) ) {
			$this->redirect( self::SLUG . '-entities', 'merge_invalid' );
		}

		$this->plugin->entities()->merge( $source, $target );
		$this->redirect( self::SLUG . '-entities', 'merged' );
	}

	private function is_entity_post( int $id ): bool {
		$post = $id ? get_post( $id ) : null;
		return $post && in_array( $post->post_type, $this->plugin->rules()->post_types(), true );
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
				'related_articles'       => isset( $_POST['related_articles'] ),
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

	public function render_tools(): void {
		$settings = $this->plugin->settings();
		$flash    = get_transient( 'aggregate_it_flash' );
		if ( $flash ) {
			delete_transient( 'aggregate_it_flash' );
		}
		$flash_type = 'success';
		if ( is_array( $flash ) ) {
			$flash_type = sanitize_key( (string) ( $flash['type'] ?? 'success' ) );
			$flash      = (string) ( $flash['message'] ?? '' );
		}
		$blacklist = $settings->blacklist_raw();
		$events    = EventLog::all();
		$info      = $this->system_info();
		require AGGREGATE_IT_PATH . 'src/Admin/views/tools.php';
	}

	public function handle_bulk_add_sources(): void {
		$this->guard( 'aggregate_it_bulk_add_sources' );

		$raw   = (string) wp_unslash( $_POST['urls'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		$repo  = $this->plugin->sources();
		$added = 0;

		foreach ( $lines as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( $url === '' || $repo->exists_url( $url ) ) {
				continue;
			}
			$repo->create( $url, '', $this->default_source_settings( $url ) );
			$added++;
		}

		/* translators: %d: number of feeds added */
		$this->flash( sprintf( _n( '%d feed added.', '%d feeds added.', $added, 'aggregate-it' ), $added ) );
		$this->redirect( self::SLUG . '-tools', '' );
	}

	public function handle_save_blacklist(): void {
		$this->guard( 'aggregate_it_save_blacklist' );

		$this->plugin->settings()->set( 'blacklist', sanitize_textarea_field( wp_unslash( $_POST['blacklist'] ?? '' ) ) );
		$this->flash( __( 'Blacklist saved.', 'aggregate-it' ) );
		$this->redirect( self::SLUG . '-tools', '' );
	}

	public function handle_export_config(): void {
		$this->guard( 'aggregate_it_export_config' );

		$data = [
			'aggregate_it_export' => AGGREGATE_IT_VERSION,
			'exported_at'         => gmdate( 'c' ),
			'settings'            => $this->exportable_settings(),
			'blacklist'           => $this->plugin->settings()->blacklist(),
			'delegation_rules'    => $this->plugin->rules()->all(),
			'sources'             => array_map(
				static fn ( $s ) => [
					'url'      => $s->url,
					'title'    => $s->title,
					'status'   => $s->status,
					'settings' => $s->settings,
				],
				$this->plugin->sources()->all()
			),
		];

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="aggregate-it-config-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function handle_import_config(): void {
		$this->guard( 'aggregate_it_import_config' );

		$upload = $_FILES['config'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $upload ) ) {
			$this->flash( __( 'Choose a JSON or XML file to import.', 'aggregate-it' ), 'error' );
			$this->redirect( self::SLUG . '-tools', '' );
		}

		$raw = $this->read_import_upload( $upload );
		if ( $raw === null ) {
			$this->redirect( self::SLUG . '-tools', '' );
		}

		if ( str_starts_with( ltrim( $raw ), '<' ) ) {
			$this->flash( $this->import_wxr_config( $raw ) );
			$this->redirect( self::SLUG . '-tools', '' );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$this->flash( __( 'That file is not valid JSON or WordPress XML.', 'aggregate-it' ), 'error' );
			$this->redirect( self::SLUG . '-tools', '' );
		}

		if ( isset( $data['aggregate_it_export'] ) ) {
			$this->flash( $this->import_native_config( $data ) );
		} elseif ( isset( $data['wprss_settings_general'] ) || isset( $data['wprss_settings_ftp'] ) ) {
			$this->flash( $this->import_wprss_config( $data ) );
		} else {
			$added = $this->add_feed_urls( $this->collect_feed_urls( $data ) );
			if ( $added > 0 ) {
				/* translators: %d: number of feeds added */
				$this->flash( sprintf( _n( 'Imported %d feed from that file.', 'Imported %d feeds from that file.', $added, 'aggregate-it' ), $added ) );
			} else {
				$this->flash( __( 'The JSON file was valid, but no feed URLs were found to import.', 'aggregate-it' ), 'error' );
			}
		}

		$this->redirect( self::SLUG . '-tools', '' );
	}

	public function handle_clear_logs(): void {
		$this->guard( 'aggregate_it_clear_logs' );
		EventLog::clear();
		$this->flash( __( 'Activity log cleared.', 'aggregate-it' ) );
		$this->redirect( self::SLUG . '-tools', '' );
	}

	public function handle_reset(): void {
		$this->guard( 'aggregate_it_reset' );

		switch ( sanitize_key( wp_unslash( $_POST['reset'] ?? '' ) ) ) {
			case 'settings':
				Settings::reset();
				$this->flash( __( 'Settings reset to defaults.', 'aggregate-it' ) );
				break;
			case 'queue':
				$cleared = $this->plugin->items()->clear_all();
				/* translators: %d: number of queued items removed */
				$this->flash( sprintf( _n( 'Removed %d item from the queue.', 'Removed %d items from the queue.', $cleared, 'aggregate-it' ), $cleared ) );
				break;
			default:
				$this->flash( __( 'Nothing was reset.', 'aggregate-it' ) );
		}

		$this->redirect( self::SLUG . '-tools', '' );
	}

	private function flash( string $message, string $type = 'success' ): void {
		set_transient(
			'aggregate_it_flash',
			[
				'message' => $message,
				'type'    => $type,
			],
			30
		);
	}

	/**
	 * @param array<string,mixed> $upload
	 */
	private function read_import_upload( array $upload ): ?string {
		$error = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( $error !== UPLOAD_ERR_OK ) {
			$this->flash( $this->upload_error_message( $error ), 'error' );
			return null;
		}

		$tmp_name = (string) ( $upload['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! is_uploaded_file( $tmp_name ) ) {
			$this->flash( __( 'The uploaded file could not be read. Please choose the JSON file again and retry.', 'aggregate-it' ), 'error' );
			return null;
		}

		$raw = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $raw === false || trim( $raw ) === '' ) {
			$this->flash( __( 'That file is empty.', 'aggregate-it' ), 'error' );
			return null;
		}

		return (string) $raw;
	}

	private function upload_error_message( int $error ): string {
		return match ( $error ) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __( 'That file is too large for this server to upload.', 'aggregate-it' ),
			UPLOAD_ERR_PARTIAL => __( 'The file upload did not finish. Please try again.', 'aggregate-it' ),
			UPLOAD_ERR_NO_FILE => __( 'Choose a JSON or XML file to import.', 'aggregate-it' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'The server is missing a temporary upload folder.', 'aggregate-it' ),
			UPLOAD_ERR_CANT_WRITE => __( 'The server could not write the uploaded file.', 'aggregate-it' ),
			UPLOAD_ERR_EXTENSION => __( 'A PHP extension stopped the file upload.', 'aggregate-it' ),
			default => __( 'The file could not be uploaded. Please try again.', 'aggregate-it' ),
		};
	}

	/** @return array<string,mixed> settings safe to export — secrets are never written out */
	private function exportable_settings(): array {
		$all = $this->plugin->settings()->all();
		unset( $all['api_key'], $all['voyage_api_key'] );
		return $all;
	}

	/** @param array<string,mixed> $data */
	private function import_native_config( array $data ): string {
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$settings = $data['settings'];
			unset( $settings['api_key'], $settings['voyage_api_key'] );
			$this->plugin->settings()->update( $settings );
		}
		if ( array_key_exists( 'blacklist', $data ) ) {
			$list = is_array( $data['blacklist'] ) ? implode( "\n", $data['blacklist'] ) : (string) $data['blacklist'];
			$this->plugin->settings()->set( 'blacklist', sanitize_textarea_field( $list ) );
		}
		if ( isset( $data['delegation_rules'] ) && is_array( $data['delegation_rules'] ) ) {
			$this->plugin->rules()->replace( $data['delegation_rules'] );
			flush_rewrite_rules();
		}

		$added = 0;
		foreach ( (array) ( $data['sources'] ?? [] ) as $source ) {
			$url = esc_url_raw( (string) ( $source['url'] ?? '' ) );
			if ( $url === '' || $this->plugin->sources()->exists_url( $url ) ) {
				continue;
			}
			$this->plugin->sources()->create( $url, sanitize_text_field( (string) ( $source['title'] ?? '' ) ), (array) ( $source['settings'] ?? [] ) );
			$added++;
		}

		/* translators: %d: number of feeds added */
		return __( 'Configuration restored.', 'aggregate-it' ) . ' ' . sprintf( _n( '%d feed added.', '%d feeds added.', $added, 'aggregate-it' ), $added );
	}

	/** @param array<string,mixed> $data */
	private function import_wprss_config( array $data ): string {
		$ftp = (array) ( $data['wprss_settings_ftp'] ?? [] );
		$gen = (array) ( $data['wprss_settings_general'] ?? [] );
		$map = [];

		if ( ! empty( $ftp['post_type'] ) ) {
			$map['target_post_type'] = sanitize_key( (string) $ftp['post_type'] );
		}
		if ( ! empty( $ftp['post_status'] ) && in_array( $ftp['post_status'], [ 'publish', 'draft', 'pending' ], true ) ) {
			$map['publish_status'] = (string) $ftp['post_status'];
		}
		if ( isset( $ftp['save_images_locally'] ) ) {
			$map['image_mode'] = ( 'true' === $ftp['save_images_locally'] || true === $ftp['save_images_locally'] ) ? 'import' : 'off';
		}
		$cron = (string) ( $gen['cron_interval'] ?? '' );
		$minutes = [ 'hourly' => 60, 'twicedaily' => 720, 'daily' => 1440 ][ $cron ] ?? 0;
		if ( $minutes ) {
			$map['import_interval_minutes'] = $minutes;
		}

		if ( $map ) {
			$this->plugin->settings()->update( $map );
		}

		$added = $this->add_feed_urls( $this->collect_feed_urls( $data ) );

		if ( $added === 0 ) {
			/* translators: %d: number of settings mapped */
			return sprintf(
				__( 'Imported from WP RSS Aggregator: %d setting(s) mapped. No feed sources were found in that file; WP RSS Aggregator source exports are separate from its settings export.', 'aggregate-it' ),
				count( $map )
			);
		}

		/* translators: 1: number of settings mapped, 2: number of feeds added */
		return sprintf(
			__( 'Imported from WP RSS Aggregator: %1$d setting(s) mapped, %2$d feed(s) added.', 'aggregate-it' ),
			count( $map ),
			$added
		);
	}

	private function import_wxr_config( string $raw ): string {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $raw );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( $xml === false || ! isset( $xml->channel->item ) ) {
			$this->flash( __( 'That file is not valid WordPress XML.', 'aggregate-it' ), 'error' );
			$this->redirect( self::SLUG . '-tools', '' );
		}

		$sources = [];
		foreach ( $xml->channel->item as $item ) {
			$wp = $item->children( 'http://wordpress.org/export/1.2/' );
			if ( (string) $wp->post_type !== 'wprss_feed' ) {
				continue;
			}

			$url = '';
			foreach ( $wp->postmeta as $meta ) {
				if ( (string) $meta->meta_key === 'wprss_url' ) {
					$url = (string) $meta->meta_value;
					break;
				}
			}

			if ( $url !== '' ) {
				$sources[] = [
					'url'   => $url,
					'title' => (string) $item->title,
				];
			}
		}

		$added = $this->add_feed_sources( $sources );
		if ( $added === 0 ) {
			return __( 'The WordPress XML file was valid, but no new WP RSS Aggregator feed sources were found to import.', 'aggregate-it' );
		}

		/* translators: %d: number of feeds added */
		return sprintf( _n( 'Imported %d WP RSS Aggregator feed from WordPress XML.', 'Imported %d WP RSS Aggregator feeds from WordPress XML.', $added, 'aggregate-it' ), $added );
	}

	/**
	 * @param mixed $data
	 * @return string[]
	 */
	private function collect_feed_urls( $data ): array {
		$urls = [];
		array_walk_recursive(
			$data,
			static function ( $value ) use ( &$urls ) {
				if ( ! is_string( $value ) || ! preg_match( '#^https?://#i', $value ) ) {
					return;
				}
				$path  = (string) wp_parse_url( $value, PHP_URL_PATH );
				$query = (string) wp_parse_url( $value, PHP_URL_QUERY );
				if ( preg_match( '#\.(rss|atom|xml)$#i', $path )
					|| preg_match( '#(^|/)(feed|rss|atom)(/|$)#i', $path )
					|| preg_match( '#(^|&)feed=#i', $query ) ) {
					$urls[] = $value;
				}
			}
		);
		return array_values( array_unique( $urls ) );
	}

	/** @param string[] $urls */
	private function add_feed_urls( array $urls ): int {
		return $this->add_feed_sources(
			array_map(
				static fn ( string $url ) => [
					'url'   => $url,
					'title' => '',
				],
				$urls
			)
		);
	}

	/** @param array<int,array{url:string,title:string}> $sources */
	private function add_feed_sources( array $sources ): int {
		$repo  = $this->plugin->sources();
		$added = 0;
		foreach ( $sources as $source ) {
			$clean = esc_url_raw( $source['url'] );
			if ( $clean === '' || $repo->exists_url( $clean ) ) {
				continue;
			}
			$repo->create( $clean, sanitize_text_field( $source['title'] ), $this->default_source_settings( $clean ) );
			$added++;
		}
		return $added;
	}

	/** @return array<string,mixed> */
	private function default_source_settings( string $url ): array {
		return [
			'interval_minutes' => $this->plugin->settings()->import_interval_minutes(),
			'categories'       => $this->infer_categories_from_feed_url( $url ),
		];
	}

	/** @return int[] existing category IDs inferred from a feed URL path */
	private function infer_categories_from_feed_url( string $url ): array {
		if ( ! in_array( 'category', get_object_taxonomies( $this->plugin->settings()->target_post_type() ), true ) ) {
			return [];
		}

		$terms = get_categories( [ 'hide_empty' => false, 'number' => 0 ] );
		if ( ! $terms ) {
			return [];
		}

		$term_map = [];
		foreach ( $terms as $term ) {
			$term_map[ sanitize_title( (string) $term->slug ) ] = (int) $term->term_id;
			$term_map[ sanitize_title( (string) $term->name ) ] = (int) $term->term_id;
		}

		foreach ( $this->category_candidates_from_url( $url ) as $candidate ) {
			$slug = sanitize_title( $candidate );
			if ( isset( $term_map[ $slug ] ) ) {
				return [ $term_map[ $slug ] ];
			}

			foreach ( $term_map as $term_slug => $term_id ) {
				if ( str_contains( $slug, $term_slug ) || str_contains( $term_slug, $slug ) ) {
					return [ $term_id ];
				}
			}
		}

		return [];
	}

	/** @return string[] */
	private function category_candidates_from_url( string $url ): array {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $path === '' ) {
			return [];
		}

		$skip     = [ 'feed', 'rss', 'atom', 'xml', 'topic', 'topics', 'category', 'categories', 'tag', 'tags' ];
		$segments = array_values(
			array_filter(
				array_map(
					static function ( string $segment ): string {
						$segment = strtolower( rawurldecode( trim( $segment ) ) );
						return preg_replace( '#\.(rss|atom|xml)$#', '', $segment ) ?: '';
					},
					explode( '/', trim( $path, '/' ) )
				),
				static fn ( string $segment ): bool => $segment !== ''
			)
		);

		$prioritized = [];
		foreach ( $segments as $index => $segment ) {
			if ( in_array( $segment, [ 'topic', 'topics', 'category', 'categories' ], true ) ) {
				$prioritized = array_merge( $prioritized, array_slice( $segments, $index + 1 ) );
			}
		}

		$candidates = array_merge( $prioritized, $segments );
		return array_values(
			array_unique(
				array_filter(
					$candidates,
					static fn ( string $segment ): bool => ! in_array( $segment, $skip, true )
				)
			)
		);
	}

	/** @return array<string,string> */
	private function system_info(): array {
		$s       = $this->plugin->settings();
		$counts  = $this->plugin->items()->state_counts();
		$sources = $this->plugin->sources()->all();
		$active  = array_filter( $sources, static fn ( $src ) => $src->status === 'active' );
		$import  = wp_next_scheduled( 'aggregate_it_import' );
		$process = wp_next_scheduled( 'aggregate_it_process_queue' );

		return [
			__( 'Plugin version', 'aggregate-it' )  => AGGREGATE_IT_VERSION,
			__( 'WordPress', 'aggregate-it' )        => get_bloginfo( 'version' ),
			__( 'PHP', 'aggregate-it' )              => PHP_VERSION,
			__( 'AI service', 'aggregate-it' )       => $s->provider_key(),
			__( 'Model', 'aggregate-it' )            => $s->ai_model() ?: __( '(default)', 'aggregate-it' ),
			__( 'API key set', 'aggregate-it' )      => $s->api_key() !== '' ? __( 'yes', 'aggregate-it' ) : __( 'no', 'aggregate-it' ),
			__( 'Encryption (OpenSSL)', 'aggregate-it' ) => function_exists( 'openssl_encrypt' ) ? __( 'yes', 'aggregate-it' ) : __( 'no', 'aggregate-it' ),
			__( 'Feeds (active / total)', 'aggregate-it' ) => count( $active ) . ' / ' . count( $sources ),
			__( 'In the queue', 'aggregate-it' )     => (string) ( array_sum( $counts ) - ( $counts['published'] ?? 0 ) - ( $counts['dead_letter'] ?? 0 ) ),
			__( 'Failed', 'aggregate-it' )           => (string) ( $counts['dead_letter'] ?? 0 ),
			__( 'Next feed check', 'aggregate-it' )  => $import ? gmdate( 'Y-m-d H:i', $import ) . ' UTC' : __( 'not scheduled', 'aggregate-it' ),
			__( 'Next processing run', 'aggregate-it' ) => $process ? gmdate( 'Y-m-d H:i', $process ) . ' UTC' : __( 'not scheduled', 'aggregate-it' ),
			__( 'Memory limit', 'aggregate-it' )     => (string) ini_get( 'memory_limit' ),
		];
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
