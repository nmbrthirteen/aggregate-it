<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Google Gemini provider. Structured output via generateContent with a JSON response
 * mime type; embeddings via text-embedding-004. Default model gemini-2.0-flash-lite —
 * among the cheapest text models available.
 */
final class GeminiProvider extends BaseHttpProvider {

	private const BASE          = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const DEFAULT_MODEL = 'gemini-2.0-flash-lite';
	private const EMBED_MODEL   = 'text-embedding-004';

	/** input/output USD per million tokens. */
	private const PRICING = [
		'gemini-2.0-flash-lite' => [ 0.075, 0.30 ],
		'gemini-2.0-flash'      => [ 0.10, 0.40 ],
		'gemini-2.5-flash-lite' => [ 0.10, 0.40 ],
		'gemini-2.5-flash'      => [ 0.30, 2.50 ],
	];

	public function key(): string {
		return 'gemini';
	}

	public function structured( string $prompt, array $schema, array $opts = [] ): array {
		$model = $this->settings->ai_model() ?: self::DEFAULT_MODEL;

		$response = wp_remote_post(
			self::BASE . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $this->settings->api_key() ),
			[
				'timeout' => 120,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'contents'         => [ [ 'parts' => [ [ 'text' => $prompt . $this->schema_hint( $schema ) ] ] ] ],
						'generationConfig' => [ 'responseMimeType' => 'application/json' ],
					]
				),
			]
		);

		$data = $this->decode( $response );

		$text = (string) ( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' );
		$in   = (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 );
		$out  = (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 );

		return [
			'result'   => $this->first_json( $text ),
			'tokens'   => $in + $out,
			'cost_usd' => $this->cost( self::PRICING, $model, self::DEFAULT_MODEL, $in, $out ),
		];
	}

	public function embed( string $text ): array {
		$response = wp_remote_post(
			self::BASE . self::EMBED_MODEL . ':embedContent?key=' . rawurlencode( $this->settings->api_key() ),
			[
				'timeout' => 30,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'model'   => 'models/' . self::EMBED_MODEL,
						'content' => [ 'parts' => [ [ 'text' => $text ] ] ],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->hash_embedding( $text );
		}

		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$vector = $data['embedding']['values'] ?? null;
		if ( ! is_array( $vector ) ) {
			return $this->hash_embedding( $text );
		}

		return [
			'vector'   => array_map( 'floatval', $vector ),
			'tokens'   => 0,
			'cost_usd' => 0.0,
		];
	}

	private function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Gemini request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || isset( $data['error'] ) ) {
			throw new \RuntimeException( 'Gemini API error: ' . ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}
		return is_array( $data ) ? $data : [];
	}
}
