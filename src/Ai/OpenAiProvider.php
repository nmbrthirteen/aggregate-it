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
		$model   = $this->settings->ai_model() ?: self::DEFAULT_MODEL;
		$message = $prompt . $this->schema_hint( $schema );

		// Strict Structured Outputs guarantees every required field (e.g. category) is
		// present; plain JSON mode does not, and a dropped category silently sends the post
		// to the default WordPress category. Fall back to JSON mode if the model rejects it.
		try {
			$data = $this->chat(
				$model,
				$message,
				[
					'type'        => 'json_schema',
					'json_schema' => [ 'name' => 'article', 'strict' => true, 'schema' => $this->strict_schema( $schema ) ],
				]
			);
		} catch ( \RuntimeException $e ) {
			$data = $this->chat( $model, $message, [ 'type' => 'json_object' ] );
		}

		$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		$in   = (int) ( $data['usage']['prompt_tokens'] ?? 0 );
		$out  = (int) ( $data['usage']['completion_tokens'] ?? 0 );

		return [
			'result'   => $this->first_json( $text ),
			'tokens'   => $in + $out,
			'cost_usd' => $this->cost( self::PRICING, $model, self::DEFAULT_MODEL, $in, $out ),
		];
	}

	/** @param array<string,mixed> $response_format */
	private function chat( string $model, string $message, array $response_format ): array {
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
						'messages'        => [ [ 'role' => 'user', 'content' => $message ] ],
						'response_format' => $response_format,
					]
				),
			]
		);

		return $this->decode( $response );
	}

	/**
	 * Rewrite our schema into the subset OpenAI's strict mode accepts: every object must list
	 * all properties as required and forbid extras, and string/number constraints it rejects
	 * (maxLength and friends) are dropped — length is still guided by the prompt.
	 *
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	private function strict_schema( array $schema ): array {
		unset( $schema['minLength'], $schema['maxLength'], $schema['pattern'], $schema['format'], $schema['minimum'], $schema['maximum'] );

		if ( ( $schema['type'] ?? '' ) === 'object' && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $key => $prop ) {
				$schema['properties'][ $key ] = $this->strict_schema( (array) $prop );
			}
			$schema['required']             = array_keys( $schema['properties'] );
			$schema['additionalProperties'] = false;
		}

		if ( ( $schema['type'] ?? '' ) === 'array' && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = $this->strict_schema( (array) $schema['items'] );
		}

		return $schema;
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
			return $this->degraded_embedding( $text, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || isset( $data['error'] ) ) {
			return $this->degraded_embedding( $text, (string) ( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
		}

		$vector = $data['data'][0]['embedding'] ?? null;
		if ( ! is_array( $vector ) ) {
			return $this->degraded_embedding( $text, 'no vector in response' );
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
