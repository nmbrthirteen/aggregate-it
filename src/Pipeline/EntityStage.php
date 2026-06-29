<?php

namespace AggregateIt\Pipeline;

use AggregateIt\Database\Schema;
use AggregateIt\Entity\DelegationRules;
use AggregateIt\Entity\EntityLinker;
use AggregateIt\Entity\EntityRepository;
use AggregateIt\Entity\EntityResearcher;
use AggregateIt\Entity\EntityResolver;
use AggregateIt\Entity\Name;
use AggregateIt\Support\ActivityLog;

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
		$created  = [];
		$skipped  = [];
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
				$skipped[] = [ 'name' => $name, 'reason' => $type !== '' ? 'no hub for type ' . $type : 'no type' ];
				continue;
			}

			$decision = $this->resolver->resolve( $rule, $name );
			if ( $decision['action'] === 'skip' ) {
				$skipped[] = [ 'name' => $name, 'reason' => 'ambiguous match' ];
				continue;
			}

			if ( $decision['action'] === 'create' ) {
				$entity_id = $this->repo->create( $rule['target_cpt'], $name, $this->researcher->research( $rule, $name, $type, $content, $item->url, $desc ) );
				$this->repo->set_fields( $entity_id, $this->researcher->fields( $rule, $name, $type, $content ) );
				$created[] = $name;
			} else {
				$entity_id = (int) $decision['entity_id'];
				// Delegate the news into an existing hub: fill it in if it's still a stub.
				if ( $desc !== '' && $this->repo->is_stub( $entity_id ) ) {
					$this->repo->enrich( $entity_id, $desc );
				}
			}

			$this->repo->add_timeline( $entity_id, $post_id, $desc );

			$resolved[] = [
				'id'   => $entity_id,
				'name' => $name,
				'url'  => (string) get_permalink( $entity_id ),
				'cap'  => (int) ( $rule['linking']['max_links_per_post'] ?? 5 ),
			];
		}

		if ( $resolved ) {
			$cap    = (int) ( $resolved[0]['cap'] ?? 5 );
			$linked = $this->linker->link( $post_id, $resolved, $cap );

			ActivityLog::record(
				'info',
				$created
					? sprintf( 'Post #%d: linked %d mention(s) to %d topic hub(s), created %d new: %s.', $post_id, $linked, count( $resolved ), count( $created ), implode( ', ', $created ) )
					: sprintf( 'Post #%d: linked %d mention(s) to %d topic hub(s).', $post_id, $linked, count( $resolved ) ),
				[
					'item_id'   => $item->id,
					'source_id' => $item->source_id,
					'post_id'   => $post_id,
					'type'      => Schema::STATE_ENTITY_LINKED,
					'detail'    => [
						'linked'  => array_map( static fn ( $r ) => $r['name'], $resolved ),
						'created' => $created,
					],
				]
			);
		}

		if ( $skipped ) {
			ActivityLog::record(
				'info',
				sprintf( 'Post #%d: skipped %d topic(s) — %s.', $post_id, count( $skipped ), implode( ', ', array_map( static fn ( $s ) => $s['name'] . ' (' . $s['reason'] . ')', $skipped ) ) ),
				[
					'item_id'   => $item->id,
					'source_id' => $item->source_id,
					'post_id'   => $post_id,
					'type'      => Schema::STATE_ENTITY_LINKED,
					'detail'    => [ 'skipped' => $skipped ],
				]
			);
		}

		do_action( 'aggregate_it_publish_ping', $post_id );

		return Schema::STATE_PUBLISHED;
	}
}
