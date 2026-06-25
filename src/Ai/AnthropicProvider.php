<?php

namespace AggregateIt\Ai;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Production AI provider backed by the Claude Messages API (structured outputs) and,
 * optionally, Voyage AI for embeddings. Uses the WordPress HTTP API rather than the
 * Anthropic PHP SDK: the plugin ships as a GitHub zip with no composer step on end-user
 * sites, and bundling Guzzle causes cross-plugin version conflicts.
 *
 * Model defaults to Claude Haiku 4.5 to match the project's cheapest-viable posture; it
 * is a setting and can be switched to Opus 4.8 etc.
 */
final class AnthropicProvider implements AiProvider {

	private const MESSAGES_ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const VERSION           = '2023-06-01';
	private const VOYAGE_ENDPOINT   = 'https://api.voyageai.com/v1/embeddings';

	/** input/output USD per million tokens, by model. */
	private const PRICING = [
		'claude-haiku-4-5'  => [ 1.0, 5.0 ],
		'claude-sonnet-4-6' => [ 3.0, 15.0 ],
		'claude-opus-4-8'   => [ 5.0, 25.0 ],
		'claude-opus-4-7'   => [ 5.0, 25.0 ],
	];

	public function __construct( private Settings $settings ) {}

	public function key(): string {
		return 'anthropic';
	}

	public function structured( string $prompt, array $schema, array $opts = [] ): array {
		$model = $this->settings->ai_model() ?: 'claude-haiku-4-5';

		$response = wp_remote_post(
			self::MESSAGES_ENDPOINT,
			[
				'timeout' => 120,
				'headers' => [
					'x-api-key'         => $this->settings->api_key(),
					'anthropic-version' => self::VERSION,
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'         => $model,
						'max_tokens'    => $opts['max_tokens'] ?? $this->settings->max_output_tokens(),
						'messages'      => [ [ 'role' => 'user', 'content' => $prompt ] ],
						'output_config' => [
							'format' => [
								'type'   => 'json_schema',
								'schema' => $this->sanitize_schema( $schema ),
							],
						],
					]
				),
			]
		);

		$data = $this->decode( $response );

		if ( ( $data['stop_reason'] ?? '' ) === 'refusal' ) {
			throw new \RuntimeException( 'Claude declined the request (refusal).' );
		}

		$text = '';
		foreach ( $data['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text = (string) ( $block['text'] ?? '' );
				break;
			}
		}

		$result = json_decode( $text, true );
		if ( ! is_array( $result ) ) {
			throw new \RuntimeException( 'Claude returned non-JSON structured output.' );
		}

		$in  = (int) ( $data['usage']['input_tokens'] ?? 0 );
		$out = (int) ( $data['usage']['output_tokens'] ?? 0 );

		return [
			'result'   => $result,
			'tokens'   => $in + $out,
			'cost_usd' => $this->cost( $model, $in, $out ),
		];
	}

	public function embed( string $text ): array {
		$voyage_key = $this->settings->voyage_api_key();
		if ( $voyage_key === '' ) {
			return $this->hash_embedding( $text );
		}

		$response = wp_remote_post(
			self::VOYAGE_ENDPOINT,
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $voyage_key,
					'content-type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'input' => [ $text ], 'model' => 'voyage-3.5-lite' ] ),
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

	/** @return array{result:array,tokens:int,cost_usd:float} */
	private function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Claude request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ( $data['type'] ?? '' ) === 'error' ) {
			$message = $data['error']['message'] ?? ( 'HTTP ' . $code );
			throw new \RuntimeException( 'Claude API error: ' . $message );
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Structured outputs reject string/numeric constraints and require
	 * additionalProperties:false on every object. Strip the former, enforce the latter.
	 */
	private function sanitize_schema( array $schema ): array {
		foreach ( [ 'maxLength', 'minLength', 'minimum', 'maximum', 'multipleOf' ] as $unsupported ) {
			unset( $schema[ $unsupported ] );
		}

		if ( ( $schema['type'] ?? '' ) === 'object' ) {
			$schema['additionalProperties'] = false;
			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $name => $property ) {
					if ( is_array( $property ) ) {
						$schema['properties'][ $name ] = $this->sanitize_schema( $property );
					}
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = $this->sanitize_schema( $schema['items'] );
		}

		return $schema;
	}

	private function cost( string $model, int $in, int $out ): float {
		[ $in_price, $out_price ] = self::PRICING[ $model ] ?? self::PRICING['claude-haiku-4-5'];
		return ( $in / 1_000_000 ) * $in_price + ( $out / 1_000_000 ) * $out_price;
	}

	/** @return array{vector:float[],tokens:int,cost_usd:float} */
	private function hash_embedding( string $text ): array {
		$dims   = 256;
		$vector = array_fill( 0, $dims, 0.0 );
		foreach ( preg_split( '/\W+/', strtolower( $text ) ) ?: [] as $token ) {
			if ( $token !== '' ) {
				$vector[ abs( crc32( $token ) ) % $dims ] += 1.0;
			}
		}
		return [ 'vector' => $vector, 'tokens' => 0, 'cost_usd' => 0.0 ];
	}
}
