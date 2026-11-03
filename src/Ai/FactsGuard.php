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

	/** Common words that are capitalized only because they open a sentence or clause. */
	private const STOPWORDS = [
		'a', 'an', 'the', 'this', 'that', 'these', 'those', 'it', 'its', 'he', 'she', 'they', 'we', 'you', 'his', 'her', 'their', 'our',
		'in', 'on', 'at', 'for', 'with', 'from', 'by', 'to', 'of', 'as', 'after', 'before', 'during', 'while', 'when', 'where',
		'and', 'but', 'or', 'so', 'if', 'then', 'also', 'however', 'meanwhile', 'according', 'there', 'here', 'what', 'who',
		'is', 'are', 'was', 'were', 'will', 'has', 'have', 'had', 'not', 'no', 'yes', 'some', 'many', 'most', 'other', 'one', 'all',
		'today', 'yesterday', 'tomorrow', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
	];

	/** @return string[] normalized salient tokens */
	public function salient( string $text ): array {
		$tokens = [];

		// Numbers, money, percentages, years.
		if ( preg_match_all( '/\$?\d[\d,.]*\%?/', $text, $m ) ) {
			foreach ( $m[0] as $n ) {
				$tokens[] = strtolower( trim( $n, '.,' ) );
			}
		}

		// Capitalized proper nouns (Acme Corp, Tim Cook). A lone capitalized word that opens
		// a sentence is just grammar ("Yesterday", "Shares"), so it is not an entity.
		if ( preg_match_all( '/\b([A-Z][a-zA-Z0-9]+(?:\s+[A-Z][a-zA-Z0-9]+){0,3})\b/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as [ $run, $offset ] ) {
				$words = preg_split( '/\s+/', $run ) ?: [];
				while ( $words && in_array( strtolower( $words[0] ), self::STOPWORDS, true ) ) {
					array_shift( $words );
				}
				if ( ! $words ) {
					continue;
				}
				if ( count( $words ) === 1 && $this->starts_sentence( $text, (int) $offset ) ) {
					continue;
				}
				$tokens[] = strtolower( implode( ' ', $words ) );
			}
		}

		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	/**
	 * Numeric/date tokens present in the rewrite but absent from the source, likely
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

	private function starts_sentence( string $text, int $offset ): bool {
		$before = rtrim( substr( $text, 0, $offset ), " \t\"'\u{201C}\u{2018}(" );
		if ( $before === '' ) {
			return true;
		}
		return strpbrk( substr( $before, -1 ), ".!?:\n" ) !== false;
	}

	/** @return string[] */
	private function numbers( string $text ): array {
		if ( ! preg_match_all( '/\d[\d,.]*\%?/', $text, $m ) ) {
			return [];
		}
		return array_values( array_unique( array_map( static fn ( $n ) => strtolower( trim( $n, '.,' ) ), $m[0] ) ) );
	}
}
