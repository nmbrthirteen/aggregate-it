<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

trait HashEmbedding {

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
