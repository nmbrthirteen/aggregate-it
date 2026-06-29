<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

trait HashEmbedding {

	/**
	 * Hash fallback, but warn (once an hour) that real embeddings are down. Silent fallback
	 * here makes every article look unique, which breaks story de-duplication — so it must
	 * be visible, not swallowed.
	 *
	 * @return array{vector:float[],tokens:int,cost_usd:float}
	 */
	protected function degraded_embedding( string $text, string $reason ): array {
		if ( ! get_transient( 'aggregate_it_embed_degraded' ) ) {
			set_transient( 'aggregate_it_embed_degraded', 1, HOUR_IN_SECONDS );
			\AggregateIt\Support\EventLog::warning(
				sprintf( 'Embeddings are failing (%s). Duplicate detection is degraded — similar articles may publish as separate posts. Check the AI provider API key and plan.', $reason )
			);
		}
		return $this->hash_embedding( $text );
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
}
