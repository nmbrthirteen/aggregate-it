<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Maps pipeline states to the Stage that advances them. Phase 0 registers no-op
 * passthrough stages so the state machine runs end to end; later phases replace each
 * one with real logic (extraction, embedding, clustering, rewrite, entity linking).
 */
final class Pipeline {

	/** @var array<string,Stage> */
	private array $stages = [];

	public function register( Stage $stage ): void {
		$this->stages[ $stage->handles() ] = $stage;
	}

	public function stage_for( string $state ): ?Stage {
		return $this->stages[ $state ] ?? null;
	}

	public static function default_order(): array {
		return [
			Schema::STATE_FETCHED,
			Schema::STATE_EXTRACTED,
			Schema::STATE_EMBEDDED,
			Schema::STATE_CLUSTERED,
			Schema::STATE_REWRITTEN,
			Schema::STATE_ENTITY_LINKED,
			Schema::STATE_PUBLISHED,
		];
	}

	public static function next_state( string $state ): string {
		$order = self::default_order();
		$idx   = array_search( $state, $order, true );
		if ( $idx === false || $idx + 1 >= count( $order ) ) {
			return Schema::STATE_PUBLISHED;
		}
		return $order[ $idx + 1 ];
	}

	/** Register a passthrough for every non-terminal state without a real stage yet. */
	public function register_passthroughs(): void {
		foreach ( self::default_order() as $state ) {
			if ( in_array( $state, Schema::TERMINAL, true ) ) {
				continue;
			}
			if ( isset( $this->stages[ $state ] ) ) {
				continue;
			}
			$this->register( new PassthroughStage( $state ) );
		}
	}
}
