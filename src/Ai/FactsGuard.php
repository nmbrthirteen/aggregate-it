<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic, AI-free fact handling. Extracts salient tokens (numbers, dates, proper
 * nouns) used for two jobs: flagging invented facts in a rewrite (anything numeric in the
 * output that wasn't in the input), and gating novelty (does new content bring facts the
 * canonical post doesn't already have).
 */
final class FactsGuard {

	/** @return string[] normalized salient tokens */
	public function salient( string $text ): array {
		$tokens = [];

		// Numbers, money, percentages, years.
		if ( preg_match_all( '/\$?\d[\d,.]*\%?/', $text, $m ) ) {
			foreach ( $m[0] as $n ) {
				$tokens[] = strtolower( trim( $n, '.,' ) );
			}
		}

		// Capitalized multi-word proper nouns (Acme Corp, Tim Cook).
		if ( preg_match_all( '/\b([A-Z][a-zA-Z0-9]+(?:\s+[A-Z][a-zA-Z0-9]+){0,3})\b/', $text, $m ) ) {
			foreach ( $m[1] as $name ) {
				$tokens[] = strtolower( $name );
			}
		}

		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	/**
	 * Numeric/date tokens present in the rewrite but absent from the source — likely
	 * fabrications. Proper nouns are excluded (rewrites legitimately rephrase names).
	 *
	 * @return string[]
	 */
	public function invented( string $source, string $rewrite ): array {
		$src_numbers = $this->numbers( $source );
		$out_numbers = $this->numbers( $rewrite );
		return array_values( array_diff( $out_numbers, $src_numbers ) );
	}

	/**
	 * Salient tokens in $new not already covered by $known (novelty).
	 *
	 * @param string[] $known
	 * @return string[]
	 */
	public function novel( string $new, array $known ): array {
		return array_values( array_diff( $this->salient( $new ), $known ) );
	}

	/** @return string[] */
	private function numbers( string $text ): array {
		if ( ! preg_match_all( '/\d[\d,.]*\%?/', $text, $m ) ) {
			return [];
		}
		return array_values( array_unique( array_map( static fn ( $n ) => strtolower( trim( $n, '.,' ) ), $m[0] ) ) );
	}
}
