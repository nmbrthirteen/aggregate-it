<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Database\Schema;
use AggregateIt\Entity\DelegationRules;
use AggregateIt\Entity\EntityLinker;
use AggregateIt\Entity\EntityRepository;
use AggregateIt\Entity\EntityResearcher;
use AggregateIt\Entity\EntityResolver;
use AggregateIt\Entity\Name;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `entity_linked` -> `published`: resolves each extracted entity against its
 * delegation rule (link existing / create stub / skip ambiguous), then injects
 * first-mention links and records relationships. No-ops cleanly when the post was
 * suppressed or no rules are configured. Free unless a research provider is connected.
 */
final class EntityStage implements Stage {

	public function __construct(
		private DelegationRules $rules,
		private EntityResolver $resolver,
		private EntityResearcher $researcher,
		private EntityLinker $linker,
		private EntityRepository $repo
	) {}

	public function handles(): string {
		return Schema::STATE_ENTITY_LINKED;
	}

	public function process( Item $item ): string {
		$post_id  = $item->post_id;
		$entities = (array) ( $item->flags['entities'] ?? [] );

		if ( ! $post_id ) {
			return Schema::STATE_PUBLISHED;
		}

		if ( ! $entities ) {
			do_action( 'aggregate_it_publish_ping', $post_id );
			return Schema::STATE_PUBLISHED;
		}

		$resolved = [];
		$seen     = [];
		$content  = (string) $item->raw_content;

		foreach ( $entities as $entity ) {
			$name = (string) ( $entity['name'] ?? '' );
			$type = (string) ( $entity['type'] ?? '' );
			$desc = (string) ( $entity['description'] ?? '' );
			$norm = Name::normalize( $name );

			if ( $norm === '' || isset( $seen[ $norm ] ) ) {
				continue;
			}
			$seen[ $norm ] = true;

			$rule = $this->rules->for_type( $type );
			if ( ! $rule ) {
				continue;
			}

			$decision = $this->resolver->resolve( $rule, $name );
			if ( $decision['action'] === 'skip' ) {
				continue;
			}

			if ( $decision['action'] === 'create' ) {
				$entity_id = $this->repo->create( $rule['target_cpt'], $name, $this->researcher->research( $rule, $name, $type, $content, $item->url, $desc ) );
				$this->repo->set_fields( $entity_id, $this->researcher->fields( $rule, $name, $type, $content ) );
			} else {
				$entity_id = (int) $decision['entity_id'];
				// Delegate the news into an existing hub: fill it in if it's still a stub.
				if ( $desc !== '' && $this->repo->is_stub( $entity_id ) ) {
					$this->repo->enrich( $entity_id, $desc );
				}
			}

			$resolved[] = [
				'id'   => $entity_id,
				'name' => $name,
				'url'  => (string) get_permalink( $entity_id ),
				'cap'  => (int) ( $rule['linking']['max_links_per_post'] ?? 5 ),
			];
		}

		if ( $resolved ) {
			$cap = (int) ( $resolved[0]['cap'] ?? 5 );
			$this->linker->link( $post_id, $resolved, $cap );
		}

		do_action( 'aggregate_it_publish_ping', $post_id );

		return Schema::STATE_PUBLISHED;
	}
}
