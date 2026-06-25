<?php

namespace AggregateIt\Queue;

use AggregateIt\Cost\CostMeter;
use AggregateIt\Cost\SpendCap;
use AggregateIt\Pipeline\Item;
use AggregateIt\Pipeline\PaidStage;
use AggregateIt\Pipeline\Pipeline;
use AggregateIt\Settings;
use AggregateIt\Support\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the pipeline. A 1-minute cron is the floor; the "nudge" (a non-blocking
 * admin-ajax self-POST) kicks the worker immediately after work is enqueued. Each run
 * claims a batch and advances each item one stage, within a wall-clock budget so a run
 * never approaches max_execution_time. Mirrors crm-connect's QueueWorker.
 */
final class QueueWorker {

	private const HOOK         = 'aggregate_it_process_queue';
	private const BATCH        = 10;
	private const MAX_ATTEMPTS = 6;
	private const TIME_BUDGET  = 20; // seconds

	public function __construct(
		private ItemStore $items,
		private Pipeline $pipeline,
		private CostMeter $cost,
		private SpendCap $cap,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'cron_tick' ] );
		add_action( 'aggregate_it_dispatch_queue', [ $this, 'nudge' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
		add_action( 'wp_ajax_nopriv_aggregate_it_run', [ $this, 'ajax_run' ] );
		add_action( 'wp_ajax_aggregate_it_run', [ $this, 'ajax_run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'aggregate_it_minute', self::HOOK );
		}
	}

	/**
	 * Background tick (every minute). Runs only when automatic processing is on, and at
	 * most once per the configured interval — the transient gates the cadence without
	 * rescheduling cron, so interval changes take effect immediately.
	 */
	public function cron_tick(): void {
		if ( ! $this->settings->processing_enabled() ) {
			return;
		}
		if ( get_transient( 'aggregate_it_processed_recently' ) ) {
			return;
		}
		set_transient( 'aggregate_it_processed_recently', 1, $this->settings->processing_interval_minutes() * MINUTE_IN_SECONDS );
		$this->run();
	}

	public function add_cron_interval( array $schedules ): array {
		$schedules['aggregate_it_minute'] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Aggregate It)', 'aggregate-it' ),
		];
		return $schedules;
	}

	public function nudge(): void {
		if ( get_transient( 'aggregate_it_nudge_lock' ) ) {
			return;
		}
		set_transient( 'aggregate_it_nudge_lock', 1, 5 );

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'blocking' => false,
				'timeout'  => 0.01,
				'body'     => [ 'action' => 'aggregate_it_run', 'token' => $this->run_token() ],
			]
		);
	}

	public function ajax_run(): void {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! hash_equals( $this->run_token(), $token ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		// "Process now" forces a run; import-triggered nudges respect the auto toggle.
		$forced = (bool) get_transient( 'aggregate_it_force_run' );
		if ( $forced ) {
			delete_transient( 'aggregate_it_force_run' );
		}
		if ( $forced || $this->settings->processing_enabled() ) {
			$this->run();
		}
		wp_die( 'ok', '', [ 'response' => 200 ] );
	}

	private function run_token(): string {
		$token = get_option( 'aggregate_it_run_token' );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_option( 'aggregate_it_run_token', $token, false );
		}
		return (string) $token;
	}

	public function run(): void {
		$deadline = time() + self::TIME_BUDGET;

		do {
			$items = $this->items->claim_due( self::BATCH );
			foreach ( $items as $item ) {
				$this->process( $item );
				if ( time() >= $deadline ) {
					return;
				}
			}
		} while ( $items && time() < $deadline );
	}

	private function process( Item $item ): void {
		$stage = $this->pipeline->stage_for( $item->state );
		if ( $stage === null ) {
			return;
		}

		if ( $stage instanceof PaidStage && $this->cap->exceeded() ) {
			$this->items->defer( $item->id, 15 * MINUTE_IN_SECONDS );
			return;
		}

		$attempts = $item->attempts + 1;

		try {
			$next = $stage->process( $item );
			$this->items->advance( $item->id, $next, $item->flags );
		} catch ( \Throwable $e ) {
			$this->fail( $item, $attempts, $e );
		}
	}

	private function fail( Item $item, int $attempts, \Throwable $e ): void {
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->items->dead_letter( $item->id, $attempts, $e->getMessage() );
			$this->cost->record( $item->state, 0, 0, $item->id, 'error', $e->getMessage() );
			EventLog::error( sprintf( 'Article #%d failed at the %s step: %s', $item->id, $item->state, $e->getMessage() ) );
			do_action( 'aggregate_it_dead_letter', $item, $e );
			return;
		}
		$this->items->retry( $item->id, $attempts, $e->getMessage(), $this->future( $this->backoff( $attempts ) ) );
	}

	private function backoff( int $attempts ): int {
		return (int) min( HOUR_IN_SECONDS, 30 * ( 2 ** ( $attempts - 1 ) ) );
	}

	private function future( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + max( 1, $seconds ) );
	}
}
