<?php

namespace AggregateIt\Tests;

use AggregateIt\Cluster\Deduplicator;
use AggregateIt\Settings;
use AggregateIt\Vector\VectorStore;
use PHPUnit\Framework\TestCase;

final class DeduplicatorTest extends TestCase {

	private function scan( array $ids, array $embeddings, float $threshold, int $cursor = 0, ?int $deadline = null ): array {
		$dedup    = new Deduplicator( new Settings(), new VectorStore() );
		$assigned = [];
		$next     = $dedup->scan( $ids, $embeddings, $threshold, $cursor, $deadline ?? PHP_INT_MAX, $assigned );
		return [ $next, $assigned ];
	}

	public function test_similar_stories_group_under_the_oldest_post(): void {
		[ $next, $assigned ] = $this->scan(
			[ 1, 2, 3 ],
			[ 1 => [ 1.0, 0.0 ], 2 => [ 0.99, 0.1 ], 3 => [ 0.0, 1.0 ] ],
			0.72
		);
		$this->assertSame( 3, $next );
		$this->assertSame( [ 2 => 1 ], $assigned );
	}

	public function test_threshold_comes_from_the_caller(): void {
		$embeddings = [ 1 => [ 1.0, 0.0 ], 2 => [ 0.8, 0.6 ] ];
		$this->assertSame( [ 2 => 1 ], $this->scan( [ 1, 2 ], $embeddings, 0.75 )[1] );
		$this->assertSame( [], $this->scan( [ 1, 2 ], $embeddings, 0.9 )[1] );
	}

	public function test_posts_with_different_dimensions_never_match(): void {
		$assigned = $this->scan( [ 1, 2 ], [ 1 => [ 1.0, 0.0 ], 2 => [ 1.0, 0.0, 0.0 ] ], 0.5 )[1];
		$this->assertSame( [], $assigned );
	}

	public function test_posts_without_an_embedding_are_skipped(): void {
		$this->assertSame( [], $this->scan( [ 1, 2 ], [ 1 => [ 1.0, 0.0 ] ], 0.5 )[1] );
	}

	public function test_scan_stops_at_the_deadline_and_reports_where_to_resume(): void {
		[ $next ] = $this->scan( [ 1, 2 ], [ 1 => [ 1.0 ], 2 => [ 1.0 ] ], 0.5, 0, 0 );
		$this->assertSame( 0, $next );
	}
}
