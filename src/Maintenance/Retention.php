<?php

namespace AggregateIt\Maintenance;

use AggregateIt\Database\Schema;
use AggregateIt\Queue\ItemStore;
use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Daily housekeeping: drop old processed/failed item rows and old log rows so the
 * tables don't grow without bound. Published posts are never touched — only the
 * internal tracking rows behind the Articles list and the cost log.
 */
final class Retention {

	private const HOOK = 'aggregate_it_retention';

	public function __construct(
		private ItemStore $items,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function run(): void {
		$days = $this->settings->retention_days();
		if ( $days <= 0 ) {
			return; // keep forever
		}

		$this->items->purge_old( $days );

		global $wpdb;
		$log    = Schema::table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE created_at < %s", $cutoff ) );
	}
}
