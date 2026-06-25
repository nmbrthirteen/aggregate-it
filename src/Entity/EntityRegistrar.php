<?php

namespace AggregateIt\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a public CPT for each delegation rule's target type, so entity hubs are real,
 * indexable pages. Also exposes the entity post types for the dashboard count.
 */
final class EntityRegistrar {

	public function __construct( private DelegationRules $rules ) {}

	public function register(): void {
		// boot() already runs on `init`, so register immediately rather than re-hooking
		// a priority that has likely passed.
		$this->register_post_types();
		add_filter( 'aggregate_it_entity_post_types', [ $this, 'post_types' ] );
	}

	public function register_post_types(): void {
		foreach ( $this->rules->post_types() as $cpt ) {
			if ( post_type_exists( $cpt ) ) {
				continue;
			}
			$label = ucwords( str_replace( [ '-', '_' ], ' ', $cpt ) );
			register_post_type(
				$cpt,
				[
					'labels'       => [ 'name' => $label, 'singular_name' => $label ],
					'public'       => true,
					'has_archive'  => true,
					'show_in_rest' => true,
					'menu_icon'    => 'dashicons-id',
					'rewrite'      => [ 'slug' => $cpt ],
					'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
				]
			);
		}
	}

	public function post_types( array $types ): array {
		return array_values( array_unique( array_merge( $types, $this->rules->post_types() ) ) );
	}
}
