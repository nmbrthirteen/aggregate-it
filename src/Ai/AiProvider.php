<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

interface AiProvider {

	public function key(): string;

	/**
	 * One structured call: rewrite + SEO fields + entities + facts in a single round
	 * trip. Returns the decoded object validated against $schema.
	 *
	 * @param array<string,mixed> $schema JSON schema the result must satisfy.
	 * @param array<string,mixed> $opts
	 * @return array{result:array<string,mixed>,tokens:int,cost_usd:float}
	 */
	public function structured( string $prompt, array $schema, array $opts = [] ): array;

	/**
	 * @return array{vector:float[],tokens:int,cost_usd:float}
	 */
	public function embed( string $text ): array;
}
