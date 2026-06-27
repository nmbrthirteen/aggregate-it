<?php

namespace AggregateIt\Admin;

use AggregateIt\Cost\SpendCap;
use AggregateIt\Plugin;
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
