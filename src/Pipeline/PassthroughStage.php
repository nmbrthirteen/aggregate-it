<?php

namespace AggregateIt\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 0 placeholder: advances an item to the next state without doing work, so the
 * queue and state machine are observable before real stage logic exists.
 */
final class PassthroughStage implements Stage {

	public function __construct( private string $state ) {}

	public function handles(): string {
		return $this->state;
	}

	public function process( Item $item ): string {
		return Pipeline::next_state( $this->state );
	}
}
