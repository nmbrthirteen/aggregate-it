<?php

namespace AggregateIt\Research;

defined( 'ABSPATH' ) || exit;

/**
 * Free, no-key entity research via Wikipedia's REST summary API. Returns a short extract
 * (as a cited fact) and the canonical Wikipedia URL (as a sameAs link). Best-effort:
 * skips disambiguation pages and anything without a clear summary, so a wrong match
 * simply yields nothing rather than bad data.
 */
final class WikipediaProvider implements ResearchProvider {

	private const ENDPOINT = 'https://en.wikipedia.org/api/rest_v1/page/summary/';

	public function key(): string {
		return 'wikipedia';
	}

	public function research( string $name, string $type, int $max_lookups = 3 ): array {
		$empty = [ 'facts' => [], 'sameas' => [], 'tokens' => 0, 'cost_usd' => 0.0 ];

		$title = rawurlencode( str_replace( ' ', '_', trim( $name ) ) );
		if ( $title === '' ) {
			return $empty;
		}

		$response = wp_remote_get(
			self::ENDPOINT . $title,
			[
				'timeout'    => 8,
				'user-agent' => 'AggregateIt/1.0 (WordPress plugin)',
				'headers'    => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return $empty;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ( $data['type'] ?? '' ) === 'disambiguation' ) {
			return $empty;
		}

		$extract = trim( (string) ( $data['extract'] ?? '' ) );
		$page    = (string) ( $data['content_urls']['desktop']['page'] ?? '' );
		if ( $extract === '' ) {
			return $empty;
		}

		return [
			'facts'    => $page !== '' ? [ [ 'value' => $extract, 'source' => $page ] ] : [ [ 'value' => $extract, 'source' => '' ] ],
			'sameas'   => array_values( array_filter( [ $page ] ) ),
			'tokens'   => 0,
			'cost_usd' => 0.0,
		];
	}
}
