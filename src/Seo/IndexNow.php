<?php

namespace AggregateIt\Seo;

use AggregateIt\Settings;
use AggregateIt\Support\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Fast indexing via IndexNow (Bing/Yandex et al.): serves the verification key file and
 * pings the API when a post is published or a living post gets a novelty update. Free,
 * no dependency. Google has no equivalent open endpoint for normal content, so we rely
 * on sitemap lastmod + internal links there rather than faking it.
 */
final class IndexNow {

	private const OPTION   = 'aggregate_it_indexnow_key';
	private const ENDPOINT = 'https://api.indexnow.org/indexnow';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'maybe_serve_key' ] );
		add_action( 'aggregate_it_publish_ping', [ $this, 'ping' ] );
	}

	public function maybe_serve_key(): void {
		$key = $this->key();
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( $uri !== '' && strpos( $uri, '/' . $key . '.txt' ) === 0 ) {
			header( 'Content-Type: text/plain' );
			echo esc_html( $key );
			exit;
		}
	}

	public function ping( int $post_id ): void {
		if ( ! $this->settings->indexnow_enabled() ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( ! $url || get_post_status( $post_id ) !== 'publish' ) {
			return;
		}

		$key = $this->key();

		$response = wp_remote_get(
			add_query_arg(
				[
					'url'         => rawurlencode( $url ),
					'key'         => $key,
					'keyLocation' => rawurlencode( home_url( '/' . $key . '.txt' ) ),
				],
				self::ENDPOINT
			),
			[ 'timeout' => 5 ]
		);

		if ( is_wp_error( $response ) ) {
			EventLog::warning( sprintf( 'IndexNow ping for post #%d failed: %s', $post_id, $response->get_error_message() ) );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			EventLog::warning( sprintf( 'IndexNow rejected post #%d with HTTP %d.', $post_id, $code ) );
		}
	}

	public function key(): string {
		$key = get_option( self::OPTION );
		if ( ! $key ) {
			$key = wp_generate_password( 32, false );
			update_option( self::OPTION, $key, false );
		}
		return (string) $key;
	}
}
