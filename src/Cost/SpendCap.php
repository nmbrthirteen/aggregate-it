<?php

namespace AggregateIt\Cost;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hard daily ceiling. When today's spend crosses the cap, paid pipeline stages stop;
 * free stages keep running. Mirrors crm-connect's transient auto-pause.
 */
final class SpendCap {

	private const PAUSE_KEY = 'aggregate_it_spend_paused';

	public function __construct(
		private Settings $settings,
		private CostMeter $meter
	) {}

	public function exceeded(): bool {
		$cap = $this->settings->daily_spend_cap_usd();
		if ( $cap <= 0 ) {
			return false;
		}
		$over = $this->meter->spent_today() >= $cap;
		if ( $over && ! $this->is_paused() ) {
			set_transient( self::PAUSE_KEY, 1, DAY_IN_SECONDS );
			do_action( 'aggregate_it_spend_cap_reached', $cap );
		}
		return $over;
	}

	public function is_paused(): bool {
		return (bool) get_transient( self::PAUSE_KEY );
	}

	public static function resume(): void {
		delete_transient( self::PAUSE_KEY );
	}
}
