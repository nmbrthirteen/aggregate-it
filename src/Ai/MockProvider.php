<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic, zero-cost provider used for tests and for running the pipeline before
 * a real provider is configured. Output is derived from the input so the same text
 * always yields the same result.
 */
final class MockProvider implements AiProvider {

	private const DIMS = 64;

	public function key(): string {
		return 'mock';
	}

	public function structured( string $prompt, array $schema, array $opts = [] ): array {
		// The rewrite prompt embeds the real article as "TITLE: …" and "ARTICLE:\n…".
		// Mirror those so mock output looks like the source (not the prompt instructions).
		$title = '';
		$body  = $prompt;
		if ( preg_match( '/\nTITLE:\s*(.*)/', $prompt, $m ) ) {
			$title = trim( $m[1] );
		}
		if ( preg_match( '/\nARTICLE:\s*\n(.*)$/s', $prompt, $m ) ) {
			$body = trim( $m[1] );
		}

		$title   = $title !== '' ? $title : $this->first_phrase( $body );
		$keyword = $this->first_phrase( $title );

		return [
			'result'   => [
				'rewritten_body'   => $body,
				'seo_title'        => $title,
				'meta_description' => substr( trim( $body ), 0, 155 ),
				'slug'             => sanitize_title( $title ),
				'primary_keyword'  => $keyword,
				'category'         => '',
				'entities'         => [],
				'facts'            => [],
			],
			'tokens'   => 0,
			'cost_usd' => 0.0,
		];
	}

	public function embed( string $text ): array {
		$vector = array_fill( 0, self::DIMS, 0.0 );
		foreach ( preg_split( '/\W+/', strtolower( $text ) ) ?: [] as $token ) {
			if ( $token === '' ) {
				continue;
			}
			$bucket            = abs( crc32( $token ) ) % self::DIMS;
			$vector[ $bucket ] += 1.0;
		}

		return [
			'vector'   => $vector,
			'tokens'   => 0,
			'cost_usd' => 0.0,
		];
	}

	private function first_phrase( string $text ): string {
		$words = preg_split( '/\s+/', trim( wp_strip_all_tags( $text ) ) ) ?: [];
		$words = array_slice( array_filter( $words ), 0, 4 );
		return $words ? implode( ' ', $words ) : 'untitled';
	}
}
