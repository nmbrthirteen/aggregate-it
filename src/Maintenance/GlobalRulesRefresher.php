<?php

namespace AggregateIt\Maintenance;

use AggregateIt\Publish\Rules;
use AggregateIt\Settings;
use AggregateIt\Source\SourceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies global per-post-type rules to every post of each configured type, reading the
 * post's own meta as the field values. Runs daily (and on demand after a save) so derived
 * meta like status stays correct as dates pass — for any post type, not just scraped ones.
 */
final class GlobalRulesRefresher {

	private const HOOK  = 'aggregate_it_global_rules';
	private const BATCH = 200;

	/** @var array<int,array<string,bool>> source id => meta keys it governs */
	private array $owned_cache = [];

	public function __construct(
		private Settings $settings,
		private SourceRepository $sources
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function schedule_soon(): void {
		wp_schedule_single_event( time() + 5, self::HOOK );
	}

	public function run(): void {
		$now = time();
		foreach ( $this->settings->global_rules() as $type => $rules ) {
			if ( is_array( $rules ) && $rules ) {
				$this->apply_type( (string) $type, $rules, $now );
			}
		}
	}

	/** @param array<int,array<string,string>> $rules */
	public function apply_type( string $type, array $rules, int $now ): void {
		$needed = $this->referenced_fields( $rules );
		$offset = 0;

		do {
			$ids = get_posts(
				[
					'post_type'      => $type,
					'post_status'    => 'any',
					'posts_per_page' => self::BATCH,
					'offset'         => $offset,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			foreach ( (array) $ids as $id ) {
				$values = $this->values_for( (int) $id, $needed );
				$owned  = $this->source_owned_keys( (int) $id );
				foreach ( Rules::apply( $values, $rules, $now ) as $key => $value ) {
					$key = sanitize_key( (string) $key );
					// A scraped post's per-source rules own these keys; leave them to win.
					if ( isset( $owned[ $key ] ) ) {
						continue;
					}
					update_post_meta( (int) $id, $key, $value );
				}
			}

			$offset += self::BATCH;
		} while ( count( (array) $ids ) === self::BATCH );
	}

	/**
	 * @param array<int,array<string,string>> $rules
	 * @return string[]
	 */
	private function referenced_fields( array $rules ): array {
		$fields = [];
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['field'] ) ) {
				$fields[ (string) $rule['field'] ] = true;
			}
			if ( preg_match_all( '/\{([a-z0-9_]+)/i', (string) ( $rule['set_value'] ?? '' ), $m ) ) {
				foreach ( $m[1] as $f ) {
					$fields[ strtolower( $f ) ] = true;
				}
			}
		}
		return array_keys( $fields );
	}

	/** @return array<string,bool> meta keys governed by the post's per-source scrape rules */
	private function source_owned_keys( int $id ): array {
		$sid = (int) get_post_meta( $id, '_ai_source_id', true );
		if ( ! $sid ) {
			return [];
		}
		if ( ! isset( $this->owned_cache[ $sid ] ) ) {
			$source = $this->sources->get( $sid );
			$keys   = [];
			foreach ( $source ? $source->rules() : [] as $rule ) {
				$key = sanitize_key( (string) ( $rule['set_key'] ?? '' ) );
				if ( $key !== '' ) {
					$keys[ $key ] = true;
				}
			}
			$this->owned_cache[ $sid ] = $keys;
		}
		return $this->owned_cache[ $sid ];
	}

	/**
	 * @param string[] $needed
	 * @return array<string,string>
	 */
	private function values_for( int $id, array $needed ): array {
		$values = [
			'post_title' => (string) get_the_title( $id ),
			'post_date'  => (string) get_post_field( 'post_date', $id ),
		];
		foreach ( $needed as $field ) {
			if ( ! isset( $values[ $field ] ) ) {
				$values[ $field ] = (string) get_post_meta( $id, $field, true );
			}
		}
		return $values;
	}
}
