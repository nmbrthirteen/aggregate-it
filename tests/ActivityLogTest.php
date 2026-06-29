<?php

namespace AggregateIt\Tests;

use AggregateIt\Database\Schema;
use AggregateIt\Support\ActivityLog;
use AggregateIt\Support\EventLog;
use PHPUnit\Framework\TestCase;

final class ActivityLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__db'] = [];
	}

	private function rows(): array {
		return $GLOBALS['__db'][ Schema::table( 'log' ) ] ?? [];
	}

	public function test_record_maps_context_to_columns(): void {
		ActivityLog::record(
			'info',
			'Article #7 extracted.',
			[
				'item_id'    => 7,
				'source_id'  => 3,
				'type'       => 'fetched',
				'from_state' => 'fetched',
				'to_state'   => 'extracted',
				'detail'     => [ 'feed_chars' => 10, 'final_chars' => 200 ],
			]
		);

		$row = $this->rows()[0];
		$this->assertSame( 7, $row['item_id'] );
		$this->assertSame( 3, $row['source_id'] );
		$this->assertSame( 'fetched', $row['stage'] );
		$this->assertSame( 'fetched', $row['from_state'] );
		$this->assertSame( 'extracted', $row['to_state'] );
		$this->assertSame( 'info', $row['level'] );
		$this->assertStringContainsString( '"final_chars":200', $row['detail'] );
	}

	public function test_record_without_context_leaves_nullable_columns_null(): void {
		ActivityLog::record( 'warning', 'Something happened.' );

		$row = $this->rows()[0];
		$this->assertNull( $row['item_id'] );
		$this->assertNull( $row['stage'] );
		$this->assertNull( $row['detail'] );
		$this->assertSame( 'warning', $row['level'] );
	}

	public function test_eventlog_facade_writes_through_to_db(): void {
		EventLog::error( 'Feed died.' );

		$row = $this->rows()[0];
		$this->assertSame( 'error', $row['level'] );
		$this->assertSame( 'Feed died.', $row['message'] );
	}

	public function test_clear_targets_only_zero_cost_rows(): void {
		// The in-memory stub can't execute SQL, so this pins the predicate that keeps cost
		// history intact; the delete behaviour itself is covered by integration testing.
		ActivityLog::clear();
		$this->assertStringContainsString( 'tokens = 0 AND cost_usd = 0', $GLOBALS['wpdb']->last_query );
	}
}
