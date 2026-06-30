<?php

namespace AggregateIt\Maintenance;

use AggregateIt\Publish\Rules;
use AggregateIt\Source\SourceRepository;
use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Re-applies each source's rules to its scraped posts daily, so time-based conditions (a
 * date moving into the past) update the derived meta without needing a re-scrape.
 */
final class RulesRefresher {

	private const HOOK = 'aggregate_it_rules_refresh';

	public function __construct( private SourceRepository $sources ) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function run(): void {
		$ids = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_ai_rule_values', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);

		$now   = time();
		$rules = [];
		foreach ( (array) $ids as $id ) {
			$values = (array) Json::decode( (string) get_post_meta( $id, '_ai_rule_values', true ), [] );
			$sid    = (int) get_post_meta( $id, '_ai_source_id', true );
			if ( ! $values || ! $sid ) {
				continue;
			}

			if ( ! array_key_exists( $sid, $rules ) ) {
				$source        = $this->sources->get( $sid );
				$rules[ $sid ] = $source ? $source->rules() : [];
			}
			if ( ! $rules[ $sid ] ) {
				continue;
			}

			foreach ( Rules::apply( $values, $rules[ $sid ], $now ) as $key => $value ) {
				update_post_meta( $id, sanitize_key( (string) $key ), $value );
			}
		}
	}
}
