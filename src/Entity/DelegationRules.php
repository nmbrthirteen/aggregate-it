<?php

namespace AggregateIt\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * The data-driven config that maps an extracted entity type to a custom post type and
 * tells the engine how to match, research, and link it. Adding support for a new post
 * type is adding a rule — no code. Stored as one option.
 */
final class DelegationRules {

	private const OPTION = 'aggregate_it_delegation_rules';

	/** @return array<int,array<string,mixed>> */
	public function all(): array {
		$rules = get_option( self::OPTION, [] );
		return is_array( $rules ) ? array_values( $rules ) : [];
	}

	public function for_type( string $entity_type ): ?array {
		foreach ( $this->all() as $rule ) {
			if ( strtolower( $rule['entity_type'] ?? '' ) === strtolower( $entity_type ) && ! empty( $rule['enabled'] ) ) {
				return $this->hydrate( $rule );
			}
		}
		return null;
	}

	/** @return array<int,string> distinct target CPT slugs across all rules */
	public function post_types(): array {
		$types = [];
		foreach ( $this->all() as $rule ) {
			if ( ! empty( $rule['target_cpt'] ) ) {
				$types[] = (string) $rule['target_cpt'];
			}
		}
		return array_values( array_unique( $types ) );
	}

	public function add( string $entity_type, string $target_cpt, string $schema_type ): void {
		$rules   = $this->all();
		$rules[] = [
			'entity_type' => sanitize_key( $entity_type ),
			'target_cpt'  => sanitize_key( $target_cpt ),
			'schema_type' => sanitize_text_field( $schema_type ),
			'enabled'     => true,
		];
		update_option( self::OPTION, $rules );
	}

	public function remove( int $index ): void {
		$rules = $this->all();
		unset( $rules[ $index ] );
		update_option( self::OPTION, array_values( $rules ) );
	}

	private function hydrate( array $rule ): array {
		return wp_parse_args(
			$rule,
			[
				'schema_type' => 'Thing',
				'match'       => [ 'link_threshold' => 92, 'ambiguous_floor' => 75 ],
				'linking'     => [ 'max_links_per_post' => 5, 'first_mention_only' => true ],
				'research'    => [ 'enabled' => true, 'max_lookups' => 3 ],
			]
		);
	}
}
