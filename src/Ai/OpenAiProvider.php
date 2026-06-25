<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI provider. Structured output via Chat Completions JSON mode; embeddings via
 * text-embedding-3-small. Default model gpt-4o-mini (cheaper than Claude Haiku).
 */
final class OpenAiProvider extends BaseHttpProvider {

	private const CHAT_ENDPOINT  = 'https://api.openai.com/v1/chat/completions';
	private const EMBED_ENDPOINT = 'https://api.openai.com/v1/embeddings';
	private const DEFAULT_MODEL  = 'gpt-4o-mini';
	private const EMBED_MODEL    = 'text-embedding-3-small';

	/** input/output USD per million tokens. */
	private const PRICING = [
		'gpt-4o-mini'   => [ 0.15, 0.60 ],
		'gpt-4.1-mini'  => [ 0.40, 1.60 ],
		'gpt-4.1-nano'  => [ 0.10, 0.40 ],
		'gpt-4o'        => [ 2.50, 10.0 ],
	];

	public function key(): string {
		return 'openai';
	}

	public function structured( string $prompt, array $schema, array $opts = [] ): array {
		$model = $this->settings->ai_model() ?: self::DEFAULT_MODEL;

		$response = wp_remote_post(
			self::CHAT_ENDPOINT,
			[
				'timeout' => 120,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->settings->api_key(),
					'content-type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'           => $model,
						'messages'        => [ [ 'role' => 'user', 'content' => $prompt . $this->schema_hint( $schema ) ] ],
						'response_format' => [ 'type' => 'json_object' ],
					]
				),
			]
		);

		$data = $this->decode( $response );

		$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		$in   = (int) ( $data['usage']['prompt_tokens'] ?? 0 );
		$out  = (int) ( $data['usage']['completion_tokens'] ?? 0 );

		return [
			'result'   => $this->first_json( $text ),
			'tokens'   => $in + $out,
			'cost_usd' => $this->cost( self::PRICING, $model, self::DEFAULT_MODEL, $in, $out ),
		];
	}

	public function embed( string $text ): array {
		$response = wp_remote_post(
			self::EMBED_ENDPOINT,
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->settings->api_key(),
					'content-type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'model' => self::EMBED_MODEL, 'input' => $text ] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->hash_embedding( $text );
		}

		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$vector = $data['data'][0]['embedding'] ?? null;
		if ( ! is_array( $vector ) ) {
			return $this->hash_embedding( $text );
		}

		return [
			'vector'   => array_map( 'floatval', $vector ),
			'tokens'   => (int) ( $data['usage']['total_tokens'] ?? 0 ),
			'cost_usd' => 0.0,
		];
	}

	private function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'OpenAI request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || isset( $data['error'] ) ) {
			throw new \RuntimeException( 'OpenAI API error: ' . ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}
		return is_array( $data ) ? $data : [];
	}
}
