<?php

namespace AggregateIt\Ai;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for JSON-mode HTTP providers (OpenAI, Gemini): a schema hint appended
 * to the prompt, tolerant JSON extraction, and a local embedding fallback. Each provider
 * supplies its own endpoints, auth, and request/response shapes.
 */
abstract class BaseHttpProvider implements AiProvider {

	public function __construct( protected Settings $settings ) {}

	/** Instruction appended so JSON-mode models return the exact keys we parse. */
	protected function schema_hint( array $schema ): string {
		$keys     = array_keys( $schema['properties'] ?? [] );
		$required = $schema['required'] ?? [];
		$list     = implode( ', ', $keys );

		return "\n\nRespond with a single JSON object (no markdown, no code fence) containing these keys: "
			. $list . '. Required keys: ' . implode( ', ', $required ) . '.';
	}

	/** Parse the model's text into an object, tolerating code fences and surrounding prose. */
	protected function first_json( string $text ): array {
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?|```$/m', '', $text );

		$decoded = json_decode( trim( (string) $text ), true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/\{.*\}/s', (string) $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		throw new \RuntimeException( 'Provider returned non-JSON structured output.' );
	}

	/** @return array{vector:float[],tokens:int,cost_usd:float} */
	protected function hash_embedding( string $text ): array {
		$dims   = 256;
		$vector = array_fill( 0, $dims, 0.0 );
		foreach ( preg_split( '/\W+/', strtolower( $text ) ) ?: [] as $token ) {
			if ( $token !== '' ) {
				$vector[ abs( crc32( $token ) ) % $dims ] += 1.0;
			}
		}
		return [ 'vector' => $vector, 'tokens' => 0, 'cost_usd' => 0.0 ];
	}

	protected function cost( array $pricing, string $model, string $default, int $in, int $out ): float {
		[ $in_price, $out_price ] = $pricing[ $model ] ?? $pricing[ $default ];
		return ( $in / 1_000_000 ) * $in_price + ( $out / 1_000_000 ) * $out_price;
	}
}
