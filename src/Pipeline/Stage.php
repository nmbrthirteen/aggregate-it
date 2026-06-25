<?php

namespace AggregateIt\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * A single pipeline stage. Each stage handles exactly one input state and returns the
 * next state. Throwing signals a retryable failure (the queue backs off / dead-letters).
 * Stages must be idempotent — they may be re-run after a crash mid-claim.
 */
interface Stage {

	public function handles(): string;

	public function process( Item $item ): string;
}
