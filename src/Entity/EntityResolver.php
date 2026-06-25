<?php

namespace AggregateIt\Entity;

use AggregateIt\Support\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Type-constrained, conservative resolution. Exact/alias or high fuzzy similarity links;
 * clearly novel creates; the ambiguous middle is skipped and logged rather than guessed.
 * A clean sparse graph outranks a dense corrupted one, and duplicates are recoverable
 * via the merge tool — wrong links are not.
 */
final class EntityResolver {

	public function __construct( private EntityRepository $repo ) {}

	/**
	 * @param array<string,mixed> $rule
	 * @return array{action:string,entity_id:?int}
	 */
	public function resolve( array $rule, string $name ): array {
		$cpt  = (string) $rule['target_cpt'];
		$norm = Name::normalize( $name );

		if ( $norm === '' ) {
			return [ 'action' => 'skip', 'entity_id' => null ];
		}

		$exact = $this->repo->find_by_name( $cpt, $norm );
		if ( $exact ) {
			return [ 'action' => 'link', 'entity_id' => $exact ];
		}

		$link_floor      = (int) ( $rule['match']['link_threshold'] ?? 92 );
		$ambiguous_floor = (int) ( $rule['match']['ambiguous_floor'] ?? 75 );

		$best_id    = null;
		$best_score = 0.0;
		foreach ( $this->repo->all_of_type( $cpt ) as $entity ) {
			if ( $entity['norm'] === '' ) {
				continue;
			}
			similar_text( $norm, $entity['norm'], $percent );
			if ( $percent > $best_score ) {
				$best_score = $percent;
				$best_id    = $entity['id'];
			}
		}

		if ( $best_id && $best_score >= $link_floor ) {
			$this->repo->add_alias( $best_id, $norm );
			return [ 'action' => 'link', 'entity_id' => $best_id ];
		}

		if ( $best_id && $best_score >= $ambiguous_floor ) {
			EventLog::warning( sprintf( 'Ambiguous entity "%s" (%.0f%% vs #%d) — skipped, needs resolution.', $name, $best_score, $best_id ) );
			return [ 'action' => 'skip', 'entity_id' => null ];
		}

		return [ 'action' => 'create', 'entity_id' => null ];
	}
}
