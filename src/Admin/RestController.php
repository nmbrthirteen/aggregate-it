<?php

namespace AggregateIt\Admin;

use AggregateIt\Cost\CostMeter;
use AggregateIt\Cost\SpendCap;
use AggregateIt\Plugin;
use AggregateIt\Source\HttpFetcher;
use AggregateIt\Source\Parser\ScraperParser;
use AggregateIt\Source\Scrape\SelectorAssistant;
use AggregateIt\Support\ActivityLog;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RestController {

	private const NS = 'aggregate-it/v1';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'stats' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/activity',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'activity' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/post-type-fields',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'post_type_fields' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/scrape-preview',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'scrape_preview' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/suggest-selectors',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'suggest_selectors' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/run',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/resume',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resume' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/seed',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'seed' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'count' => [
						'type'              => 'integer',
						'default'           => 5,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->plugin->stats()->payload(), 200 );
	}

	public function activity( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 10, min( 200, (int) $request->get_param( 'per_page' ) ?: 50 ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$filters  = [
			'level'   => sanitize_key( (string) $request->get_param( 'level' ) ),
			'type'    => sanitize_key( (string) $request->get_param( 'type' ) ),
			'item_id' => (int) $request->get_param( 'item' ),
			'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		];

		$total = ActivityLog::count( $filters );

		return new WP_REST_Response(
			[
				'rows'  => ActivityLog::query( $filters, $per_page, ( $page - 1 ) * $per_page ),
				'total' => $total,
				'page'  => $page,
				'pages' => (int) max( 1, ceil( $total / $per_page ) ),
			],
			200
		);
	}

	public function post_type_fields( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( $type === '' ) {
			return new WP_REST_Response( [ 'ok' => true, 'fields' => [] ], 200 );
		}

		$keys = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s
				 ORDER BY pm.meta_key LIMIT 200",
				$type,
				$wpdb->esc_like( '_' ) . '%'
			)
		);

		return new WP_REST_Response( [ 'ok' => true, 'fields' => array_values( array_map( 'strval', $keys ) ) ], 200 );
	}

	public function scrape_preview( WP_REST_Request $request ): WP_REST_Response {
		$url           = esc_url_raw( (string) $request->get_param( 'url' ) );
		$item_selector = sanitize_text_field( (string) $request->get_param( 'item_selector' ) );
		if ( $url === '' || $item_selector === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => __( 'Enter the page URL and item selector first.', 'aggregate-it' ) ], 200 );
		}

		$fields = [];
		foreach ( (array) $request->get_param( 'fields' ) as $field ) {
			$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$rule = [
				'selector' => sanitize_text_field( (string) ( $field['selector'] ?? '' ) ),
				'attr'     => sanitize_text_field( (string) ( $field['attr'] ?? 'text' ) ) ?: 'text',
			];
			$regex = sanitize_text_field( (string) ( $field['regex'] ?? '' ) );
			if ( $regex !== '' ) {
				$rule['regex'] = $regex;
			}
			$fields[ $name ] = $rule;
		}

		try {
			$respect_robots = $request->get_param( 'respect_robots' ) === null ? true : (bool) $request->get_param( 'respect_robots' );
			$html           = ( new HttpFetcher() )->fetch( $url, $respect_robots );
			if ( ! is_string( $html ) || $html === '' ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => __( 'Could not fetch that page.', 'aggregate-it' ) ], 200 );
			}

			$cfg     = [ 'discovery' => [ 'item_selector' => $item_selector ], 'extraction' => [ 'fields' => $fields ] ];
			$entries = ( new ScraperParser( new HttpFetcher() ) )->entries_from_html( $html, $cfg, $url );

			return new WP_REST_Response(
				[ 'ok' => true, 'count' => count( $entries ), 'sample' => array_slice( $entries, 0, 5 ) ],
				200
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage() ], 200 );
		}
	}

	public function suggest_selectors( WP_REST_Request $request ): WP_REST_Response {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( $url === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => __( 'Enter the page URL first.', 'aggregate-it' ) ], 200 );
		}

		$provider = $this->plugin->providers()->get();
		if ( $this->plugin->settings()->provider_key() !== 'mock' && $provider->key() === 'mock' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => __( 'Add an AI API key in Settings first.', 'aggregate-it' ) ], 200 );
		}

		try {
			$html = ( new HttpFetcher() )->fetch( $url );
			if ( ! is_string( $html ) || $html === '' ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => __( 'Could not fetch that page.', 'aggregate-it' ) ], 200 );
			}

			$result = ( new SelectorAssistant( $provider ) )->suggest( $html );
			( new CostMeter() )->record( 'scrape', $result['tokens'], $result['cost_usd'], null );

			return new WP_REST_Response( [ 'ok' => true, 'suggestion' => $result['suggestion'] ], 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage() ], 200 );
		}
	}

	public function run(): WP_REST_Response {
		// Force a processing pass even when automatic processing is paused.
		set_transient( 'aggregate_it_force_run', 1, 60 );
		do_action( 'aggregate_it_dispatch_queue' );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function resume(): WP_REST_Response {
		SpendCap::resume();
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function test(): WP_REST_Response {
		try {
			$provider = $this->plugin->providers()->get();
			if ( $this->plugin->settings()->provider_key() !== 'mock' && $provider->key() === 'mock' ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => __( 'No API key saved for the selected service.', 'aggregate-it' ) ], 200 );
			}
			$schema   = [
				'type'       => 'object',
				'properties' => [ 'ok' => [ 'type' => 'boolean' ] ],
				'required'   => [ 'ok' ],
			];
			$provider->structured( 'Reply with the JSON object {"ok": true} and nothing else.', $schema, [ 'max_tokens' => 64 ] );

			return new WP_REST_Response( [ 'ok' => true, 'provider' => $provider->key() ], 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $e->getMessage() ], 200 );
		}
	}

	/** Dev convenience: enqueue demo items so the pipeline + dashboard have data. */
	public function seed( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->plugin->seed_enabled() ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'seeding disabled' ], 403 );
		}

		$count = max( 1, min( 50, (int) $request->get_param( 'count' ) ) );
		$store = $this->plugin->items();
		$now   = time();

		for ( $i = 0; $i < $count; $i++ ) {
			$n = $now + $i;
			$store->enqueue(
				0,
				'demo-' . $n . '-' . wp_generate_password( 6, false ),
				'https://example.com/demo/' . $n,
				'Demo article ' . $n . '. ' . str_repeat( 'Sample body content for the pipeline. ', 8 )
			);
		}

		do_action( 'aggregate_it_dispatch_queue' );

		return new WP_REST_Response( [ 'ok' => true, 'seeded' => $count ], 200 );
	}
}
