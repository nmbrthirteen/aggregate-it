<?php

namespace AggregateIt\Tests;

use AggregateIt\Support\Vector;
use PHPUnit\Framework\TestCase;

final class VectorTest extends TestCase {

	public function test_pack_round_trips(): void {
		$vector   = [ 0.5, -1.25, 3.0, 0.0 ];
		$unpacked = Vector::unpack( Vector::pack( $vector ) );

		$this->assertCount( 4, $unpacked );
		foreach ( $vector as $i => $expected ) {
			$this->assertEqualsWithDelta( $expected, $unpacked[ $i ], 1e-6 );
		}
	}

	public function test_identical_vectors_are_maximally_similar(): void {
		$a = [ 1.0, 2.0, 3.0 ];
		$this->assertEqualsWithDelta( 1.0, Vector::cosine( $a, $a ), 1e-6 );
	}

	public function test_orthogonal_vectors_have_zero_similarity(): void {
		$this->assertEqualsWithDelta( 0.0, Vector::cosine( [ 1.0, 0.0 ], [ 0.0, 1.0 ] ), 1e-6 );
	}

	public function test_empty_vector_is_safe(): void {
		$this->assertSame( 0.0, Vector::cosine( [], [ 1.0 ] ) );
	}
}
