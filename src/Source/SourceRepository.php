<?php

namespace AggregateIt\Source;

use AggregateIt\Database\Schema;
use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

final class SourceRepository {

	private function table(): string {
		return Schema::table( 'sources' );
	}

	public function create( string $url, string $title, array $settings = [] ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			[
				'url'        => $url,
				'title'      => $title,
				'status'     => 'active',
				'settings'   => Json::encode( $settings ),
				'health'     => Json::encode( [] ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['settings'] ) && is_array( $fields['settings'] ) ) {
			$fields['settings'] = Json::encode( $fields['settings'] );
		}
		$wpdb->update( $this->table(), $fields, [ 'id' => $id ] );
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), [ 'id' => $id ] );
	}

	public function get( int $id ): ?Source {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
		return $row ? Source::from_row( $row ) : null;
	}

	/** @return Source[] */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC" );
		return array_map( [ Source::class, 'from_row' ], $rows ?: [] );
	}

	/**
	 * Active sources whose next import is due (last_checked + interval <= now).
	 *
	 * @return Source[]
	 */
	public function due( int $default_interval, int $limit = 10 ): array {
		$due = [];
		foreach ( $this->all() as $source ) {
			if ( ! $source->is_active() ) {
				continue;
			}
			if ( $this->is_due( $source, $default_interval ) ) {
				$due[] = $source;
			}
			if ( count( $due ) >= $limit ) {
				break;
			}
		}
		return $due;
	}

	private function is_due( Source $source, int $default_interval ): bool {
		if ( $source->last_checked === null ) {
			return true;
		}
		$next = strtotime( $source->last_checked . ' UTC' ) + $source->interval_minutes( $default_interval ) * MINUTE_IN_SECONDS;
		return time() >= $next;
	}

	public function mark_checked( int $id, array $health ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'health'       => Json::encode( $health ),
				'last_checked' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
	}
}
